<?php

namespace App\Console\Commands;

use App\Services\BatteryPlanner;
use App\Enum\SigEnergy\BatteryInstruction;
use App\Contracts\PriceProviderInterface;
use App\Services\SigenEnergyApiService;
use App\Models\BatteryHistory;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BatteryControllerCommand extends Command
{
    public const string SIGNATURE = 'app:send-instruction';
    protected $signature = self::SIGNATURE .'
                            {--dry-run : Show what would be done without executing}
                            {--force : Force execution even outside normal schedule}
                            {--system-id= : Sigenergy system ID to control}
                            {--target-grid=6 : Target grid consumption during charging (kW)}
                            {--charge-power=4 : Maximum charging power (kW)}
                            {--time= : Simulate decision for specific time (YYYY-MM-DD HH:MM format, forces dry-run)}';

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
        $simulationTime = $this->option('time');
        $isDryRun = $this->option('dry-run') || !empty($simulationTime); // Force dry-run when time is specified
        $systemId = $this->getSystemId();

        $this->info('🤖 Battery Controller - ' . ($isDryRun ? 'DRY RUN MODE' : 'PRODUCTION MODE'));
        if ($simulationTime) {
            $this->line('🕐 Simulating time: ' . $simulationTime);
        }
        $this->line('════════════════════════════════════════════════════════════════');

        try {
            // Parse simulation time if provided (ensure same timezone as price data)
            $currentTime = $simulationTime ? Carbon::parse($simulationTime)->setTimezone('Europe/Stockholm') : now();
            
            // 1. Validate timing (should run at start of 15-minute intervals)
            if (!$this->isValidExecutionTime($currentTime) && !$this->option('force') && !$simulationTime) {
                $this->warn('⚠️  Controller should run at start of 15-minute intervals (00, 15, 30, 45 minutes)');
                $this->line('Use --force to override, or wait for next scheduled interval');
                return 1;
            }

            // 2. Get current system state from Sigenergy API
            $this->line('📡 Getting current system state from Sigenergy...');
            $systemState = $this->getCurrentSystemState($systemId);
            $this->displaySystemState($systemState);

            // 3. Get current electricity prices and make decision
            $this->line('💰 Fetching electricity prices...');
            $prices = $this->priceApi->getDayAheadPrices();
            if (empty($prices)) {
                throw new \Exception('No price data available from price provider');
            }

            // 4. Floor negative prices for optimization (preserve originals for logging)
            $flooredPrices = array_map(function($priceData) {
                $flooredData = $priceData;
                $flooredData['value'] = max(0, $priceData['value']); // Floor to minimum 0
                $flooredData['original_value'] = $priceData['value']; // Preserve original
                return $flooredData;
            }, $prices);

            // 5. Ask BatteryPlanner for pure price-based recommendation
            $this->line('🧠 Asking BatteryPlanner for price analysis...');
            $priceRecommendation = $this->planner->makeImmediateDecision($flooredPrices, null, $currentTime);
            $this->displayPlannerDecision($priceRecommendation);

            // 6. Apply operational logic (SOC, grid, load) to price recommendation
            $this->line('⚙️ Applying operational constraints...');
            $plannerDecision = $this->applyOperationalLogic($priceRecommendation, $systemState);

            // 7. Controller safety checks and final decision
            $finalDecision = $this->applyControllerSafetyChecks($plannerDecision, $systemState);

            $this->displayFinalDecision($finalDecision);

            // 6. Execute decision (or simulate in dry-run)
            if ($isDryRun) {
                $this->warn('🔸 DRY RUN: Would execute ' . $finalDecision['action']->value . ' command');
                $executionResult = ['success' => true, 'simulated' => true];
            } else {
                $this->line('⚡ Executing command to Sigenergy API...');
                $executionResult = $this->executeCommand($systemId, $finalDecision);
            }

            // 7. Log decision and results to database (skip if simulation time)
            if (!$simulationTime) {
                $this->line('📝 Logging to database...');
                $historyRecord = $this->logOptimizationCycle(
                    $systemId,
                    $finalDecision,
                    $systemState,
                    $prices,
                    $executionResult,
                    $isDryRun
                );
            } else {
                $this->line('📝 Skipping database logging (simulation mode)');
                $historyRecord = null;
            }

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
    private function isValidExecutionTime(?Carbon $time = null): bool
    {
        $time = $time ?? now();
        $minute = $time->minute;
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
                case BatteryInstruction::CHARGE->value:
                    $apiResponse = $this->sigenApi->forceChargeBatteryMqtt($systemId, time() + 60, $power, 15);
                    $result = $apiResponse['success'] ?? false;
                    $apiResponse['power'] = $power; // Add power to response
                    break;

                case BatteryInstruction::DISCHARGE->value:
                    $apiResponse = $this->sigenApi->forceDischargeBatteryMqtt($systemId, time(), $power, 15);
                    $result = $apiResponse['success'] ?? false;
                    $apiResponse['power'] = $power; // Add power to response
                    break;

                case BatteryInstruction::IDLE->value:
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
     * Apply operational logic (SOC, grid, load) to price-based recommendation
     */
    private function applyOperationalLogic(array $priceRecommendation, array $systemState): array
    {
        $currentSOC = $systemState['current_soc'];
        $solarPower = $systemState['solar_power'];
        $loadPower = $systemState['load_power'];
        $netLoad = $loadPower - $solarPower; // Positive = need power, negative = excess
        $currentGridConsumption = $netLoad;

        // Start with price recommendation
        $decision = $priceRecommendation;

        // Emergency charging takes absolute priority
        if ($currentSOC <= 10) {
            return [
                'action' => BatteryInstruction::CHARGE,
                'power' => $this->calculateOptimalChargePower($currentGridConsumption),
                'duration' => 15,
                'reason' => sprintf('Emergency charge - SOC critically low (%.1f%%)', $currentSOC),
                'confidence' => 'high',
                'override_reason' => 'Emergency SOC override'
            ];
        }

        // SOC-based constraints on price recommendation
        if ($decision['action'] === BatteryInstruction::CHARGE) {
            if ($currentSOC >= 95) {
                $decision['action'] = BatteryInstruction::IDLE;
                $decision['power'] = 0;
                $decision['reason'] = 'Price suggests charging but SOC too high (≥95%)';
                $decision['confidence'] = 'high';
            } else {
                // Adjust charging power based on grid consumption target
                $decision['power'] = $this->calculateOptimalChargePower($currentGridConsumption);
            }
        }

        if ($decision['action'] === BatteryInstruction::DISCHARGE) {
            if ($currentSOC <= 20) {
                $decision['action'] = BatteryInstruction::IDLE;
                $decision['power'] = 0;
                $decision['reason'] = sprintf('Price suggests discharging but SOC too low (%.1f%% ≤ 20%%)', $currentSOC);
                $decision['confidence'] = 'high';
            } else {
                // Adjust discharge power based on load
                $maxDischargePower = (float) $this->option('charge-power'); // Use same power limit for discharge
                $decision['power'] = min($maxDischargePower, max(1.0, $netLoad));
            }
        }

        // If price recommendation is idle, check for load balancing needs
        if ($decision['action'] === BatteryInstruction::IDLE) {
            // High excess solar - absorb with charging
            if ($netLoad < -2.0 && $currentSOC < 85) {
                $maxChargePower = (float) $this->option('charge-power');
                $decision = [
                    'action' => BatteryInstruction::CHARGE,
                    'power' => min($maxChargePower, abs($netLoad)),
                    'duration' => 15,
                    'reason' => sprintf('Absorbing excess solar (%.1fkW), price neutral', abs($netLoad)),
                    'confidence' => 'medium'
                ];
            }

            // High load demand - assist with discharging
            if ($netLoad > 2.0 && $currentSOC > 25) {
                $maxDischargePower = (float) $this->option('charge-power'); // Use same limit
                $decision = [
                    'action' => BatteryInstruction::DISCHARGE,
                    'power' => min($maxDischargePower, $netLoad),
                    'duration' => 15,
                    'reason' => sprintf('Supporting high load (%.1fkW), price neutral', $netLoad),
                    'confidence' => 'medium'
                ];
            }
        }

        return $decision;
    }

    /**
     * Calculate optimal charging power targeting configured grid consumption
     */
    private function calculateOptimalChargePower(float $currentGridConsumption): float
    {
        $targetGridConsumption = (float) $this->option('target-grid');
        $maxChargePower = (float) $this->option('charge-power');
        return max(1.0, min($maxChargePower, $targetGridConsumption - $currentGridConsumption));
    }

    /**
     * Apply controller safety checks to planner decision
     */
    private function applyControllerSafetyChecks(array $plannerDecision, array $systemState): array
    {
        $finalDecision = $plannerDecision;

        if ($finalDecision['action'] === BatteryInstruction::DISCHARGE && $systemState['current_soc'] <= 10) {
            $finalDecision['action'] = BatteryInstruction::IDLE;
            $finalDecision['power'] = 0;
            $finalDecision['reason'] = 'Controller override: SOC too low for discharging';
            $finalDecision['confidence'] = 'high';
        }

        return $finalDecision;
    }

    /**
     * Display the final controller decision
     */
    private function displayFinalDecision(array $decision): void
    {
        $action = strtoupper($decision['action']->value);
        $actionColor = match($decision['action']) {
            BatteryInstruction::CHARGE => 'green',
            BatteryInstruction::DISCHARGE => 'red',
            BatteryInstruction::IDLE => 'gray',
            default => 'yellow'
        };

        $this->line("   🎯 Final Decision: <fg={$actionColor};options=bold>{$action}</>");

        if (isset($decision['power'])) {
            $this->line("   ⚡ Power: " . number_format($decision['power'], 1) . " kW");
        }

        $this->line("   🧠 Reason: " . ($decision['reason'] ?? 'N/A'));
        $this->line("   📊 Confidence: " . ($decision['confidence'] ?? 'medium'));

        // Show current price if available in decision
        if (isset($decision['current_price'])) {
            $this->line("   💡 Current price: " . number_format($decision['current_price'], 3) . " SEK/kWh");
        }
    }

    /**
     * Log optimization cycle to database
     */
    private function logOptimizationCycle(
        string $systemId,
        array $decision,
        array $systemState,
        array $allPrices,
        array $executionResult,
        bool $isDryRun
    ): BatteryHistory {
        $now = now();
        $intervalStart = $now->copy()->startOfHour()->addMinutes(floor($now->minute / 15) * 15);

        // Extract price information from decision (now handled by BatteryPlanner)
        $currentPrice = $decision['current_price'] ?? 0.50;
        $priceContext = $decision['price_context'] ?? [];
        $dailyAvgPrice = $priceContext['average_price'] ?? array_sum(array_column($allPrices, 'value')) / count($allPrices);

        // Determine price tier (simplified - BatteryPlanner should handle this)
        $priceTier = $this->determinePriceTier($currentPrice, $allPrices);

        $chargeTracking = $this->calculateCurrentChargeMetrics($systemId, $systemState['current_soc']);

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
    private function calculateCurrentChargeMetrics(string $systemId, float $currentSoc): array
    {
        // Get recent charge intervals to calculate weighted average cost
        $recentChargeIntervals = BatteryHistory::forSystem($systemId)
            ->where('action', BatteryInstruction::CHARGE)
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
        $action = strtoupper($decision['action']->value);
        $actionColor = match($decision['action']) {
            BatteryInstruction::CHARGE => 'green',
            BatteryInstruction::DISCHARGE => 'red',
            BatteryInstruction::IDLE => 'gray',
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
    private function displayResults(?BatteryHistory $record, array $executionResult, bool $isDryRun, ?string $systemId = null): void
    {
        $this->newLine();
        $this->info('📋 Cycle Summary');
        
        if ($record) {
            $this->line("   📝 History ID: {$record->id}");
            $this->line("   ⏰ Interval: " . $record->interval_start->format('H:i'));
            $this->line("   🎯 Action: " . strtoupper($record->action->value));
            $this->line("   💰 Price: " . number_format($record->price_sek_kwh, 3) . " SEK/kWh ({$record->price_tier} tier)");

            if ($record->cost_of_current_charge_sek) {
                $this->line("   💳 Cost in battery: " . number_format($record->cost_of_current_charge_sek, 2) . " SEK");
                $this->line("   📊 Avg charge price: " . number_format($record->avg_charge_price_sek_kwh, 3) . " SEK/kWh");
            }
        } else {
            $this->line("   📝 No database record (simulation mode)");
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
