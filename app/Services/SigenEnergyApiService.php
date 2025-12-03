<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SigenEnergyApiService
{
    private string $baseUrl;
    private string $username;
    private string $password;

    public function __construct()
    {
        $this->baseUrl = config('services.sigenergy.base_url', 'https://api.sigencloud.com');
        $this->username = config('services.sigenergy.username');
        $this->password = config('services.sigenergy.password');
    }

    /**
     * Authenticate with Sigenergy API and get access token
     * Uses username/password login to get token with 12-hour expiry
     */
    public function authenticate(): ?string
    {
        $cacheKey = 'sigenergy_access_token';
        
        // Check if we have a cached valid token
        if ($token = Cache::get($cacheKey)) {
            return $token;
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json; charset=UTF-8',
                'Accept' => 'application/json, text/plain, */*',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
                'sigen-region' => 'eu'
            ])->post("{$this->baseUrl}/openapi/auth/login/password", [
                'username' => $this->username,
                'password' => $this->password,
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
                    Cache::put($cacheKey, $token, now()->addMinutes($cacheMinutes));
                    
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
            return null;
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
     * Set battery charge mode
     */
    public function setBatteryChargeMode(bool $charge = true): bool
    {
        $response = $this->makeRequest('post', '/battery/charge', [
            'mode' => $charge ? 'charge' : 'discharge'
        ]);

        return $response?->successful() ?? false;
    }

    /**
     * Set battery discharge mode
     */
    public function setBatteryDischargeMode(): bool
    {
        return $this->setBatteryChargeMode(false);
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
    public function getSystemList(int $startTime = null, int $endTime = null): ?array
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
     * Get PV real-time data from AIO device
     */
    public function getPvRealtimeFromAio(string $systemId, string $serialNumber): ?array
    {
        $aioData = $this->getAioRealtimeData($systemId, $serialNumber);
        
        if ($aioData === null) {
            return null;
        }
        
        return [
            'pvPower' => $aioData['pvPower'] ?? null,
            'pvTotalPower' => $aioData['pvTotalPower'] ?? null,
            'pvEnergyDaily' => $aioData['pvEnergyDaily'] ?? null,
            'pvEnergyTotal' => $aioData['pvEnergyTotal'] ?? null,
            'pvPowerDay' => $aioData['pvPowerDay'] ?? null,
            'pv1Voltage' => $aioData['pv1Voltage'] ?? null,
            'pv1Current' => $aioData['pv1Current'] ?? null,
            'pv2Voltage' => $aioData['pv2Voltage'] ?? null,
            'pv2Current' => $aioData['pv2Current'] ?? null,
            'pv3Voltage' => $aioData['pv3Voltage'] ?? null,
            'pv3Current' => $aioData['pv3Current'] ?? null,
            'pv4Voltage' => $aioData['pv4Voltage'] ?? null,
            'pv4Current' => $aioData['pv4Current'] ?? null,
        ];
    }

    /**
     * Get grid real-time data from AIO device  
     */
    public function getGridRealtimeFromAio(string $systemId, string $serialNumber): ?array
    {
        $aioData = $this->getAioRealtimeData($systemId, $serialNumber);
        
        if ($aioData === null) {
            return null;
        }
        
        return [
            'activePower' => $aioData['activePower'] ?? null,
            'reactivePower' => $aioData['reactivePower'] ?? null,
            'powerFactor' => $aioData['powerFactor'] ?? null,
            'gridFrequency' => $aioData['gridFrequency'] ?? null,
            'aPhaseVoltage' => $aioData['aPhaseVoltage'] ?? null,
            'bPhaseVoltage' => $aioData['bPhaseVoltage'] ?? null,
            'cPhaseVoltage' => $aioData['cPhaseVoltage'] ?? null,
            'aPhaseCurrent' => $aioData['aPhaseCurrent'] ?? null,
            'bPhaseCurrent' => $aioData['bPhaseCurrent'] ?? null,
            'cPhaseCurrent' => $aioData['cPhaseCurrent'] ?? null,
        ];
    }

    /**
     * Get device temperature and diagnostics from AIO
     */
    public function getAioDiagnostics(string $systemId, string $serialNumber): ?array
    {
        $aioData = $this->getAioRealtimeData($systemId, $serialNumber);
        
        if ($aioData === null) {
            return null;
        }
        
        return [
            'internalTemperature' => $aioData['internalTemperature'] ?? null,
            'insulationResistance' => $aioData['insulationResistance'] ?? null,
        ];
    }

    /**
     * Get gateway real-time data
     */
    public function getGatewayRealtimeData(string $systemId, string $serialNumber): ?array
    {
        $deviceData = $this->getDeviceRealtimeData($systemId, $serialNumber);
        
        if ($deviceData === null || $deviceData['deviceType'] !== 'Gateway') {
            return null;
        }
        
        return $deviceData['realTimeInfo'] ?? null;
    }

    /**
     * Get meter real-time data
     */
    public function getMeterRealtimeData(string $systemId, string $serialNumber): ?array
    {
        $deviceData = $this->getDeviceRealtimeData($systemId, $serialNumber);
        
        if ($deviceData === null || $deviceData['deviceType'] !== 'Meter') {
            return null;
        }
        
        return $deviceData['realTimeInfo'] ?? null;
    }

    /**
     * Get current energy storage operation mode for a system
     * Rate limit: Each account can access a single power station only once every 5 minutes
     */
    public function getEnergyStorageOperationMode(string $systemId): ?int
    {
        $response = $this->makeRequest('get', "/openapi/instruction/{$systemId}/settings");
        
        if ($response?->successful()) {
            $data = $response->json();
            
            // Check for error code in response
            if (isset($data['code']) && $data['code'] !== 0) {
                Log::error('Sigenergy energy storage mode query failed', [
                    'system_id' => $systemId,
                    'code' => $data['code'],
                    'message' => $data['msg'] ?? 'Unknown error'
                ]);
                return null;
            }
            
            return $data['data']['energyStorageOperationMode'] ?? null;
        }
        
        return null;
    }

    /**
     * Check if energy storage is in automatic mode (mode 0)
     */
    public function isEnergyStorageAutoMode(string $systemId): ?bool
    {
        $mode = $this->getEnergyStorageOperationMode($systemId);
        
        return $mode !== null ? $mode === 0 : null;
    }

    /**
     * Check if energy storage is in forced charge mode
     */
    public function isEnergyStorageForcedChargeMode(string $systemId): ?bool
    {
        $mode = $this->getEnergyStorageOperationMode($systemId);
        
        // Assuming mode 1 = forced charge (need enum documentation)
        return $mode !== null ? $mode === 1 : null;
    }

    /**
     * Check if energy storage is in forced discharge mode
     */
    public function isEnergyStorageForcedDischargeMode(string $systemId): ?bool
    {
        $mode = $this->getEnergyStorageOperationMode($systemId);
        
        // Assuming mode 2 = forced discharge (need enum documentation)
        return $mode !== null ? $mode === 2 : null;
    }

    /**
     * Set energy storage operation mode for a system
     * Only available in Northbound mode, requires authorization
     */
    public function setEnergyStorageOperationMode(string $systemId, int $energyStorageOperationMode): bool
    {
        $response = $this->makeRequest('put', '/openapi/instruction/settings', [
            'systemId' => $systemId,
            'energyStorageOperationMode' => $energyStorageOperationMode,
        ]);

        if ($response?->successful()) {
            $data = $response->json();
            
            // Check for error code in response
            if (isset($data['code']) && $data['code'] !== 0) {
                Log::error('Sigenergy energy storage mode set failed', [
                    'system_id' => $systemId,
                    'mode' => $energyStorageOperationMode,
                    'code' => $data['code'],
                    'message' => $data['msg'] ?? 'Unknown error'
                ]);
                return false;
            }

            Log::info('Sigenergy energy storage mode changed', [
                'system_id' => $systemId,
                'new_mode' => $energyStorageOperationMode
            ]);
            
            return true;
        }
        
        return false;
    }

    /**
     * Set energy storage to automatic mode
     */
    public function setEnergyStorageAutoMode(string $systemId): bool
    {
        return $this->setEnergyStorageOperationMode($systemId, 0);
    }

    /**
     * Set energy storage to forced charge mode
     * Note: Power parameter may be required but not specified in this endpoint
     */
    public function setEnergyStorageForcedChargeMode(string $systemId): bool
    {
        return $this->setEnergyStorageOperationMode($systemId, 1);
    }

    /**
     * Set energy storage to forced discharge mode
     * Note: Power parameter may be required but not specified in this endpoint
     */
    public function setEnergyStorageForcedDischargeMode(string $systemId): bool
    {
        return $this->setEnergyStorageOperationMode($systemId, 2);
    }

    /**
     * Send battery command to control energy storage system
     * Uses batch command format with commands array
     */
    public function sendBatteryCommand(
        string $systemId,
        string $activeMode,
        int $startTime,
        array $additionalParams = []
    ): bool {
        $token = $this->authenticate();
        
        if (!$token) {
            return false;
        }

        // Build command in batch format
        $command = array_merge([
            'systemId' => $systemId,
            'activeMode' => $activeMode,
            'startTime' => $startTime,
        ], $additionalParams);

        $commandData = [
            'accessToken' => $token,
            'commands' => [$command]
        ];

        $response = $this->makeRequest('post', '/openapi/instruction/command', $commandData);

        if ($response?->successful()) {
            $data = $response->json();
            
            // Check for error code in response
            if (isset($data['code']) && $data['code'] !== 0) {
                Log::error('Sigenergy battery command failed', [
                    'system_id' => $systemId,
                    'active_mode' => $activeMode,
                    'code' => $data['code'],
                    'message' => $data['msg'] ?? 'Unknown error'
                ]);
                return false;
            }

            Log::info('Sigenergy battery command sent successfully', [
                'system_id' => $systemId,
                'active_mode' => $activeMode,
                'start_time' => $startTime
            ]);
            
            return true;
        }
        
        return false;
    }

    /**
     * Force charge battery from grid (perfect for cheap Nord Pool prices)
     */
    public function forceChargeBattery(
        string $systemId,
        int $startTime,
        float $chargingPower = null,
        int $duration = null,
        float $pvPower = null,
        string $chargePriorityType = null
    ): bool {
        $params = [];
        if ($chargingPower !== null) {
            $params['chargingPower'] = $chargingPower;
        }
        if ($duration !== null) {
            $params['duration'] = $duration;
        }
        if ($pvPower !== null) {
            $params['pvPower'] = $pvPower;
        }
        if ($chargePriorityType !== null) {
            $params['chargePriorityType'] = $chargePriorityType;
        }

        return $this->sendBatteryCommand($systemId, 'charge', $startTime, $params);
    }

    /**
     * Force discharge battery (perfect for expensive Nord Pool prices)  
     */
    public function forceDischargeBattery(
        string $systemId,
        int $startTime,
        int $dischargePower = null,
        int $duration = null
    ): bool {
        $params = [];
        if ($dischargePower !== null) {
            $params['dischargePower'] = $dischargePower;
        }
        if ($duration !== null) {
            $params['duration'] = $duration;
        }

        return $this->sendBatteryCommand($systemId, 'discharge', $startTime, $params);
    }

    /**
     * Send batch battery commands (up to 24 commands per batch)
     */
    public function sendBatchBatteryCommands(array $commands): bool
    {
        $token = $this->authenticate();
        
        if (!$token) {
            return false;
        }

        if (count($commands) > 24) {
            Log::error('Too many commands in batch', ['count' => count($commands)]);
            return false;
        }

        $commandData = [
            'accessToken' => $token,
            'commands' => $commands
        ];

        $response = $this->makeRequest('post', '/openapi/instruction/command', $commandData);

        if ($response?->successful()) {
            $data = $response->json();
            
            if (isset($data['code']) && $data['code'] !== 0) {
                Log::error('Sigenergy batch command failed', [
                    'code' => $data['code'],
                    'message' => $data['msg'] ?? 'Unknown error'
                ]);
                return false;
            }

            Log::info('Sigenergy batch commands sent successfully', [
                'command_count' => count($commands)
            ]);
            
            return true;
        }
        
        return false;
    }

    /**
     * Set battery to idle mode (maintain current SOC)
     */
    public function setBatteryIdle(string $systemId, int $startTime, int $duration = null): bool
    {
        $params = [];
        if ($duration !== null) {
            $params['duration'] = $duration;
        }

        return $this->sendBatteryCommand($systemId, 'idle', $startTime, $params);
    }

    /**
     * Force charge battery from GRID specifically (Nord Pool optimization)
     */
    public function forceChargeBatteryFromGrid(
        string $systemId,
        int $startTime,
        float $chargingPower,
        int $duration
    ): bool {
        return $this->forceChargeBattery(
            $systemId,
            $startTime,
            $chargingPower,
            $duration,
            null,
            'GRID'
        );
    }

    /**
     * Force charge battery from PV specifically  
     */
    public function forceChargeBatteryFromPV(
        string $systemId,
        int $startTime,
        float $chargingPower,
        int $duration
    ): bool {
        return $this->forceChargeBattery(
            $systemId,
            $startTime,
            $chargingPower,
            $duration,
            null,
            'PV'
        );
    }

    /**
     * Set battery idle with power export limit (curtail PV)
     */
    public function setBatteryIdleWithPowerLimit(
        string $systemId,
        int $startTime,
        int $duration,
        float $maxSellPower = 0.0
    ): bool {
        $params = [
            'duration' => $duration,
            'maxSellPower' => $maxSellPower
        ];

        return $this->sendBatteryCommand($systemId, 'idle', $startTime, $params);
    }

    /**
     * Set self-consumption mode (solar priority for charging)
     */
    public function setBatterySelfConsumption(
        string $systemId,
        int $startTime,
        string $priority = 'PV',
        int $duration = null
    ): bool {
        $params = ['priority' => $priority];
        if ($duration !== null) {
            $params['duration'] = $duration;
        }

        return $this->sendBatteryCommand($systemId, 'selfConsumption', $startTime, $params);
    }

    /**
     * Set self-consumption with grid priority (sell solar, use grid)
     */
    public function setBatterySelfConsumptionGrid(
        string $systemId,
        int $startTime,
        int $duration = null
    ): bool {
        $params = [];
        if ($duration !== null) {
            $params['duration'] = $duration;
        }

        return $this->sendBatteryCommand($systemId, 'selfConsumption-grid', $startTime, $params);
    }

    /**
     * Get system historical data for analysis and optimization
     * Rate limit: One account can only access one station once every 5 minutes
     */
    public function getSystemHistoricalData(
        string $systemId, 
        string $level, 
        string $date = null
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
     * Get monthly historical data 
     */
    public function getMonthlyHistory(string $systemId, string $date): ?array
    {
        return $this->getSystemHistoricalData($systemId, 'Month', $date);
    }

    /**
     * Get yearly historical data
     */
    public function getYearlyHistory(string $systemId, string $date): ?array
    {
        return $this->getSystemHistoricalData($systemId, 'Year', $date);
    }

    /**
     * Get lifetime historical data
     */
    public function getLifetimeHistory(string $systemId): ?array
    {
        return $this->getSystemHistoricalData($systemId, 'Lifetime');
    }

    /**
     * Analyze historical patterns for optimization
     */
    public function analyzeEnergyPatterns(string $systemId, string $date): ?array
    {
        $history = $this->getDailyHistory($systemId, $date);
        
        if (!$history || !isset($history['itemList'])) {
            return null;
        }

        $analysis = [
            'peak_solar_hour' => null,
            'peak_consumption_hour' => null,
            'peak_grid_import_hour' => null,
            'peak_grid_export_hour' => null,
            'battery_cycles' => 0,
            'self_sufficiency_ratio' => 0,
            'energy_efficiency' => 0
        ];

        $maxSolar = 0;
        $maxConsumption = 0;
        $maxGridImport = 0;
        $maxGridExport = 0;
        $totalGeneration = 0;
        $totalSelfConsumption = 0;

        foreach ($history['itemList'] as $item) {
            // Find peak hours
            if ($item['pvTotalPower'] > $maxSolar) {
                $maxSolar = $item['pvTotalPower'];
                $analysis['peak_solar_hour'] = $item['dataTime'];
            }
            
            if ($item['loadPower'] > $maxConsumption) {
                $maxConsumption = $item['loadPower'];
                $analysis['peak_consumption_hour'] = $item['dataTime'];
            }
            
            if ($item['fromGridPower'] > $maxGridImport) {
                $maxGridImport = $item['fromGridPower'];
                $analysis['peak_grid_import_hour'] = $item['dataTime'];
            }
            
            if ($item['toGridPower'] > $maxGridExport) {
                $maxGridExport = $item['toGridPower'];
                $analysis['peak_grid_export_hour'] = $item['dataTime'];
            }

            $totalGeneration += $item['powerGeneration'] ?? 0;
            $totalSelfConsumption += $item['powerSelfConsumption'] ?? 0;
        }

        // Calculate ratios
        if ($totalGeneration > 0) {
            $analysis['self_sufficiency_ratio'] = $totalSelfConsumption / $totalGeneration;
        }

        return $analysis;
    }

    /**
     * Get device historical data (device-specific history)
     * Rate limit: One account can only access one device once every 5 minutes
     */
    public function getDeviceHistoricalData(
        string $systemId,
        string $serialNumber, 
        string $level, 
        string $date = null
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
        string $date = null
    ): ?array {
        return $this->getDeviceHistoricalData($systemId, $serialNumber, $level, $date);
    }

    /**
     * Get inverter historical data for specific device
     */
    public function getInverterHistoricalData(
        string $systemId,
        string $serialNumber,
        string $level,
        string $date = null
    ): ?array {
        return $this->getDeviceHistoricalData($systemId, $serialNumber, $level, $date);
    }

    /**
     * Analyze battery performance over time
     */
    public function analyzeBatteryPerformance(
        string $systemId,
        string $serialNumber,
        string $date
    ): ?array {
        $history = $this->getBatteryHistoricalData($systemId, $serialNumber, 'Day', $date);
        
        if (!$history || !isset($history['itemList'])) {
            return null;
        }

        $analysis = [
            'min_soc' => 100,
            'max_soc' => 0,
            'total_charge_energy' => 0,
            'total_discharge_energy' => 0,
            'charge_cycles' => 0,
            'avg_charge_power' => 0,
            'avg_discharge_power' => 0,
            'efficiency' => 0
        ];

        $chargeCount = 0;
        $dischargeCount = 0;
        $totalChargePower = 0;
        $totalDischargePower = 0;

        foreach ($history['itemList'] as $item) {
            // SOC analysis
            if (isset($item['batterySOC'])) {
                $soc = $item['batterySOC'];
                $analysis['min_soc'] = min($analysis['min_soc'], $soc);
                $analysis['max_soc'] = max($analysis['max_soc'], $soc);
            }

            // Energy analysis
            if (isset($item['chargeEnergy'])) {
                $analysis['total_charge_energy'] += $item['chargeEnergy'];
            }
            
            if (isset($item['dischargeEnergy'])) {
                $analysis['total_discharge_energy'] += $item['dischargeEnergy'];
            }

            // Power analysis
            if (isset($item['chargingDischargingPower'])) {
                $power = $item['chargingDischargingPower'];
                if ($power > 0) {
                    $totalChargePower += $power;
                    $chargeCount++;
                } elseif ($power < 0) {
                    $totalDischargePower += abs($power);
                    $dischargeCount++;
                }
            }
        }

        // Calculate averages
        if ($chargeCount > 0) {
            $analysis['avg_charge_power'] = $totalChargePower / $chargeCount;
        }
        
        if ($dischargeCount > 0) {
            $analysis['avg_discharge_power'] = $totalDischargePower / $dischargeCount;
        }

        // Calculate efficiency
        if ($analysis['total_charge_energy'] > 0) {
            $analysis['efficiency'] = $analysis['total_discharge_energy'] / $analysis['total_charge_energy'];
        }

        // Estimate charge cycles (rough calculation)
        $socRange = $analysis['max_soc'] - $analysis['min_soc'];
        $analysis['charge_cycles'] = $socRange / 100; // Simplified cycle calculation

        return $analysis;
    }

    /**
     * Schedule battery command for specific time (Nord Pool optimization)
     */
    public function scheduleBatteryOptimization(
        string $systemId,
        string $mode,
        \DateTime $startTime,
        int $durationHours,
        array $additionalParams = []
    ): bool {
        return $this->sendBatteryCommand(
            $systemId,
            $mode,
            $startTime->getTimestamp(),
            array_merge($additionalParams, ['duration' => $durationHours * 3600])
        );
    }

    /**
     * Optimize for cheap electricity (charge battery)
     */
    public function optimizeForCheapElectricity(
        string $systemId,
        \DateTime $startTime,
        int $durationHours,
        int $maxChargePower = null
    ): bool {
        Log::info('Optimizing for cheap electricity', [
            'system_id' => $systemId,
            'start_time' => $startTime->format('Y-m-d H:i:s'),
            'duration_hours' => $durationHours
        ]);

        return $this->forceChargeBattery(
            $systemId,
            $startTime->getTimestamp(),
            $maxChargePower,
            $durationHours * 3600
        );
    }

    /**
     * Optimize for expensive electricity (discharge battery)
     */
    public function optimizeForExpensiveElectricity(
        string $systemId,
        \DateTime $startTime,
        int $durationHours,
        int $maxDischargePower = null
    ): bool {
        Log::info('Optimizing for expensive electricity', [
            'system_id' => $systemId,
            'start_time' => $startTime->format('Y-m-d H:i:s'),
            'duration_hours' => $durationHours
        ]);

        return $this->forceDischargeBattery(
            $systemId,
            $startTime->getTimestamp(),
            $maxDischargePower,
            $durationHours * 3600
        );
    }
}