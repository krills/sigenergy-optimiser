<?php

namespace App\Http\Controllers;

use App\Services\SigenEnergyApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class AdminDashboardController extends Controller
{
    private SigenEnergyApiService $sigenEnergyApi;

    public function __construct(SigenEnergyApiService $sigenEnergyApi)
    {
        $this->sigenEnergyApi = $sigenEnergyApi;
    }

    /**
     * Display the admin dashboard with systems and devices
     */
    public function index(Request $request)
    {
        // Cache key for session authentication status
        $authCacheKey = 'sigenergy_dashboard_auth_' . session()->getId();

        // Try to get cached auth status first
        $authError = null;
        $isAuthenticated = false;

        // Check if we have valid authentication in session cache
        $sessionAuth = Cache::get($authCacheKey);
        if ($sessionAuth && $sessionAuth['expires_at'] > now()) {
            $isAuthenticated = true;
        } else {
            Log::info('Attempting Sigenergy authentication for dashboard');
            $token = $this->sigenEnergyApi->authenticate();

            if ($token) {
                $isAuthenticated = true;
                // Cache authentication status for 24 hours (as requested)
                Cache::put($authCacheKey, [
                    'authenticated' => true,
                    'expires_at' => now()->addHours(24)
                ], now()->addHours(24));

                Log::info('Sigenergy authentication successful for dashboard');
            } else {
                $authError = 'Failed to authenticate with Sigenergy API. Please check your credentials.';
                Log::error('Sigenergy authentication failed for dashboard');
            }
        }

        // If not authenticated, show error state
        if (!$isAuthenticated) {
            return Inertia::render('Dashboard', [
                'authenticated' => false,
                'authError' => $authError,
                'systems' => [],
                'lastUpdated' => null
            ]);
        }

        // Get systems and devices data with caching
        $systemsData = $this->getCachedSystemsAndDevices();

        return Inertia::render('Dashboard', [
            'authenticated' => true,
            'authError' => null,
            'systems' => $systemsData['systems'] ?? [],
            'lastUpdated' => $systemsData['lastUpdated'] ?? null,
            'cacheInfo' => [
                'nextUpdate' => $systemsData['nextUpdate'] ?? null,
                'dataAge' => $systemsData['dataAge'] ?? null
            ]
        ]);
    }

    /**
     * Get systems and devices data with 5-minute caching
     */
    private function getCachedSystemsAndDevices(): array
    {
        $cacheKey = 'sigenergy_systems_devices_data';
        $cacheDuration = 5; // 5 minutes as requested

        // Check if we have cached data
        $cachedData = Cache::get($cacheKey);
        if ($cachedData) {
            return $cachedData;
        }

        Log::info('Fetching fresh systems and devices data from Sigenergy API');

        try {
            // Get systems list
            $systems = $this->sigenEnergyApi->getSystemList();

            if ($systems === null) {
                Log::error('Failed to fetch systems list from Sigenergy API');
                return [
                    'systems' => [],
                    'lastUpdated' => now()->toISOString(),
                    'nextUpdate' => now()->addMinutes($cacheDuration)->toISOString(),
                    'dataAge' => 0,
                    'error' => 'Failed to fetch systems data'
                ];
            }

            // Enrich systems with devices data
            $enrichedSystems = [];
            foreach ($systems as $system) {
                $systemId = $system['systemId'] ?? null;

                if (!$systemId) {
                    Log::warning('System missing systemId', ['system' => $system]);
                    continue;
                }

                // Get devices for this system (with rate limiting consideration)
                $devices = $this->sigenEnergyApi->getDeviceList($systemId);

                if ($devices === null) {
                    Log::warning('Failed to fetch devices for system', ['systemId' => $systemId]);
                    $devices = [];
                }

                // Categorize devices
                $deviceCategories = $this->categorizeDevices($devices);

                $enrichedSystems[] = [
                    'systemId' => $systemId,
                    'systemName' => $system['systemName'] ?? 'Unknown System',
                    'address' => $system['addr'] ?? null,
                    'status' => $system['status'] ?? 'unknown',
                    'isActive' => $system['isActivate'] ?? false,
                    'onOffGridStatus' => $system['onOffGridStatus'] ?? 'unknown',
                    'timeZone' => $system['timeZone'] ?? null,
                    'pvCapacity' => $system['pvCapacity'] ?? null,
                    'batteryCapacity' => $system['batteryCapacity'] ?? null,
                    'gridConnectTime' => $system['gridConnectTime'] ?? null,
                    'devices' => [
                        'total' => count($devices),
                        'batteries' => $deviceCategories['batteries'],
                        'inverters' => $deviceCategories['inverters'],
                        'gateways' => $deviceCategories['gateways'],
                        'meters' => $deviceCategories['meters'],
                        'other' => $deviceCategories['other']
                    ],
                    'rawDevices' => $devices
                ];

                // Rate limiting: Sleep briefly between device API calls
                usleep(100000); // 100ms delay to respect rate limits
            }

            // Prepare cached data
            $dataToCache = [
                'systems' => $enrichedSystems,
                'lastUpdated' => now()->toISOString(),
                'nextUpdate' => now()->addMinutes($cacheDuration)->toISOString(),
                'dataAge' => 0
            ];

            // Cache the data for 5 minutes
            Cache::put($cacheKey, $dataToCache, now()->addMinutes($cacheDuration));

            Log::info('Successfully cached systems and devices data', [
                'systems_count' => count($enrichedSystems),
                'cache_duration' => $cacheDuration . ' minutes'
            ]);

            return $dataToCache;

        } catch (\Exception $e) {
            Log::error('Error fetching systems and devices data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'systems' => [],
                'lastUpdated' => now()->toISOString(),
                'nextUpdate' => now()->addMinutes($cacheDuration)->toISOString(),
                'dataAge' => 0,
                'error' => 'Exception occurred while fetching data: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Categorize devices by type
     */
    private function categorizeDevices(array $devices): array
    {
        $categories = [
            'batteries' => [],
            'inverters' => [],
            'gateways' => [],
            'meters' => [],
            'other' => []
        ];

        foreach ($devices as $device) {
            $deviceType = strtolower($device['deviceType'] ?? '');

            // Log device types to help identify "other" devices
            Log::debug('Device categorization', [
                'serial' => $device['serialNumber'] ?? 'unknown',
                'type' => $device['deviceType'] ?? 'unknown',
                'type_lower' => $deviceType
            ]);

            if (str_contains($deviceType, 'battery') ||
                str_contains($deviceType, 'ess') ||
                str_contains($deviceType, 'bms') ||
                str_contains($deviceType, 'storage')) {
                $categories['batteries'][] = $device;
            } elseif (str_contains($deviceType, 'inverter') ||
                      str_contains($deviceType, 'aio') ||
                      str_contains($deviceType, 'hybrid') ||
                      str_contains($deviceType, 'pcs')) {
                $categories['inverters'][] = $device;
            } elseif (str_contains($deviceType, 'gateway') ||
                      str_contains($deviceType, 'hub') ||
                      str_contains($deviceType, 'communication') ||
                      str_contains($deviceType, 'comm')) {
                $categories['gateways'][] = $device;
            } elseif (str_contains($deviceType, 'meter') ||
                      str_contains($deviceType, 'monitor') ||
                      str_contains($deviceType, 'sensor')) {
                $categories['meters'][] = $device;
            } else {
                // Add detailed logging for uncategorized devices
                Log::info('Uncategorized device found', [
                    'serial' => $device['serialNumber'] ?? 'unknown',
                    'type' => $device['deviceType'] ?? 'unknown',
                    'pn' => $device['pn'] ?? 'unknown',
                    'status' => $device['status'] ?? 'unknown'
                ]);
                $categories['other'][] = $device;
            }
        }

        return $categories;
    }

    /**
     * Force refresh the cached data
     */
    public function refresh(Request $request)
    {
        $cacheKey = 'sigenergy_systems_devices_data';

        // Clear the cache to force fresh data
        Cache::forget($cacheKey);

        Log::info('Dashboard data refresh requested by user');

        // Redirect back to dashboard which will fetch fresh data
        return redirect()->route('dashboard')->with('message', 'Dashboard data refreshed successfully');
    }

    /**
     * Get real-time energy flow data for a specific system
     */
    public function getSystemEnergyFlow(Request $request, string $systemId)
    {
        try {
            $energyFlow = $this->sigenEnergyApi->getSystemEnergyFlow($systemId);

            if ($energyFlow === null) {
                return response()->json([
                    'error' => 'Failed to fetch energy flow data'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $energyFlow,
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching energy flow data', [
                'systemId' => $systemId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Exception occurred: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get real-time summary data for a specific system
     */
    public function getSystemRealtime(Request $request, string $systemId)
    {
        try {
            $realtimeData = $this->sigenEnergyApi->getSystemRealtimeData($systemId);

            if ($realtimeData === null) {
                return response()->json([
                    'error' => 'Failed to fetch realtime data'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $realtimeData,
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching realtime data', [
                'systemId' => $systemId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Exception occurred: ' . $e->getMessage()
            ], 500);
        }
    }
}
