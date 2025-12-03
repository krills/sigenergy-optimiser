<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BatteryOptimizationService
{
    private SigenEnergyApiService $sigenergy;
    private ElectricityPriceAggregator $priceAggregator;
    
    // Optimization thresholds (SEK/kWh)
    private const VERY_LOW_PRICE = 0.05;
    private const LOW_PRICE = 0.30;
    private const HIGH_PRICE = 0.80;
    private const VERY_HIGH_PRICE = 1.20;
    
    // Battery constraints
    private const MIN_SOC = 20; // Never discharge below 20%
    private const MAX_SOC = 95; // Never charge above 95%
    private const SAFE_CHARGE_POWER = 3.0; // kW
    private const SAFE_DISCHARGE_POWER = 3.0; // kW
    
    public function __construct()
    {
        $this->sigenergy = new SigenEnergyApiService();
        $this->priceAggregator = new ElectricityPriceAggregator();
    }

    /**
     * Main optimization algorithm - analyzes current conditions and executes optimal strategy
     */
    public function optimizeSystem(string $systemId): array
    {
        Log::info('Starting battery optimization', ['system_id' => $systemId]);
        
        // Get current system status
        $systemStatus = $this->sigenergy->getSystemRealtimeData($systemId);
        $energyFlow = $this->sigenergy->getSystemEnergyFlow($systemId);
        $telemetryData = $this->sigenergy->getSystemTelemetryData($systemId);
        
        if (!$systemStatus || !$energyFlow || !$telemetryData) {
            throw new \Exception('Failed to retrieve system data');
        }
        
        // Get current and upcoming electricity prices using aggregator
        $currentPrice = $this->priceAggregator->getCurrentPrice();
        $hourlyPrices = $this->priceAggregator->getNext24HourPrices();
        $tomorrowPrices = []; // Will be included in hourlyPrices
        
        // Analyze system state
        $analysis = $this->analyzeSystemState($systemStatus, $energyFlow, $telemetryData);
        
        // Generate optimization decision
        $decision = $this->generateOptimizationDecision(
            $analysis,
            $currentPrice,
            $hourlyPrices,
            $hourlyPrices // Use same array for compatibility
        );
        
        // Execute optimization commands
        $result = $this->executeOptimization($systemId, $decision, $analysis);
        
        Log::info('Optimization completed', [
            'system_id' => $systemId,
            'decision' => $decision,
            'result' => $result
        ]);
        
        return [
            'success' => true,
            'analysis' => $analysis,
            'decision' => $decision,
            'result' => $result,
            'timestamp' => now()
        ];
    }

    /**
     * Analyze current system state and constraints
     */
    private function analyzeSystemState(array $systemStatus, array $energyFlow, array $telemetryData): array
    {
        // Extract key metrics
        $soc = $energyFlow['batterySoc'] ?? 0;
        $pvPower = $energyFlow['pvPower'] ?? 0;
        $gridPower = $energyFlow['gridPower'] ?? 0;
        $loadPower = $energyFlow['loadPower'] ?? 0;
        $batteryPower = $energyFlow['batteryPower'] ?? 0;
        
        // System constraints from telemetry
        $maxChargePower = ($telemetryData['batteryMaxChargePowerW'] ?? 3000) / 1000; // Convert to kW
        $maxDischargePower = ($telemetryData['batteryMaxDischargePowerW'] ?? 3000) / 1000;
        $batteryCapacity = ($telemetryData['batteryRatedCapabilityWh'] ?? 8000) / 1000; // Convert to kWh
        $systemStatus = $telemetryData['systemStatus'] ?? 'unknown';
        
        // Calculate available capacity
        $availableChargeCapacity = ($batteryCapacity * (self::MAX_SOC - $soc)) / 100;
        $availableDischargeCapacity = ($batteryCapacity * ($soc - self::MIN_SOC)) / 100;
        
        // Determine current energy balance
        $netLoad = $loadPower - $pvPower; // Positive = importing, Negative = exporting
        
        return [
            'soc' => $soc,
            'pvPower' => $pvPower,
            'gridPower' => $gridPower,
            'loadPower' => $loadPower,
            'batteryPower' => $batteryPower,
            'netLoad' => $netLoad,
            'maxChargePower' => min($maxChargePower, self::SAFE_CHARGE_POWER),
            'maxDischargePower' => min($maxDischargePower, self::SAFE_DISCHARGE_POWER),
            'batteryCapacity' => $batteryCapacity,
            'availableChargeCapacity' => max(0, $availableChargeCapacity),
            'availableDischargeCapacity' => max(0, $availableDischargeCapacity),
            'systemStatus' => $systemStatus,
            'canCharge' => $soc < self::MAX_SOC && $systemStatus === 'running',
            'canDischarge' => $soc > self::MIN_SOC && $systemStatus === 'running',
            'isExportingSolar' => $pvPower > $loadPower && $gridPower < 0,
            'isImportingFromGrid' => $gridPower > 0
        ];
    }

    /**
     * Generate optimization decision based on prices and system state
     */
    private function generateOptimizationDecision(
        array $analysis,
        float $currentPrice,
        array $hourlyPrices,
        array $tomorrowPrices
    ): array {
        // Find price patterns
        $priceAnalysis = $this->analyzePricePatterns($currentPrice, $hourlyPrices, $tomorrowPrices);
        
        // Decision matrix based on Stockholm optimization strategy
        $decision = $this->applyOptimizationMatrix(
            $analysis,
            $currentPrice,
            $priceAnalysis
        );
        
        // Add scheduling for upcoming price changes
        $schedule = $this->planFutureOptimizations($analysis, $hourlyPrices, $tomorrowPrices);
        
        return array_merge($decision, [
            'currentPrice' => $currentPrice,
            'priceAnalysis' => $priceAnalysis,
            'schedule' => $schedule
        ]);
    }

    /**
     * Apply Stockholm optimization strategy matrix
     */
    private function applyOptimizationMatrix(array $analysis, float $currentPrice, array $priceAnalysis): array
    {
        $soc = $analysis['soc'];
        $pvPower = $analysis['pvPower'];
        $netLoad = $analysis['netLoad'];
        $isHighSolar = $pvPower > 2.0; // kW
        $isMediumSolar = $pvPower > 0.5 && $pvPower <= 2.0;
        $isLowSolar = $pvPower <= 0.5;
        
        // Price-based decisions with solar considerations
        if ($currentPrice <= self::VERY_LOW_PRICE) {
            // VERY LOW PRICE: Force charge from grid regardless of solar
            return [
                'mode' => 'charge',
                'reason' => 'Very cheap electricity - force charge from grid',
                'chargingPower' => $analysis['maxChargePower'],
                'duration' => 3600, // 1 hour
                'priority' => 'grid_charge'
            ];
        }
        
        if ($currentPrice <= self::LOW_PRICE) {
            // LOW PRICE: Charge mode with grid supplement
            if ($soc < 80) {
                return [
                    'mode' => 'charge',
                    'reason' => 'Low price + SOC below 80% - charge with grid supplement',
                    'chargingPower' => $analysis['maxChargePower'],
                    'duration' => 2 * 3600, // 2 hours
                    'gridPurchasingPowerLimit' => 2.0 // Limit grid import
                ];
            } else {
                return [
                    'mode' => 'selfConsumption',
                    'reason' => 'Low price but battery near full - maintain self consumption',
                    'duration' => 3600
                ];
            }
        }
        
        if ($currentPrice >= self::VERY_HIGH_PRICE) {
            // VERY HIGH PRICE: Maximize battery discharge and solar export
            if ($isHighSolar) {
                return [
                    'mode' => 'selfConsumption-grid',
                    'reason' => 'Very high price + high solar - export solar, use battery for home',
                    'dischargingPower' => $analysis['maxDischargePower'],
                    'duration' => 3600,
                    'maxExportPower' => 5.0 // Maximize solar export
                ];
            } else {
                return [
                    'mode' => 'discharge',
                    'reason' => 'Very high price + low solar - use battery to avoid grid import',
                    'dischargingPower' => min($analysis['maxDischargePower'], $netLoad),
                    'duration' => 3600
                ];
            }
        }
        
        if ($currentPrice >= self::HIGH_PRICE) {
            // HIGH PRICE: Avoid grid import, use battery and solar
            if ($isHighSolar) {
                return [
                    'mode' => 'selfConsumption-grid',
                    'reason' => 'High price + high solar - prioritize solar export',
                    'maxExportPower' => 4.0
                ];
            } else {
                return [
                    'mode' => 'discharge',
                    'reason' => 'High price + low solar - use battery to avoid grid costs',
                    'dischargingPower' => min($analysis['maxDischargePower'], max(0, $netLoad)),
                    'duration' => 3600
                ];
            }
        }
        
        // MEDIUM PRICE: Intelligent self-consumption based on solar and SOC
        if ($isHighSolar) {
            if ($soc < 60) {
                return [
                    'mode' => 'selfConsumption',
                    'reason' => 'Medium price + high solar + low SOC - prioritize battery charging',
                    'chargingPower' => min($analysis['maxChargePower'], $pvPower - $netLoad)
                ];
            } else {
                return [
                    'mode' => 'selfConsumption-grid',
                    'reason' => 'Medium price + high solar + good SOC - prioritize solar export',
                    'maxExportPower' => 3.0
                ];
            }
        } else {
            if ($soc > 40) {
                return [
                    'mode' => 'selfConsumption',
                    'reason' => 'Medium price + low solar + good SOC - maintain self consumption',
                    'duration' => 1800 // 30 minutes
                ];
            } else {
                // Low SOC and low solar - prepare for potential price increases
                if ($priceAnalysis['nextHourTrend'] === 'increasing') {
                    return [
                        'mode' => 'charge',
                        'reason' => 'Medium price but prices increasing - charge now',
                        'chargingPower' => $analysis['maxChargePower'] * 0.7,
                        'duration' => 1800
                    ];
                } else {
                    return [
                        'mode' => 'selfConsumption',
                        'reason' => 'Medium price + low SOC - maintain current state',
                        'duration' => 1800
                    ];
                }
            }
        }
    }

    /**
     * Analyze price patterns for trend prediction
     */
    private function analyzePricePatterns(float $currentPrice, array $hourlyPrices, array $tomorrowPrices): array
    {
        $allPrices = array_merge($hourlyPrices, $tomorrowPrices);
        
        // Find min/max prices in next 24 hours
        $minPrice = min($allPrices);
        $maxPrice = max($allPrices);
        $avgPrice = array_sum($allPrices) / count($allPrices);
        
        // Determine current price position
        $pricePercentile = ($currentPrice - $minPrice) / ($maxPrice - $minPrice) * 100;
        
        // Analyze next hour trend
        $nextHourPrice = $hourlyPrices[1] ?? $currentPrice;
        $nextHourTrend = $nextHourPrice > $currentPrice ? 'increasing' : 'decreasing';
        
        // Find optimal charge/discharge windows
        $chargeWindows = [];
        $dischargeWindows = [];
        
        foreach ($allPrices as $index => $price) {
            if ($price <= self::LOW_PRICE) {
                $chargeWindows[] = [
                    'hour' => $index,
                    'price' => $price,
                    'savings' => $avgPrice - $price
                ];
            }
            
            if ($price >= self::HIGH_PRICE) {
                $dischargeWindows[] = [
                    'hour' => $index,
                    'price' => $price,
                    'earnings' => $price - $avgPrice
                ];
            }
        }
        
        return [
            'minPrice' => $minPrice,
            'maxPrice' => $maxPrice,
            'avgPrice' => $avgPrice,
            'pricePercentile' => $pricePercentile,
            'nextHourTrend' => $nextHourTrend,
            'chargeWindows' => $chargeWindows,
            'dischargeWindows' => $dischargeWindows,
            'priceCategory' => $this->categorizePriceLevel($currentPrice)
        ];
    }

    /**
     * Plan future optimizations based on upcoming prices
     */
    private function planFutureOptimizations(array $analysis, array $hourlyPrices, array $tomorrowPrices): array
    {
        $schedule = [];
        $allPrices = array_merge($hourlyPrices, $tomorrowPrices);
        
        // Look ahead for significant price changes
        $now = Carbon::now();
        
        foreach ($allPrices as $hour => $price) {
            $futureTime = $now->copy()->addHours($hour);
            
            // Schedule charging during very low prices
            if ($price <= self::VERY_LOW_PRICE && $analysis['canCharge']) {
                $schedule[] = [
                    'time' => $futureTime->timestamp,
                    'action' => 'charge',
                    'mode' => 'charge',
                    'power' => $analysis['maxChargePower'],
                    'duration' => 3600,
                    'reason' => "Very low price: {$price} SEK/kWh",
                    'priority' => 'high'
                ];
            }
            
            // Schedule discharging during very high prices
            if ($price >= self::VERY_HIGH_PRICE && $analysis['canDischarge']) {
                $schedule[] = [
                    'time' => $futureTime->timestamp,
                    'action' => 'discharge',
                    'mode' => 'discharge', 
                    'power' => $analysis['maxDischargePower'],
                    'duration' => 3600,
                    'reason' => "Very high price: {$price} SEK/kWh",
                    'priority' => 'high'
                ];
            }
        }
        
        // Sort by priority and time
        usort($schedule, function($a, $b) {
            if ($a['priority'] === $b['priority']) {
                return $a['time'] - $b['time'];
            }
            return $a['priority'] === 'high' ? -1 : 1;
        });
        
        return array_slice($schedule, 0, 10); // Limit to next 10 scheduled actions
    }

    /**
     * Execute the optimization decision
     */
    private function executeOptimization(string $systemId, array $decision, array $analysis): array
    {
        // Prepare command parameters
        $command = [
            'systemId' => $systemId,
            'activeMode' => $decision['mode'],
            'startTime' => time(),
            'duration' => $decision['duration'] ?? 3600
        ];
        
        // Add power limits based on decision
        if (isset($decision['chargingPower'])) {
            $command['chargingPower'] = min($decision['chargingPower'], $analysis['maxChargePower']);
        }
        
        if (isset($decision['dischargingPower'])) {
            $command['dischargingPower'] = min($decision['dischargingPower'], $analysis['maxDischargePower']);
        }
        
        if (isset($decision['maxExportPower'])) {
            $command['maxExportPower'] = $decision['maxExportPower'];
        }
        
        if (isset($decision['gridPurchasingPowerLimit'])) {
            $command['maxImportPower'] = $decision['gridPurchasingPowerLimit'];
        }
        
        // Execute the command
        Log::info('Executing battery optimization command', $command);
        
        try {
            $result = $this->sigenergy->scheduleBatteryOptimization(
                $systemId,
                $decision['mode'],
                $command['startTime'],
                $command['duration'] / 3600, // Convert to hours
                $command
            );
            
            return [
                'command_sent' => true,
                'command' => $command,
                'api_result' => $result,
                'executed_at' => now()
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to execute optimization command', [
                'command' => $command,
                'error' => $e->getMessage()
            ]);
            
            return [
                'command_sent' => false,
                'error' => $e->getMessage(),
                'command' => $command
            ];
        }
    }

    /**
     * Categorize price level for easy reference
     */
    private function categorizePriceLevel(float $price): string
    {
        if ($price <= self::VERY_LOW_PRICE) return 'very_low';
        if ($price <= self::LOW_PRICE) return 'low';
        if ($price >= self::VERY_HIGH_PRICE) return 'very_high';
        if ($price >= self::HIGH_PRICE) return 'high';
        return 'medium';
    }

    /**
     * Get optimization recommendations without executing
     */
    public function getOptimizationRecommendations(string $systemId): array
    {
        $systemStatus = $this->sigenergy->getSystemRealtimeData($systemId);
        $energyFlow = $this->sigenergy->getSystemEnergyFlow($systemId);
        $telemetryData = $this->sigenergy->getSystemTelemetryData($systemId);
        
        $currentPrice = $this->priceAggregator->getCurrentPrice();
        $hourlyPrices = $this->priceAggregator->getNext24HourPrices();
        $tomorrowPrices = []; // Included in hourlyPrices
        
        $analysis = $this->analyzeSystemState($systemStatus, $energyFlow, $telemetryData);
        $decision = $this->generateOptimizationDecision(
            $analysis,
            $currentPrice,
            $hourlyPrices,
            $hourlyPrices // Use same array for compatibility
        );
        
        return [
            'analysis' => $analysis,
            'decision' => $decision,
            'execution' => false,
            'timestamp' => now()
        ];
    }
}