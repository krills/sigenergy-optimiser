<?php

namespace App\Http\Controllers;

use App\Services\SigenEnergyApiService;
use App\Contracts\PriceProviderInterface;
use App\Services\BatteryPlanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Inertia\Inertia;

class AdminDashboardController extends Controller
{
    private SigenEnergyApiService $sigenEnergyApi;
    private PriceProviderInterface $priceApi;
    private BatteryPlanner $batteryPlanner;

    public function __construct(SigenEnergyApiService $sigenEnergyApi, PriceProviderInterface $priceApi, BatteryPlanner $batteryPlanner)
    {
        $this->sigenEnergyApi = $sigenEnergyApi;
        $this->priceApi = $priceApi;
        $this->batteryPlanner = $batteryPlanner;
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

        // Get today's electricity prices
        $pricesData = $this->getTodaysElectricityPrices();

        // Get battery optimization schedule
        $batterySchedule = $this->getBatteryOptimizationSchedule($pricesData);

        // Get current battery mode from Sigenergy API
        $currentBatteryMode = $this->getCurrentBatteryMode($systemsData['systems'] ?? []);

        return Inertia::render('Dashboard', [
            'authenticated' => true,
            'authError' => null,
            'systems' => $systemsData['systems'] ?? [],
            'lastUpdated' => $systemsData['lastUpdated'] ?? null,
            'cacheInfo' => [
                'nextUpdate' => $systemsData['nextUpdate'] ?? null,
                'dataAge' => $systemsData['dataAge'] ?? null
            ],
            'electricityPrices' => $pricesData,
            'batterySchedule' => $batterySchedule,
            'currentBatteryMode' => $currentBatteryMode
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

    /**
     * Get today's electricity prices with caching
     */
    private function getTodaysElectricityPrices(): array
    {
        $cacheKey = 'nordpool_prices_today_se3';
        $cacheDuration = 60; // 1 hour cache

        // Check if we have cached data
        $cachedPrices = Cache::get($cacheKey);
        if ($cachedPrices) {
            return $cachedPrices;
        }

        Log::info('Fetching fresh electricity prices from elprisetjustnu.se API');

        try {
            // Get today's prices (15-minute intervals)
            $prices = $this->priceApi->getDayAheadPrices();

            if (empty($prices)) {
                Log::warning('No electricity prices available from elprisetjustnu.se API');
                return [
                    'prices' => [],
                    'loading' => false,
                    'error' => 'No price data available',
                    'lastUpdated' => now()->toISOString()
                ];
            }

            // Convert to format expected by PriceChart component
            // Note: API data is in Stockholm timezone (+01:00), preserve this for display
            $formattedPrices = [];
            foreach ($prices as $priceData) {
                // Parse with timezone info, then convert to timestamp for frontend
                $datetime = Carbon::parse($priceData['time_start']);
                $formattedPrices[] = [
                    'timestamp' => $datetime->timestamp,
                    'price' => $priceData['value'], // Already in SEK/kWh
                    'hour' => $datetime->format('H:i')
                ];
            }

            $dataToCache = [
                'prices' => $formattedPrices,
                'loading' => false,
                'error' => null,
                'lastUpdated' => now()->toISOString(),
                'provider' => [
                    'name' => $this->priceApi->getProviderName(),
                    'description' => $this->priceApi->getProviderDescription(),
                    'area' => config('services.elprisetjustnu.default_area', 'SE3'),
                    'granularity' => '15min'
                ]
            ];

            // Cache the data for 1 hour
            Cache::put($cacheKey, $dataToCache, now()->addMinutes($cacheDuration));

            Log::info('Successfully cached electricity prices', [
                'price_count' => count($formattedPrices),
                'cache_duration' => $cacheDuration . ' minutes'
            ]);

            return $dataToCache;

        } catch (\Exception $e) {
            Log::error('Error fetching electricity prices', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'prices' => [],
                'loading' => false,
                'error' => 'Failed to fetch price data: ' . $e->getMessage(),
                'lastUpdated' => now()->toISOString()
            ];
        }
    }

    /**
     * Get battery optimization schedule with caching
     */
    private function getBatteryOptimizationSchedule(array $pricesData): array
    {
        $cacheKey = 'battery_optimization_schedule_' . date('Y-m-d');
        $cacheDuration = 15; // 15 minutes cache for battery planning

        // Check if we have cached schedule
        $cachedSchedule = Cache::get($cacheKey);
        if ($cachedSchedule) {
            return $cachedSchedule;
        }

        // Check if price data is available
        if (empty($pricesData['prices'])) {
            Log::warning('No price data available for battery optimization');
            return [
                'schedule' => [],
                'chargeIntervals' => [],
                'analysis' => null,
                'error' => 'No price data available for optimization'
            ];
        }

        Log::info('Generating battery optimization schedule');

        try {
            // Assume current SOC of 50% for planning (in real implementation, get from Sigenergy API)
            $currentSOC = 50.0;

            // Convert price data to format expected by BatteryPlanner
            $intervalPrices = [];
            foreach ($pricesData['prices'] as $priceData) {
                $intervalPrices[] = [
                    'time_start' => date('c', $priceData['timestamp']),
                    'value' => $priceData['price']
                ];
            }

            // Generate optimization schedule
            $result = $this->batteryPlanner->generateSchedule($intervalPrices, $currentSOC);

            // Extract ALL potential charge windows (SOC-agnostic) for the chart
            $chargeIntervals = [];
            foreach ($result['analysis']['charge_windows'] as $chargeWindow) {
                $chargeIntervals[] = [
                    'timestamp' => $chargeWindow['start_time']->timestamp * 1000, // Convert to milliseconds for Highcharts
                    'power' => 3.0, // Standard charging power
                    'reason' => sprintf('Charge window: %.3f SEK/kWh (%s tier)',
                                       $chargeWindow['price'],
                                       $chargeWindow['tier'] === 'cheapest' ? 'cheapest' : 'middle'),
                    'price' => $chargeWindow['price'],
                    'tier' => $chargeWindow['tier']
                ];
            }

            $dataToCache = [
                'schedule' => $result['schedule'],
                'chargeIntervals' => $chargeIntervals,
                'analysis' => $result['analysis'],
                'summary' => array_merge($result['summary'], [
                    'charge_intervals' => count($result['analysis']['charge_windows']),
                    'discharge_intervals' => count($result['analysis']['discharge_windows']),
                    'charge_hours' => count($result['analysis']['charge_windows']) * 0.25,
                    'discharge_hours' => count($result['analysis']['discharge_windows']) * 0.25,
                    'cheapest_windows' => count(array_filter($result['analysis']['charge_windows'], fn($w) => $w['tier'] === 'cheapest')),
                    'middle_windows' => count(array_filter($result['analysis']['charge_windows'], fn($w) => $w['tier'] === 'middle')),
                    'note' => 'Shows ALL potential charge windows (SOC-agnostic)'
                ]),
                'priceTiers' => $result['analysis']['price_tiers'] ?? null,
                'generated_at' => now()->toISOString(),
                'current_soc' => $currentSOC,
                'error' => null
            ];

            // Cache the data for 15 minutes
            Cache::put($cacheKey, $dataToCache, now()->addMinutes($cacheDuration));

            Log::info('Successfully cached battery optimization schedule', [
                'charge_intervals' => count($chargeIntervals),
                'total_intervals' => count($result['schedule']),
                'cache_duration' => $cacheDuration . ' minutes'
            ]);

            return $dataToCache;

        } catch (\Exception $e) {
            Log::error('Error generating battery optimization schedule', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'schedule' => [],
                'chargeIntervals' => [],
                'analysis' => null,
                'error' => 'Failed to generate optimization schedule: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get current battery mode from Sigenergy API
     */
    private function getCurrentBatteryMode(array $systems): array
    {
        // Stockholm system ID
        $stockholmSystemId = 'NDXZZ1731665796';
        
        try {
            // Find Stockholm system in the systems data
            $stockholmSystem = null;
            foreach ($systems as $system) {
                if ($system['systemId'] === $stockholmSystemId) {
                    $stockholmSystem = $system;
                    break;
                }
            }

            if (!$stockholmSystem) {
                return [
                    'mode' => 'unknown',
                    'status' => 'error',
                    'error' => 'Stockholm system not found'
                ];
            }

            // Get energy flow data to determine current mode
            $energyFlow = $this->sigenEnergyApi->getSystemEnergyFlow($stockholmSystemId);
            
            if ($energyFlow === null) {
                return [
                    'mode' => 'unknown',
                    'status' => 'error',
                    'error' => 'Could not fetch energy flow data'
                ];
            }

            // Determine mode based on battery power
            $batteryPower = $energyFlow['batteryPower'] ?? 0;
            $batterySoc = $energyFlow['batterySoc'] ?? null;
            
            $mode = 'idle';
            $power_kw = 0;
            
            if ($batteryPower > 0.1) {
                $mode = 'charge';
                $power_kw = $batteryPower;
            } elseif ($batteryPower < -0.1) {
                $mode = 'discharge'; 
                $power_kw = abs($batteryPower);
            }

            return [
                'mode' => $mode,
                'status' => $mode !== 'idle' ? 'active' : 'inactive',
                'power_kw' => $power_kw > 0 ? $power_kw : null,
                'battery_soc' => $batterySoc,
                'battery_power' => $batteryPower,
                'last_updated' => now()->toISOString()
            ];

        } catch (\Exception $e) {
            Log::error('Error fetching current battery mode from Sigenergy API', [
                'error' => $e->getMessage(),
                'system_id' => $stockholmSystemId
            ]);
            
            return [
                'mode' => 'unknown',
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }

}
