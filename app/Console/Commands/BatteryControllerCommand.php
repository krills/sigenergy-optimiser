<?php

namespace App\Console\Commands;

use App\Services\BatteryPlanner;
use App\Contracts\PriceProviderInterface;
use App\Services\SigenEnergyApiService;
use App\Models\BatteryHistory;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BatteryControllerCommand extends Command
{
    protected $signature = 'send-instruction
                            {--dry-run : Show what would be done without executing}
                            {--force : Force execution even outside normal schedule}
                            {--system-id= : Sigenergy system ID to control}';

    protected $description = 'Execute battery optimization every 15 minutes (production controller)';

    // System constants
    private const float BATTERY_CAPACITY_KWH = 8.0;

    private BatteryPlanner $planner;
    private PriceProviderInterface $priceApi;
    private SigenEnergyApiService $sigenApi;

    public function __construct(
        BatteryPlanner $planner,
        PriceProviderInterface $priceApi,
        SigenEnergyApiService $sigenApi
    ) {
        parent::__construct();
        $this->planner = $planner;
        $this->priceApi = $priceApi;
        $this->sigenApi = $sigenApi;
    }

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $systemId = $this->getSystemId();

        $this->info('🤖 Battery Controller - ' . ($isDryRun ? 'DRY RUN MODE' : 'PRODUCTION MODE'));
        $this->line('════════════════════════════════════════════════════════════════');

        try {
            // 1. Validate timing (should run at start of 15-minute intervals)
            if (!$this->isValidExecutionTime() && !$this->option('force')) {
                $this->warn('⚠️  Controller should run at start of 15-minute intervals (00, 15, 30, 45 minutes)');
                $this->line('Use --force to override, or wait for next scheduled interval');
                return 1;
            }

            // 2. Get current system state from Sigenergy API
            $this->line('📡 Getting current system state from Sigenergy...');
            $systemState = $this->getCurrentSystemState($systemId);
            $this->displaySystemState($systemState);

            // 3. Get current electricity prices
            $this->line('💰 Fetching electricity prices...');
            $prices = $this->priceApi->getDayAheadPrices();
            if (empty($prices)) {
                throw new \Exception('No price data available from price provider');
            }

            $currentPrice = $this->getCurrentIntervalPrice($prices);
            $this->line("💡 Current price: " . number_format($currentPrice, 3) . " SEK/kWh");

            // 4. Ask BatteryPlanner for recommendation
            $this->line('🧠 Asking BatteryPlanner for recommendation...');
            $plannerDecision = $this->planner->makeImmediateDecision(
                $prices,
                $systemState['current_soc'],
                $systemState['solar_power'],
                $systemState['load_power']
            );

            $this->displayPlannerDecision($plannerDecision);

            // 5. Execute decision (or simulate in dry-run)
            if ($isDryRun) {
                $this->warn('🔸 DRY RUN: Would execute ' . $plannerDecision['action'] . ' command');
                $executionResult = ['success' => true, 'simulated' => true];
            } else {
                $this->line('⚡ Executing command to Sigenergy API...');
                $executionResult = $this->executeCommand($systemId, $plannerDecision);
            }

            // 6. Log decision and results to database
            $this->line('📝 Logging to database...');
            $historyRecord = $this->logOptimizationCycle(
                $systemId,
                $plannerDecision,
                $systemState,
                $currentPrice,
                $prices,
                $executionResult,
                $isDryRun
            );

            // 8. Display results
            $this->displayResults($historyRecord, $executionResult, $isDryRun, $systemId);

            $this->info('✅ Battery controller cycle completed successfully');
            return 0;

        } catch (\Exception $e) {
            Log::error('BatteryController command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);


            $this->error('❌ Battery controller failed: ' . $e->getMessage());
            $this->error( $e->getTraceAsString());
            return 1;
        }
    }

    /**
     * Get system ID from API or command option
     */
    private function getSystemId(): string
    {
        // Use command option if provided
        if ($systemId = $this->option('system-id')) {
            return $systemId;
        }

        try {
            // Get systems from Sigenergy API
            $systems = $this->sigenApi->getSystemList();

            if (empty($systems)) {
                throw new \Exception('No systems found via Sigenergy API. Please specify --system-id option.');
            }

            // Use the first system found
            $primarySystem = $systems[0];
            $systemId = $primarySystem['systemId'];

            $systemName = $primarySystem['systemName'] ?? 'Unknown';
            $this->info("📡 Using system: {$systemId} ({$systemName})");
            return $systemId;

        } catch (\Exception $e) {
            Log::error('Failed to retrieve system ID', [
                'error' => $e->getMessage()
            ]);

            throw new \Exception('Cannot determine system ID. Either provide --system-id option or ensure Sigenergy API is accessible: ' . $e->getMessage());
        }
    }

    /**
     * Check if current time is valid for 15-minute execution
     */
    private function isValidExecutionTime(): bool
    {
        $minute = now()->minute;
        return in_array($minute, [0, 15, 30, 45]);
    }

    /**
     * Get current system state from Sigenergy API
     */
    private function getCurrentSystemState(string $systemId): array
    {
        try {
            // Get real-time data from Sigenergy API
            $energyFlow = $this->sigenApi->getSystemEnergyFlow($systemId);

            return [
                'current_soc' => $energyFlow['batterySoc'] ?? 0.0,
                'solar_power' => $energyFlow['pvPower'] ?? 0.0,
                'load_power' => abs($energyFlow['loadPower'] ?? 0.0),
                'grid_power' => $energyFlow['gridPower'] ?? 0.0,
                'battery_power' => $energyFlow['batteryPower'] ?? 0.0,
                'timestamp' => now()
            ];

        } catch (\Exception $e) {
            Log::warning('Failed to get real system state, using simulated data', [
                'error' => $e->getMessage()
            ]);

            // Fallback to simulated data for testing
            return [
                'current_soc' => 45.0,
                'solar_power' => 2.1,
                'load_power' => 1.8,
                'grid_power' => 0.3,
                'battery_power' => 0.0,
                'timestamp' => now(),
                'simulated' => true
            ];
        }
    }

    /**
     * Get current interval price from today's prices
     */
    private function getCurrentIntervalPrice(array $prices): float
    {
        $now = now();
        $intervalStart = $now->copy()->startOfHour()->addMinutes(floor($now->minute / 15) * 15);

        foreach ($prices as $priceData) {
            $priceTime = Carbon::parse($priceData['time_start']);
            if ($priceTime->equalTo($intervalStart)) {
                return $priceData['value'];
            }
        }

        // Fallback to current hour price or average
        foreach ($prices as $priceData) {
            $priceTime = Carbon::parse($priceData['time_start']);
            if ($priceTime->hour === $now->hour && $priceTime->minute === 0) {
                return $priceData['value'];
            }
        }

        return array_sum(array_column($prices, 'value')) / count($prices);
    }

    /**
     * Execute battery command via Sigenergy API
     */
    private function executeCommand(string $systemId, array $decision): array
    {
        try {
            $action = $decision['action'];
            $power = $decision['power'] ?? 3.0;

            Log::info('BatteryController: Executing command', [
                'system_id' => $systemId,
                'action' => $action,
                'power' => $power,
                'timestamp' => now()->toISOString()
            ]);

            $startTime = microtime(true);
            $result = null;
            $apiResponse = null;

            switch ($action) {
                case 'charge':
                    $apiResponse = $this->sigenApi->forceChargeBatteryMqtt($systemId, time() + 60, $power, 15);
                    $result = $apiResponse['success'] ?? false;
                    $apiResponse['power'] = $power; // Add power to response
                    break;

                case 'discharge':
                    $apiResponse = $this->sigenApi->forceDischargeBatteryMqtt($systemId, time(), $power, 15);
                    $result = $apiResponse['success'] ?? false;
                    $apiResponse['power'] = $power; // Add power to response
                    break;

                case 'idle':
                    $apiResponse = $this->sigenApi->setBatteryIdleMqtt($systemId, time(), 15);
                    $result = $apiResponse['success'] ?? false;
                    break;

                default:
                    throw new \Exception("Unknown battery action: {$action}");
            }

            $executionTime = round((microtime(true) - $startTime) * 1000, 2); // milliseconds

            if ($result) {
                Log::info('BatteryController: Command executed successfully', [
                    'system_id' => $systemId,
                    'action' => $action,
                    'power' => $power,
                    'execution_time_ms' => $executionTime,
                    'api_response' => $apiResponse
                ]);
            } else {
                Log::warning('BatteryController: Command returned false', [
                    'system_id' => $systemId,
                    'action' => $action,
                    'power' => $power,
                    'execution_time_ms' => $executionTime,
                    'api_response' => $apiResponse
                ]);
            }

            return [
                'success' => $result,
                'action_executed' => $action,
                'power_setting' => $power,
                'execution_time' => now(),
                'execution_time_ms' => $executionTime,
                'api_response' => $apiResponse
            ];

        } catch (\Exception $e) {
            $executionTime = isset($startTime) ? round((microtime(true) - $startTime) * 1000, 2) : 0;

            Log::error('Failed to execute battery command', [
                'system_id' => $systemId,
                'action' => $decision['action'],
                'power' => $decision['power'] ?? 3.0,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_class' => get_class($e),
                'execution_time_ms' => $executionTime,
                'api_response' => $apiResponse ?? null,
                'trace' => $e->getTraceAsString()
            ]);

            // Return detailed error result but don't fail completely
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_class' => get_class($e),
                'action_executed' => $decision['action'],
                'execution_time' => now(),
                'execution_time_ms' => $executionTime,
                'api_response' => $apiResponse ?? null
            ];
        }
    }

    /**
     * Log optimization cycle to database
     */
    private function logOptimizationCycle(
        string $systemId,
        array $decision,
        array $systemState,
        float $currentPrice,
        array $allPrices,
        array $executionResult,
        bool $isDryRun
    ): BatteryHistory {
        $now = now();
        $intervalStart = $now->copy()->startOfHour()->addMinutes(floor($now->minute / 15) * 15);

        // Determine price tier
        $priceTier = $this->determinePriceTier($currentPrice, $allPrices);
        $dailyAvgPrice = array_sum(array_column($allPrices, 'value')) / count($allPrices);

        // Calculate current charge cost (this is the key addition)
        $chargeTracking = $this->calculateCurrentChargeMetrics($systemId, $systemState['current_soc'], $decision, $currentPrice);

        // Create history record
        $historyData = [
            'system_id' => $systemId,
            'interval_start' => $intervalStart,
            'soc_start' => $systemState['current_soc'],
            'action' => $decision['action'],
            'power_kw' => $decision['power'] ?? 0,
            'price_sek_kwh' => $currentPrice,
            'price_tier' => $priceTier,
            'daily_avg_price' => $dailyAvgPrice,
            'decision_source' => 'controller',
            'decision_factors' => [
                'planner_recommendation' => $decision['action'],
                'confidence' => $decision['confidence'] ?? 'medium',
                'reason' => $decision['reason'] ?? 'Planner recommendation',
                'system_soc' => $systemState['current_soc'],
                'system_solar_kw' => $systemState['solar_power'],
                'system_load_kw' => $systemState['load_power'],
                'system_grid_kw' => $systemState['grid_power'],
                'price_sek_kwh' => $currentPrice,
                'price_tier' => $priceTier,
                'execution_success' => $executionResult['success'] ?? false,
                'execution_error' => $executionResult['error'] ?? null,
                'execution_time_ms' => $executionResult['execution_time_ms'] ?? null,
                'execution_error_code' => $executionResult['error_code'] ?? null,
                'api_response' => $executionResult['api_response'] ?? null,
                'is_dry_run' => $isDryRun
            ],
            'solar_production_kw' => $systemState['solar_power'],
            'home_consumption_kw' => $systemState['load_power'],
            'grid_export_kw' => max(0, $systemState['grid_power']),
            'grid_import_kw' => max(0, -$systemState['grid_power']),
            // New cost tracking fields
            'cost_of_current_charge_sek' => $chargeTracking['total_cost'],
            'avg_charge_price_sek_kwh' => $chargeTracking['avg_price'],
            'energy_in_battery_kwh' => $chargeTracking['total_energy']
        ];

        return BatteryHistory::createInterval($historyData);
    }

    /**
     * Calculate current charge cost and energy metrics
     */
    private function calculateCurrentChargeMetrics(string $systemId, float $currentSoc, array $decision, float $currentPrice): array
    {
        // Get recent charge intervals to calculate weighted average cost
        $recentChargeIntervals = BatteryHistory::forSystem($systemId)
            ->where('action', 'charge')
            ->where('interval_start', '>=', now()->subDays(7)) // Last 7 days
            ->orderBy('interval_start', 'desc')
            ->get();

        // Estimate energy in battery based on SOC
        $totalEnergyInBattery = (self::BATTERY_CAPACITY_KWH * $currentSoc) / 100;

        // Calculate weighted average cost (FIFO basis)
        $totalCost = 0;
        $totalWeightedEnergy = 0;
        $intervalCount = 0;

        // Calculate average price of energy in battery
        $avgPrice = $totalWeightedEnergy > 0 ? $totalCost / $totalWeightedEnergy : 0;

        // Estimate total cost of energy currently in battery
        $totalCostInBattery = $totalEnergyInBattery * $avgPrice;

        return [
            'total_cost' => $totalCostInBattery,
            'avg_price' => $avgPrice,
            'total_energy' => $totalEnergyInBattery,
            'calculation_basis' => [
                'intervals_analyzed' => $intervalCount,
                'weighted_energy_kwh' => $totalWeightedEnergy,
                'current_soc' => $currentSoc,
                'estimated_energy_in_battery' => $totalEnergyInBattery
            ]
        ];
    }

    /**
     * Determine price tier for current price
     */
    private function determinePriceTier(float $currentPrice, array $prices): string
    {
        $sortedPrices = array_column($prices, 'value');
        sort($sortedPrices);

        $count = count($sortedPrices);

        // Get the last value of each tier (33% and 67% cutoffs)
        $cheapestCutoff = intval($count * 0.33);
        $middleCutoff = intval($count * 0.67);

        $cheapestThird = $sortedPrices[$cheapestCutoff - 1]; // Last of cheapest tier
        $middleThird = $sortedPrices[$middleCutoff - 1]; // Last of middle tier

        if ($currentPrice <= $cheapestThird) {
            return 'cheapest';
        } elseif ($currentPrice <= $middleThird) {
            return 'middle';
        } else {
            return 'expensive';
        }
    }

    /**
     * Display system state
     */
    private function displaySystemState(array $state): void
    {
        $this->line("   🔋 SOC: {$state['current_soc']}%");
        $this->line("   ☀️  Solar: " . number_format($state['solar_power'], 1) . " kW");
        $this->line("   🏠 Load: " . number_format($state['load_power'], 1) . " kW");
        $this->line("   ⚡ Grid: " . number_format($state['grid_power'], 1) . " kW");
        $this->line("   🔋 Battery: " . number_format($state['battery_power'], 1) . " kW");

        if (isset($state['simulated'])) {
            $this->warn("   ⚠️  Using simulated data (Sigenergy API unavailable)");
        }
    }

    /**
     * Display planner decision
     */
    private function displayPlannerDecision(array $decision): void
    {
        $action = strtoupper($decision['action']);
        $actionColor = match($decision['action']) {
            'charge' => 'green',
            'discharge' => 'red',
            'idle' => 'gray',
            default => 'yellow'
        };

        $this->line("   🎯 Decision: <fg={$actionColor};options=bold>{$action}</>");

        if (isset($decision['power'])) {
            $this->line("   ⚡ Power: " . number_format($decision['power'], 1) . " kW");
        }

        $this->line("   🧠 Reason: " . ($decision['reason'] ?? 'N/A'));
        $this->line("   📊 Confidence: " . ($decision['confidence'] ?? 'medium'));
    }

    /**
     * Display final results
     */
    private function displayResults(BatteryHistory $record, array $executionResult, bool $isDryRun, ?string $systemId = null): void
    {
        $this->newLine();
        $this->info('📋 Cycle Summary');
        $this->line("   📝 History ID: {$record->id}");
        $this->line("   ⏰ Interval: " . $record->interval_start->format('H:i'));
        $this->line("   🎯 Action: " . strtoupper($record->action));
        $this->line("   💰 Price: " . number_format($record->price_sek_kwh, 3) . " SEK/kWh ({$record->price_tier} tier)");

        if ($record->cost_of_current_charge_sek) {
            $this->line("   💳 Cost in battery: " . number_format($record->cost_of_current_charge_sek, 2) . " SEK");
            $this->line("   📊 Avg charge price: " . number_format($record->avg_charge_price_sek_kwh, 3) . " SEK/kWh");
        }

        if (!$isDryRun && isset($executionResult['success'])) {
            $status = $executionResult['success'] ? '✅ SUCCESS' : '❌ FAILED';
            $this->line("   🔧 API Result: {$status}");

            // Show execution timing
            if (isset($executionResult['execution_time_ms'])) {
                $this->line("   ⏱️  Execution Time: " . $executionResult['execution_time_ms'] . " ms");
            }

            if (!$executionResult['success']) {
                // Show detailed error information
                if (isset($executionResult['error'])) {
                    $this->error("   ❌ Error: " . $executionResult['error']);
                }

                if (isset($executionResult['error_code']) && $executionResult['error_code'] > 0) {
                    $this->error("   🔢 Error Code: " . $executionResult['error_code']);
                }

                if (isset($executionResult['error_class'])) {
                    $this->line("   🏷️  Error Type: " . $executionResult['error_class']);
                }

                // Show API response if available
                if (isset($executionResult['api_response'])) {
                    $this->line("   📡 Result: " . json_encode($executionResult['api_response']));
                }

                // Add troubleshooting suggestions
                $this->newLine();
                $this->warn('💡 Troubleshooting Suggestions:');
                $this->line('   • Check if system ID is correct: ' . ($systemId ?? 'Unknown'));
                $this->line('   • Verify Sigenergy API credentials are valid');
                $this->line('   • Ensure system is online and accessible');
                $this->line('   • Check rate limits (max 1 command per 5 minutes)');
                $this->line('   • Review full error logs: tail -f storage/logs/laravel-*.log');
            } else {
                // Show success details
                if (isset($executionResult['api_response'])) {
                    $this->line("   📡 API Response: " . json_encode($executionResult['api_response']));
                }
            }
        }
    }
}
