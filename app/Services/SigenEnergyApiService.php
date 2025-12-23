<?php

namespace App\Services;

use App\Enum\SigEnergy\BatteryInstruction;
use App\Enum\SigEnergy\ChargePriority;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

class SigenEnergyApiService
{
    private string $baseUrl;
    private string $appKey;
    private string $appSecret;
    private ?string $mqttServer;
    private ?int $mqttPort;
    private ?string $mqttUsername;
    private ?string $mqttPassword;
    private ?MqttClient $mqttClient;

    private const string TOKEN_CACHE_KEY = 'sigenergy_access_token';

    public function __construct()
    {
    }

    /**
     * Authenticate with Sigenergy API and get access token
     * Uses app key/secret for third-party authentication
     */
    public function authenticate(): ?string
    {
        $this->baseUrl = config('services.sigenergy.base_url', 'https://api-eu.sigencloud.com');
        $this->appKey = config('services.sigenergy.app_key');
        $this->appSecret = config('services.sigenergy.app_secret');

        $this->mqttServer = config('services.sigenergy.mqtt_server');
        $this->mqttPort = config('services.sigenergy.mqtt_port', 1883);
        $this->mqttUsername = config('services.sigenergy.mqtt_username');
        $this->mqttPassword = config('services.sigenergy.mqtt_password');

        if ($token = Cache::get(self::TOKEN_CACHE_KEY)) {
            return $token;
        }

        try {
            $credentials = $this->appKey . ':' . $this->appSecret;
            $base64Key = base64_encode($credentials);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json; charset=UTF-8',
                'Accept' => 'application/json, text/plain, */*',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
                'sigen-region' => 'eu'
            ])->post("{$this->baseUrl}/openapi/auth/login/key", [
                'key' => $base64Key,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                // Check for error code in response
                if (isset($data['code']) && $data['code'] !== 0) {
                    Log::error('Sigenergy authentication failed with error', [
                        'code' => $data['code'],
                        'message' => $data['msg'] ?? 'Unknown error'
                    ]);
                    return null;
                }

                $tokenDataJson = $data['data'] ?? null;

                // Parse the JSON string inside the data field
                $tokenData = null;
                if ($tokenDataJson) {
                    $tokenData = json_decode($tokenDataJson, true);
                }

                $token = $tokenData['accessToken'] ?? null;
                $expiresIn = $tokenData['expiresIn'] ?? null;

                if ($token) {
                    // Cache token for 95% of expiry time (default 12 hours = 43200 seconds)
                    $cacheMinutes = $expiresIn ? ($expiresIn * 0.95) / 60 : 690; // 11.5 hours default
                    Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addMinutes($cacheMinutes));

                    Log::info('Sigenergy authentication successful', [
                        'token_type' => $tokenData['tokenType'] ?? 'unknown',
                        'expires_in' => $expiresIn
                    ]);

                    return $token;
                }
            }

            Log::error('Sigenergy authentication failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

        } catch (\Exception $e) {
            Log::error('Sigenergy authentication error', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Make authenticated request to Sigenergy API
     */
    private function makeRequest(string $method, string $endpoint, array $data = []): ?Response
    {
        $token = $this->authenticate();

        if (!$token) {
            throw new \Exception('Sigenergy authentication failed');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
                'sigen-region' => 'eu'
            ])->$method("{$this->baseUrl}{$endpoint}", $data);

            return $response;

        } catch (\Exception $e) {
            Log::error('Sigenergy API request failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Get battery status
     */
    public function getBatteryStatus(): ?array
    {
        $response = $this->makeRequest('get', '/battery/status');

        return $response?->successful() ? $response->json() : null;
    }


    /**
     * Set solar energy mode (consume vs sell)
     */
    public function setSolarEnergyMode(string $mode): bool
    {
        // Mode: 'consume' or 'sell'
        $response = $this->makeRequest('post', '/solar/mode', [
            'mode' => $mode
        ]);

        return $response?->successful() ?? false;
    }

    /**
     * Get energy consumption and production data
     */
    public function getEnergyData(): ?array
    {
        $response = $this->makeRequest('get', '/energy/data');

        return $response?->successful() ? $response->json() : null;
    }

    /**
     * Get system list with optional filtering by grid connection time
     * Rate limit: Can only be accessed every 5 minutes per account
     */
    public function getSystemList(?int $startTime = null, ?int $endTime = null): ?array
    {
        $params = [];
        if ($startTime !== null) {
            $params['startTime'] = $startTime;
        }
        if ($endTime !== null) {
            $params['endTime'] = $endTime;
        }

        $endpoint = '/openapi/system';
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }

        $response = $this->makeRequest('get', $endpoint);

        if ($response?->successful()) {
            $data = $response->json();

            // Check for error code in response
            if (isset($data['code']) && $data['code'] !== 0) {
                Log::error('Sigenergy system list failed', [
                    'code' => $data['code'],
                    'message' => $data['msg'] ?? 'Unknown error'
                ]);
                return null;
            }

            $dataJson = $data['data'] ?? null;

            // Parse the JSON string inside the data field if it's a string
            if (is_string($dataJson)) {
                return json_decode($dataJson, true);
            }

            return $dataJson;
        }

        return null;
    }

    /**
     * Get system overview (alias for getSystemList for backwards compatibility)
     */
    public function getSystemOverview(): ?array
    {
        return $this->getSystemList();
    }

    /**
     * Get device list for a specific system
     * Rate limit: Can only access one station's device list once every 5 minutes per account
     */
    public function getDeviceList(string $systemId): ?array
    {
        $response = $this->makeRequest('get', "/openapi/system/{$systemId}/devices");

        if ($response?->successful()) {
            $data = $response->json();

            // Check for error code in response
            if (isset($data['code']) && $data['code'] !== 0) {
                Log::error('Sigenergy device list failed', [
                    'system_id' => $systemId,
                    'code' => $data['code'],
                    'message' => $data['msg'] ?? 'Unknown error'
                ]);
                return null;
            }

            // Handle nested JSON string in data field (similar to auth and energy flow responses)
            $deviceData = $data['data'] ?? null;
            if (is_string($deviceData)) {
                $deviceData = json_decode($deviceData, true);
            }

            // Device data comes as array of JSON strings - need to decode each device
            if (is_array($deviceData)) {
                $decodedDevices = [];
                foreach ($deviceData as $deviceJson) {
                    if (is_string($deviceJson)) {
                        $device = json_decode($deviceJson, true);

                        // Handle nested attrMap which is also a JSON string
                        if (isset($device['attrMap']) && is_string($device['attrMap'])) {
                            $device['attrMap'] = json_decode($device['attrMap'], true);
                        }

                        $decodedDevices[] = $device;
                    } else {
                        // Already decoded device object
                        $decodedDevices[] = $deviceJson;
                    }
                }
                return $decodedDevices;
            }

            return $deviceData;
        }

        return null;
    }

    /**
     * Get specific device by system ID and serial number
     */
    public function getDevice(string $systemId, string $serialNumber): ?array
    {
        $devices = $this->getDeviceList($systemId);

        if ($devices === null) {
            return null;
        }

        foreach ($devices as $device) {
            if ($device['serialNumber'] === $serialNumber) {
                return $device;
            }
        }

        return null;
    }

    /**
     * Get battery devices for a system
     */
    public function getBatteryDevices(string $systemId): ?array
    {
        $devices = $this->getDeviceList($systemId);

        if ($devices === null) {
            return null;
        }

        $batteryDevices = [];
        foreach ($devices as $device) {
            if (isset($device['deviceType']) &&
                (stripos($device['deviceType'], 'battery') !== false ||
                 stripos($device['deviceType'], 'ess') !== false)) {
                $batteryDevices[] = $device;
            }
        }

        return $batteryDevices;
    }

    /**
     * Get inverter devices for a system
     */
    public function getInverterDevices(string $systemId): ?array
    {
        $devices = $this->getDeviceList($systemId);

        if ($devices === null) {
            return null;
        }

        $inverterDevices = [];
        foreach ($devices as $device) {
            if (isset($device['deviceType']) &&
                stripos($device['deviceType'], 'inverter') !== false) {
                $inverterDevices[] = $device;
            }
        }

        return $inverterDevices;
    }

    /**
     * Get system real-time summary data
     * Rate limit: Can only access one station once every 5 minutes per account
     */
    public function getSystemRealtimeData(string $systemId): ?array
    {
        $response = $this->makeRequest('get', "/openapi/systems/{$systemId}/summary");

        if ($response?->successful()) {
            $data = $response->json();

            // Check for error code in response
            if (isset($data['code']) && $data['code'] !== 0) {
                Log::error('Sigenergy realtime data failed', [
                    'system_id' => $systemId,
                    'code' => $data['code'],
                    'message' => $data['msg'] ?? 'Unknown error'
                ]);
                return null;
            }

            return $data['data'] ?? null;
        }

        return null;
    }

    /**
     * Get daily power generation for system
     */
    public function getDailyPowerGeneration(string $systemId): ?float
    {
        $data = $this->getSystemRealtimeData($systemId);

        return $data['dailyPowerGeneration'] ?? null;
    }

    /**
     * Get current month power generation for system
     */
    public function getMonthlyPowerGeneration(string $systemId): ?float
    {
        $data = $this->getSystemRealtimeData($systemId);

        return $data['monthlyPowerGeneration'] ?? null;
    }

    /**
     * Get annual power generation for system
     */
    public function getAnnualPowerGeneration(string $systemId): ?float
    {
        $data = $this->getSystemRealtimeData($systemId);

        return $data['annualPowerGeneration'] ?? null;
    }

    /**
     * Get lifetime power generation for system
     */
    public function getLifetimePowerGeneration(string $systemId): ?float
    {
        $data = $this->getSystemRealtimeData($systemId);

        return $data['lifetimePowerGeneration'] ?? null;
    }

    /**
     * Get system energy flow data (real-time power flows and battery SOC)
     * Rate limit: Can only access one device in a station once every 5 minutes per account
     */
    public function getSystemEnergyFlow(string $systemId): ?array
    {
        $response = $this->makeRequest('get', "/openapi/systems/{$systemId}/energyFlow");

        if ($response?->successful()) {
            $data = $response->json();

            // Check for error code in response
            if (isset($data['code']) && $data['code'] !== 0) {
                Log::error('Sigenergy energy flow failed', [
                    'system_id' => $systemId,
                    'code' => $data['code'],
                    'message' => $data['msg'] ?? 'Unknown error'
                ]);
                return null;
            }

            // Handle nested JSON string in data field (similar to auth response)
            $energyFlowData = $data['data'] ?? null;
            if (is_string($energyFlowData)) {
                $energyFlowData = json_decode($energyFlowData, true);
            }

            return $energyFlowData;
        }

        return null;
    }

    /**
     * Get current PV power generation
     */
    public function getPvPower(string $systemId): ?float
    {
        $data = $this->getSystemEnergyFlow($systemId);

        return $data['pvPower'] ?? null;
    }

    /**
     * Get current grid power (positive = importing, negative = exporting)
     */
    public function getGridPower(string $systemId): ?float
    {
        $data = $this->getSystemEnergyFlow($systemId);

        return $data['gridPower'] ?? null;
    }

    /**
     * Get current load power consumption
     */
    public function getLoadPower(string $systemId): ?float
    {
        $data = $this->getSystemEnergyFlow($systemId);

        return $data['loadPower'] ?? null;
    }

    /**
     * Get current battery power (positive = charging, negative = discharging)
     */
    public function getBatteryPower(string $systemId): ?float
    {
        $data = $this->getSystemEnergyFlow($systemId);

        return $data['batteryPower'] ?? null;
    }

    /**
     * Get current battery state of charge (SOC) percentage
     */
    public function getBatterySoc(string $systemId): ?float
    {
        $data = $this->getSystemEnergyFlow($systemId);

        return $data['batterySoc'] ?? null;
    }

    /**
     * Check if system is currently exporting energy to grid
     */
    public function isExportingToGrid(string $systemId): ?bool
    {
        $gridPower = $this->getGridPower($systemId);

        return $gridPower !== null ? $gridPower < 0 : null;
    }

    /**
     * Check if system is currently importing energy from grid
     */
    public function isImportingFromGrid(string $systemId): ?bool
    {
        $gridPower = $this->getGridPower($systemId);

        return $gridPower !== null ? $gridPower > 0 : null;
    }

    /**
     * Check if battery is currently charging
     */
    public function isBatteryCharging(string $systemId): ?bool
    {
        $batteryPower = $this->getBatteryPower($systemId);

        return $batteryPower !== null ? $batteryPower > 0 : null;
    }

    /**
     * Check if battery is currently discharging
     */
    public function isBatteryDischarging(string $systemId): ?bool
    {
        $batteryPower = $this->getBatteryPower($systemId);

        return $batteryPower !== null ? $batteryPower < 0 : null;
    }

    /**
     * Get device real-time data for a specific device
     * Rate limit: Can only access one device in a station once every 5 minutes per account
     */
    public function getDeviceRealtimeData(string $systemId, string $serialNumber): ?array
    {
        $response = $this->makeRequest('get', "/openapi/systems/{$systemId}/devices/{$serialNumber}/realtimeInfo");

        if ($response?->successful()) {
            $data = $response->json();

            // Check for error code in response
            if (isset($data['code']) && $data['code'] !== 0) {
                Log::error('Sigenergy device realtime data failed', [
                    'system_id' => $systemId,
                    'serial_number' => $serialNumber,
                    'code' => $data['code'],
                    'message' => $data['msg'] ?? 'Unknown error'
                ]);
                return null;
            }

            return $data['data'] ?? null;
        }

        return null;
    }

    /**
     * Get AIO (All-in-One) inverter real-time data
     */
    public function getAioRealtimeData(string $systemId, string $serialNumber): ?array
    {
        $deviceData = $this->getDeviceRealtimeData($systemId, $serialNumber);

        if ($deviceData === null || $deviceData['deviceType'] !== 'AIO') {
            return null;
        }

        return $deviceData['realTimeInfo'] ?? null;
    }

    /**
     * Get battery real-time data from AIO device
     */
    public function getBatteryRealtimeFromAio(string $systemId, string $serialNumber): ?array
    {
        $aioData = $this->getAioRealtimeData($systemId, $serialNumber);
var_dump('aioData',$aioData);
        if ($aioData === null) {
            return null;
        }

        return [
            'batPower' => $aioData['batPower'] ?? null,
            'batSoc' => $aioData['batSoc'] ?? null,
            'esDischargingDay' => $aioData['esDischargingDay'] ?? null,
            'esChargingDay' => $aioData['esChargingDay'] ?? null,
            'esDischargingTotal' => $aioData['esDischargingTotal'] ?? null,
        ];
    }

    /**
     * Get system historical data for analysis and optimization
     * Rate limit: One account can only access one station once every 5 minutes
     */
    public function getSystemHistoricalData(
        string $systemId,
        string $level,
        ?string $date = null
    ): ?array {
        $params = ['level' => $level];
        if ($date !== null) {
            $params['date'] = $date;
        }

        $endpoint = "/openapi/systems/{$systemId}/history?" . http_build_query($params);
        $response = $this->makeRequest('get', $endpoint);

        if ($response?->successful()) {
            $data = $response->json();

            // Check for error code in response
            if (isset($data['code']) && $data['code'] !== 0) {
                Log::error('Sigenergy historical data failed', [
                    'system_id' => $systemId,
                    'level' => $level,
                    'date' => $date,
                    'code' => $data['code'],
                    'message' => $data['msg'] ?? 'Unknown error'
                ]);
                return null;
            }

            return $data['data'] ?? null;
        }

        return null;
    }

    /**
     * Get daily historical data for specific date
     */
    public function getDailyHistory(string $systemId, string $date): ?array
    {
        return $this->getSystemHistoricalData($systemId, 'Day', $date);
    }



    /**
     * Get device historical data (device-specific history)
     * Rate limit: One account can only access one device once every 5 minutes
     */
    public function getDeviceHistoricalData(
        string $systemId,
        string $serialNumber,
        string $level,
        ?string $date = null
    ): ?array {
        $params = [
            'systemId' => $systemId,
            'serialNumber' => $serialNumber,
            'level' => $level
        ];

        if ($date !== null) {
            $params['date'] = $date;
        }

        $endpoint = "/openapi/systems/{$systemId}/devices/{$serialNumber}/history?" . http_build_query($params);
        $response = $this->makeRequest('get', $endpoint);

        if ($response?->successful()) {
            $data = $response->json();

            // Check for error code in response
            if (isset($data['code']) && $data['code'] !== 0) {
                Log::error('Sigenergy device historical data failed', [
                    'system_id' => $systemId,
                    'serial_number' => $serialNumber,
                    'level' => $level,
                    'date' => $date,
                    'code' => $data['code'],
                    'message' => $data['msg'] ?? 'Unknown error'
                ]);
                return null;
            }

            return $data['data'] ?? null;
        }

        return null;
    }

    /**
     * Get battery historical data for specific device
     */
    public function getBatteryHistoricalData(
        string $systemId,
        string $serialNumber,
        string $level,
        ?string $date = null
    ): ?array {
        return $this->getDeviceHistoricalData($systemId, $serialNumber, $level, $date);
    }


    // ========================================
    // MQTT COMMAND FUNCTIONALITY
    // ========================================

    /**
     * Create MQTT connection for Sigenergy cloud
     * Uses MQTT over SSL/TLS (port 8883) with app key as client ID
     */
    private function getMqttClient(): ?MqttClient
    {
        if (!$this->mqttServer || !$this->appKey) {
            Log::error('MQTT connection parameters not configured', [
                'server' => $this->mqttServer,
                'app_key' => $this->appKey ? 'set' : 'missing'
            ]);
            return null;
        }

        if (isset($this->mqttClient)) {
            return $this->mqttClient;
        }

        try {
            // Configure SSL/TLS connection settings
            $certPath = base_path('cert');
            $connectionSettings = (new ConnectionSettings())
                ->setUsername($this->mqttUsername ?? $this->appKey)
                ->setPassword($this->mqttPassword ?? '')
                ->setUseTls(true)
                ->setTlsVerifyPeer(true)
                ->setTlsVerifyPeerName(true)
                ->setTlsCertificateAuthorityFile($certPath . '/ca.pem')
                ->setTlsClientCertificateFile($certPath . '/client.pem')
                ->setTlsClientCertificateKeyFile($certPath . '/client.key');

            // Create MQTT client with SSL/TLS
            $this->mqttClient = new MqttClient(
                $this->mqttServer,
                $this->mqttPort,
                $this->appKey,
                logger: Log::getLogger()
            );

            // Connect with configured settings
            $this->mqttClient->connect($connectionSettings, true);

            Log::info('Connected to Sigenergy MQTT broker', [
                'server' => $this->mqttServer,
                'port' => $this->mqttPort,
                'client_id' => $this->appKey
            ]);

        } catch (\Exception $e) {
            Log::error('MQTT connection error', [
                'error' => $e->getMessage(),
                'server' => $this->mqttServer,
                'port' => $this->mqttPort
            ]);
            return null;
        }
        return $this->mqttClient;
    }

    /**
     * Send battery command via MQTT (replaces HTTP POST method)
     * Service providers send instructions to specified topics via MQTT
     */
    public function sendBatteryCommandMqtt(
        string $systemId,
        BatteryInstruction $activeMode,
        int $startTime,
        array $additionalParams = []
    ): array {
        $response = [
            'success' => false,
            'action' => "sendBatteryCommand_" . $activeMode->name . "_mqtt",
            'system_id' => $systemId,
            'active_mode' => $activeMode->value,
            'error' => null,
            'mqtt_status' => null,
            'topic' => null,
            'payload_size' => 0,
            'connection_time_ms' => 0,
            'publish_time_ms' => 0
        ];

        $startTime_ms = microtime(true) * 1000;

        $mqtt = $this->getMqttClient();

        if (!$mqtt) {
            $response['error'] = 'Cannot send MQTT command - connection failed';
            $response['mqtt_status'] = 'connection_failed';
            Log::error('Cannot send MQTT command - connection failed');
            return $response;
        }

        $response['connection_time_ms'] = round((microtime(true) * 1000) - $startTime_ms, 2);

        try {
            // Build command payload (max 256KB per MQTT spec)
            $command = array_merge([
                'systemId' => $systemId,
                'activeMode' => $activeMode->value,
                'startTime' => $startTime
            ], $additionalParams);

            $payload = json_encode([
                'accessToken' => $this->authenticate(),
                'commands' => [$command]
            ]);
            $response['payload_size'] = strlen($payload);

            // Check 256KB limit
            if (strlen($payload) > 262144) { // 256KB
                $response['error'] = 'MQTT command payload too large: ' . strlen($payload) . ' bytes (limit: 262144)';
                $response['mqtt_status'] = 'payload_too_large';
                Log::error('MQTT command payload too large', [
                    'size' => strlen($payload),
                    'limit' => 262144
                ]);
                return $response;
            }

            // Send to battery control topic (using actual Sigenergy format)
            $topic = "openapi/instruction/command/{$this->appKey}/{$systemId}";
            $topic = "openapi/instruction/command";
            $response['topic'] = $topic;
            $response['payload'] = $payload;

            $publishStart = microtime(true) * 1000;
            $mqtt->publish($topic, $payload, MqttClient::QOS_AT_LEAST_ONCE);
            $response['publish_time_ms'] = round((microtime(true) * 1000) - $publishStart, 2);

            $mqtt->disconnect();

            $response['success'] = true;
            $response['mqtt_status'] = 'published_successfully';

            Log::info('MQTT battery command sent successfully', [
                'system_id' => $systemId,
                'active_mode' => $activeMode,
                'topic' => $topic,
                'payload_size' => strlen($payload)
            ]);
            return $response;

        } catch (\Exception $e) {
            $response['error'] = $e->getMessage();
            $response['mqtt_status'] = 'publish_exception';

            Log::error('MQTT command send error', [
                'error' => $e->getMessage(),
                'system_id' => $systemId,
                'active_mode' => $activeMode
            ]);

            if (isset($mqtt)) {
                try {
                    $mqtt->disconnect();
                } catch (\Exception $disconnectError) {
                    // Ignore disconnect errors
                }
            }

            return $response;
        }
    }

    /**
     * Send batch battery commands via MQTT (up to 24 commands)
     */
    public function sendBatchBatteryCommandsMqtt(array $commands): bool
    {
        if (count($commands) > 24) {
            Log::error('Too many commands in batch', ['count' => count($commands)]);
            return false;
        }

        $mqtt = $this->getMqttClient();

        if (!$mqtt) {
            return false;
        }

        try {
            $payload = json_encode([
                'commands' => $commands,
                'timestamp' => time(),
                'batch_id' => uniqid()
            ]);

            // Check 256KB limit
            if (strlen($payload) > 262144) {
                Log::error('MQTT batch payload too large', [
                    'size' => strlen($payload),
                    'limit' => 262144,
                    'command_count' => count($commands)
                ]);
                return false;
            }

            // Send to batch command topic (using actual Sigenergy format)
            $systemId = $commands[0]['systemId'] ?? 'unknown';
            $topic = "openapi/command/{$this->appKey}/{$systemId}";

            $mqtt->publish($topic, $payload, 1); // QoS 1
            $mqtt->disconnect();

            Log::info('MQTT batch commands sent successfully', [
                'command_count' => count($commands),
                'topic' => $topic,
                'payload_size' => strlen($payload)
            ]);
            return true;

        } catch (\Exception $e) {
            Log::error('MQTT batch command error', [
                'error' => $e->getMessage(),
                'command_count' => count($commands)
            ]);

            if (isset($mqtt)) {
                try {
                    $mqtt->disconnect();
                } catch (\Exception $disconnectError) {
                    // Ignore disconnect errors
                }
            }

            return false;
        }
    }

    /**
     * Subscribe to MQTT data topics for real-time monitoring
     * Subscribe to real-time operation data, status data, and alarm topics
     */
    public function subscribeToMqttData(string $systemId, ?callable $onMessage = null): bool
    {
        $mqtt = $this->getMqttClient();

        if (!$mqtt) {
            return false;
        }

        try {
            // Subscribe to different data types (using actual Sigenergy topics)
            $topics = [
                "openapi/period/{$this->appKey}/{$systemId}" => 1,    // Telemetry data (periodic push)
                "openapi/change/{$this->appKey}/{$systemId}" => 1,    // System status changes
                "openapi/alarm/{$this->appKey}/{$systemId}" => 2,     // Critical alarms (QoS 2)
            ];

            foreach ($topics as $topic => $qos) {
                $mqtt->subscribe($topic, function($topic, $message) use ($onMessage) {
                    if ($onMessage) {
                        $onMessage($topic, $message);
                    } else {
                        Log::info('MQTT message received', [
                            'topic' => $topic,
                            'message' => $message
                        ]);
                    }
                }, $qos);
                Log::info('Subscribed to MQTT topic', ['topic' => $topic, 'qos' => $qos]);
            }

            // Keep connection alive for real-time data (in production, you might want to run this in background)
            // For testing, we'll disconnect immediately
            $mqtt->disconnect();
            return true;

        } catch (\Exception $e) {
            Log::error('MQTT subscription error', [
                'error' => $e->getMessage(),
                'system_id' => $systemId
            ]);

            if (isset($mqtt)) {
                try {
                    $mqtt->disconnect();
                } catch (\Exception $disconnectError) {
                    // Ignore disconnect errors
                }
            }

            return false;
        }
    }

    /**
     * Force charge battery via MQTT
     */
    public function forceChargeBatteryMqtt(
        string  $systemId,
        int     $startTime,
        ?float  $chargingPower = null,
        ?int    $durationMinutes = null,
        ?float  $pvPower = null,
        ?ChargePriority $chargePriorityType = ChargePriority::GRID
    ): array {
        $params = [];
        if ($chargingPower !== null) {
            $params['chargingPower'] = $chargingPower;
        }
        if ($durationMinutes !== null) {
            $params['duration'] = $durationMinutes;
        }
        /*if ($pvPower !== null) {
            $params['pvPower'] = $pvPower;
        }*/
        $params['chargePriorityType'] = $chargePriorityType;

        return $this->sendBatteryCommandMqtt(
            $systemId,
            BatteryInstruction::CHARGE,
            $startTime,
            $params
        );
    }

    /**
     * Force discharge battery via MQTT
     */
    public function forceDischargeBatteryMqtt(
        string $systemId,
        int $startTime,
        ?float $dischargingPower = null,
        ?int $durationMinutes = null
    ): array {
        $params = [];
        if ($dischargingPower !== null) {
            $params['dischargingPower'] = $dischargingPower;
        }
        if ($durationMinutes !== null) {
            $params['duration'] = $durationMinutes;
        }

        return $this->sendBatteryCommandMqtt($systemId, BatteryInstruction::DISCHARGE, $startTime, $params);
    }

    /**
     * Set battery idle via MQTT
     */
    public function setBatteryIdleMqtt(string $systemId, int $startTime, ?int $durationMinutes = null): array
    {
        $params = [];
        if ($durationMinutes !== null) {
            $params['duration'] = $durationMinutes;
        }

        return $this->sendBatteryCommandMqtt($systemId, BatteryInstruction::IDLE, $startTime, $params);
    }

}
