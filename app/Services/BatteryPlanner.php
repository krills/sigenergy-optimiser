<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BatteryPlanner
{
    // Configuration constants
    private const MIN_SOC = 10; // Never discharge below 20%
    private const MAX_SOC = 95; // Never charge above 95%
    private const BATTERY_CAPACITY = 8.0; // kWh (8kWh system)
    private const MAX_CHARGE_POWER = 3.0; // kW (per 15-minute interval: 0.75 kWh)
    private const MAX_DISCHARGE_POWER = 3.0; // kW (per 15-minute interval: 0.75 kWh)
    private const ROUND_TRIP_EFFICIENCY = 0.93; // 93% efficiency

    // 15-minute interval constants
    private const INTERVAL_DURATION = 15; // minutes
    private const INTERVALS_PER_HOUR = 4;
    private const INTERVALS_PER_DAY = 96;
    private const ENERGY_PER_INTERVAL = 0.75; // kWh (3kW * 0.25 hours)

    // Planning parameters
    private const PLANNING_HORIZON_INTERVALS = 192; // 48 hours (2 days)
    private const MIN_SESSION_INTERVALS = 4; // Minimum 1 hour sessions (4 x 15min)
    private const MAX_DAILY_CYCLES = 2; // Maximum 2 charge/discharge cycles per day

    /**
     * Generate a complete charge/discharge schedule for 15-minute intervals
     */
    public function generateSchedule(array $intervalPrices, float $currentSOC, ?Carbon $startTime = null): array
    {
        $startTime = $startTime ?? now();

        Log::info('BatteryPlanner: Generating 15-minute schedule', [
            'current_soc' => $currentSOC,
            'price_intervals' => count($intervalPrices),
            'start_time' => $startTime->format('Y-m-d H:i:s')
        ]);

        // Validate inputs
        $this->validateInputs($intervalPrices, $currentSOC);

        // Analyze price patterns with 15-minute granularity
        $priceAnalysis = $this->analyzePricePatterns($intervalPrices);

        // Generate optimized schedule for each 15-minute interval
        $schedule = $this->createOptimal15MinuteSchedule($intervalPrices, $currentSOC, $priceAnalysis, $startTime);

        // Validate and optimize schedule
        $optimizedSchedule = $this->optimizeSchedule($schedule, $currentSOC);

        Log::info('BatteryPlanner: 15-minute schedule generated', [
            'total_intervals' => count($optimizedSchedule),
            'charge_intervals' => count(array_filter($optimizedSchedule, fn($s) => $s['action'] === 'charge')),
            'discharge_intervals' => count(array_filter($optimizedSchedule, fn($s) => $s['action'] === 'discharge')),
            'idle_intervals' => count(array_filter($optimizedSchedule, fn($s) => $s['action'] === 'idle'))
        ]);

        return [
            'schedule' => $optimizedSchedule,
            'analysis' => $priceAnalysis,
            'summary' => $this->generateScheduleSummary($optimizedSchedule, $currentSOC),
            'generated_at' => now(),
            'valid_until' => $startTime->copy()->addMinutes(self::PLANNING_HORIZON_INTERVALS * self::INTERVAL_DURATION)
        ];
    }

    /**
     * Make immediate decision for current 15-minute interval
     */
    public function makeImmediateDecision(
        array $intervalPrices,
        float $currentSOC,
        float $currentSolarPower = 0,
        float $currentLoadPower = 0
    ): array {

        $now = now();
        $currentInterval = $this->timeToInterval($now);
        $currentPrice = $intervalPrices[$currentInterval]['value'] ?? 0.50;

        Log::info('BatteryPlanner: Making immediate 15-minute decision', [
            'current_soc' => $currentSOC,
            'current_price' => $currentPrice,
            'solar_power' => $currentSolarPower,
            'load_power' => $currentLoadPower,
            'interval' => $currentInterval,
            'time' => $now->format('Y-m-d H:i:s')
        ]);

        // Get next few intervals for context
        $nextIntervals = array_slice($intervalPrices, $currentInterval, 16); // Next 4 hours
        $priceContext = $this->analyzePriceContext($currentPrice, $nextIntervals, $intervalPrices);

        // Calculate net load (positive = need power, negative = excess power)
        $netLoad = $currentLoadPower - $currentSolarPower;

        // Make decision based on multiple factors
        $decision = $this->calculateImmediateAction(
            $currentSOC,
            $currentPrice,
            $netLoad,
            $priceContext
        );

        Log::info('BatteryPlanner: Immediate decision made', [
            'action' => $decision['action'],
            'power' => $decision['power'],
            'duration' => $decision['duration'],
            'reason' => $decision['reason']
        ]);

        return $decision;
    }

    /**
     * Analyze price patterns to identify optimal 15-minute charge/discharge windows
     */
    private function analyzePricePatterns(array $intervalPrices): array
    {
        $intervals = array_slice($intervalPrices, 0, min(count($intervalPrices), self::PLANNING_HORIZON_INTERVALS));
        $prices = array_column($intervals, 'value');

        $stats = [
            'min' => min($prices),
            'max' => max($prices),
            'avg' => array_sum($prices) / count($prices),
            'median' => $this->calculateMedian($prices),
            'std_dev' => $this->calculateStandardDeviation($prices)
        ];

        // New 3-tier pricing algorithm: Divide prices into thirds
        $sortedPrices = $prices;
        sort($sortedPrices);
        $totalIntervals = count($sortedPrices);
        
        // Calculate thresholds for 3-tier system
        $cheapestThird = $sortedPrices[intval($totalIntervals * 0.33)];  // 33rd percentile
        $middleThird = $sortedPrices[intval($totalIntervals * 0.67)];    // 67th percentile
        // Most expensive third starts above $middleThird
        
        // Identify charge and discharge windows based on 3-tier pricing
        $chargeWindows = [];
        $dischargeWindows = [];

        foreach ($intervals as $index => $interval) {
            $price = $interval['value'];
            $timestamp = Carbon::parse($interval['time_start']);
            $hour = (int) $timestamp->format('H');

            // Time-aware charging algorithm
            $shouldCharge = false;
            
            if ($price <= $middleThird) {
                $tier = $price <= $cheapestThird ? 'cheapest' : 'middle';
                
                // Time-based charging rules:
                // 1. Always charge during cheapest third (any time of day)
                // 2. Charge during middle third, but only before evening (18:00)
                // 3. After 18:00, only charge if price is in cheapest third
                if ($tier === 'cheapest') {
                    $shouldCharge = true; // Always charge during cheapest prices
                } elseif ($tier === 'middle' && $hour < 18) {
                    $shouldCharge = true; // Middle tier charging only before 18:00
                }
                
                if ($shouldCharge) {
                    $chargeWindows[] = [
                        'interval' => $index,
                        'price' => $price,
                        'start_time' => $timestamp,
                        'end_time' => $timestamp->copy()->addMinutes(15),
                        'savings' => $middleThird - $price,
                        'tier' => $tier,
                        'time_restricted' => ($tier === 'middle' && $hour >= 18),
                        'priority' => $this->calculatePriority($price, $stats, 'charge')
                    ];
                }
            }

            // Discharge only during most expensive third (33% of the day)
            if ($price > $middleThird) {
                $dischargeWindows[] = [
                    'interval' => $index,
                    'price' => $price,
                    'start_time' => $timestamp,
                    'end_time' => $timestamp->copy()->addMinutes(15),
                    'earnings' => $price - $middleThird, // How much more expensive than middle tier
                    'tier' => 'expensive',
                    'priority' => $this->calculatePriority($price, $stats, 'discharge')
                ];
            }
        }

        // Sort by priority (highest savings/earnings first)
        usort($chargeWindows, fn($a, $b) => $b['savings'] <=> $a['savings']);
        usort($dischargeWindows, fn($a, $b) => $b['earnings'] <=> $a['earnings']);

        return [
            'stats' => $stats,
            'charge_windows' => $chargeWindows,
            'discharge_windows' => $dischargeWindows,
            'total_intervals' => count($intervals),
            'charge_opportunities' => count($chargeWindows),
            'discharge_opportunities' => count($dischargeWindows),
            'price_volatility' => $this->calculateVolatility($prices),
            'price_tiers' => [
                'cheapest_threshold' => $cheapestThird,
                'middle_threshold' => $middleThird,
                'cheapest_tier' => [0, $cheapestThird],
                'middle_tier' => [$cheapestThird, $middleThird],
                'expensive_tier' => [$middleThird, $stats['max']]
            ]
        ];
    }

    /**
     * Create optimal schedule for 15-minute intervals based on price analysis
     */
    private function createOptimal15MinuteSchedule(
        array $intervalPrices,
        float $currentSOC,
        array $priceAnalysis,
        Carbon $startTime
    ): array {
        $schedule = [];
        $simulatedSOC = $currentSOC;

        // Create schedule for each 15-minute interval
        $totalIntervals = min(count($intervalPrices), self::PLANNING_HORIZON_INTERVALS);

        for ($i = 0; $i < $totalIntervals; $i++) {
            $interval = $intervalPrices[$i];
            $price = $interval['value'];
            $timestamp = Carbon::parse($interval['time_start']);

            // Determine action based on price analysis and current SOC
            $action = $this->determineIntervalAction($price, $simulatedSOC, $priceAnalysis, $i);

            $scheduleEntry = [
                'interval' => $i,
                'action' => $action,
                'start_time' => $timestamp,
                'end_time' => $timestamp->copy()->addMinutes(15),
                'price' => $price,
                'power' => $this->calculateIntervalPower($action),
                'energy_change' => $this->calculateEnergyChange($action),
                'target_soc' => $this->calculateTargetSOC($simulatedSOC, $action),
                'reason' => $this->getActionReason($action, $price, $priceAnalysis, $i)
            ];

            $schedule[] = $scheduleEntry;

            // Update simulated SOC for next interval
            $simulatedSOC = $scheduleEntry['target_soc'];
        }

        return $schedule;
    }

    /**
     * Determine action for a specific 15-minute interval
     */
    private function determineIntervalAction(float $price, float $currentSOC, array $priceAnalysis, int $intervalIndex): string
    {
        // Get pricing tiers from analysis
        $chargeWindows = $priceAnalysis['charge_windows'];
        $dischargeWindows = $priceAnalysis['discharge_windows'];
        
        // Check if current interval is in charge or discharge windows
        $inChargeWindow = collect($chargeWindows)->contains('interval', $intervalIndex);
        $inDischargeWindow = collect($dischargeWindows)->contains('interval', $intervalIndex);

        // Safety checks first
        if ($currentSOC <= self::MIN_SOC) {
            return 'charge'; // Emergency charge
        }

        if ($currentSOC >= self::MAX_SOC) {
            return 'idle'; // Cannot charge more
        }

        // New 3-tier algorithm: Charge during cheapest 2/3 of intervals
        if ($inChargeWindow) {
            // Price is in cheapest or middle tier - good time to charge
            if ($currentSOC < 85) { // Only charge if there's room
                return 'charge';
            }
        }

        // Discharge only during most expensive third
        if ($inDischargeWindow) {
            // Price is in most expensive tier - good time to discharge
            if ($currentSOC > 30) { // Only discharge if we have energy
                return 'discharge';
            }
        }

        // Price is not optimal or SOC limits prevent action
        return 'idle';
    }

    /**
     * Calculate power for a given action in 15-minute interval
     */
    private function calculateIntervalPower(string $action): float
    {
        switch ($action) {
            case 'charge':
                return self::MAX_CHARGE_POWER;
            case 'discharge':
                return self::MAX_DISCHARGE_POWER;
            case 'idle':
            default:
                return 0.0;
        }
    }

    /**
     * Calculate energy change for a 15-minute interval action
     */
    private function calculateEnergyChange(string $action): float
    {
        switch ($action) {
            case 'charge':
                return self::ENERGY_PER_INTERVAL; // 0.75 kWh per 15 minutes at 3kW
            case 'discharge':
                return self::ENERGY_PER_INTERVAL; // 0.75 kWh per 15 minutes at 3kW
            case 'idle':
            default:
                return 0.0;
        }
    }

    /**
     * Calculate target SOC after interval action
     */
    private function calculateTargetSOC(float $currentSOC, string $action): float
    {
        $energyChange = $this->calculateEnergyChange($action);
        $socChange = ($energyChange / self::BATTERY_CAPACITY) * 100;

        switch ($action) {
            case 'charge':
                return min(self::MAX_SOC, $currentSOC + ($socChange * self::ROUND_TRIP_EFFICIENCY));
            case 'discharge':
                return max(self::MIN_SOC, $currentSOC - $socChange);
            case 'idle':
            default:
                return $currentSOC;
        }
    }

    /**
     * Get human-readable reason for action
     */
    private function getActionReason(string $action, float $price, array $priceAnalysis, int $intervalIndex): string
    {
        $stats = $priceAnalysis['stats'];
        $chargeWindows = $priceAnalysis['charge_windows'];
        $dischargeWindows = $priceAnalysis['discharge_windows'];
        
        // Find which tier this interval belongs to
        $chargeWindow = collect($chargeWindows)->firstWhere('interval', $intervalIndex);
        $dischargeWindow = collect($dischargeWindows)->firstWhere('interval', $intervalIndex);

        // Get time context for reasoning
        $interval = collect($priceAnalysis['charge_windows'] ?? [])->firstWhere('interval', $intervalIndex) 
                 ?? collect($priceAnalysis['discharge_windows'] ?? [])->firstWhere('interval', $intervalIndex);
        $hour = null;
        if ($interval && isset($interval['start_time'])) {
            $hour = (int) $interval['start_time']->format('H');
        }

        switch ($action) {
            case 'charge':
                if ($chargeWindow) {
                    $tier = $chargeWindow['tier'];
                    $savings = $chargeWindow['savings'];
                    $tierDesc = $tier === 'cheapest' ? 'cheapest third' : 'middle third';
                    
                    if ($tier === 'cheapest') {
                        return sprintf('Charging: %.3f SEK/kWh (%s tier, %.3f SEK savings)',
                                      $price, $tierDesc, $savings);
                    } else {
                        $timeDesc = $hour !== null && $hour >= 18 ? ' (pre-evening)' : '';
                        return sprintf('Charging: %.3f SEK/kWh (%s tier%s, %.3f SEK savings)',
                                      $price, $tierDesc, $timeDesc, $savings);
                    }
                }
                return sprintf('Emergency charge: %.3f SEK/kWh', $price);
            case 'discharge':
                if ($dischargeWindow) {
                    $earnings = $dischargeWindow['earnings'];
                    return sprintf('Discharging: %.3f SEK/kWh (expensive tier, %.3f SEK premium)',
                                  $price, $earnings);
                }
                return sprintf('Discharging: %.3f SEK/kWh', $price);
            case 'idle':
            default:
                if ($chargeWindow) {
                    return sprintf('Idle: %.3f SEK/kWh (charging tier but SOC limit reached)', $price);
                } elseif ($dischargeWindow) {
                    return sprintf('Idle: %.3f SEK/kWh (discharge tier but insufficient SOC)', $price);
                } else {
                    // Check if it's evening middle tier (time-restricted)
                    $sortedPrices = array_column($priceAnalysis['charge_windows'] ?? [], 'price');
                    $sortedPrices = array_merge($sortedPrices, array_column($priceAnalysis['discharge_windows'] ?? [], 'price'));
                    sort($sortedPrices);
                    $totalIntervals = count($sortedPrices);
                    $cheapestThird = $sortedPrices[intval($totalIntervals * 0.33)] ?? 0;
                    $middleThird = $sortedPrices[intval($totalIntervals * 0.67)] ?? 0;
                    
                    if ($price <= $middleThird && $price > $cheapestThird && $hour !== null && $hour >= 18) {
                        return sprintf('Idle: %.3f SEK/kWh (middle tier but evening - only cheapest tier charges)', $price);
                    }
                    return sprintf('Idle: %.3f SEK/kWh (neutral tier)', $price);
                }
        }
    }

    /**
     * Convert time to 15-minute interval index
     */
    private function timeToInterval(Carbon $time): int
    {
        $startOfDay = $time->copy()->startOfDay();
        $minutesSinceStart = $time->diffInMinutes($startOfDay);
        return intval($minutesSinceStart / self::INTERVAL_DURATION);
    }

    /**
     * Calculate immediate action for quarter-hour execution
     */
    private function calculateImmediateAction(
        float $currentSOC,
        float $currentPrice,
        float $netLoad,
        array $priceContext
    ): array {

        // Default action
        $action = [
            'action' => 'idle',
            'power' => 0,
            'duration' => 15, // minutes
            'reason' => 'No action needed',
            'confidence' => 'medium'
        ];

        // Emergency situations first
        if ($currentSOC <= self::MIN_SOC) {
            return [
                'action' => 'charge',
                'power' => self::MAX_CHARGE_POWER,
                'duration' => 15,
                'reason' => 'Emergency charge - SOC critically low',
                'confidence' => 'high'
            ];
        }

        if ($currentSOC >= self::MAX_SOC) {
            if ($netLoad > 0) {
                return [
                    'action' => 'discharge',
                    'power' => min(self::MAX_DISCHARGE_POWER, $netLoad),
                    'duration' => 15,
                    'reason' => 'SOC full, provide load power',
                    'confidence' => 'high'
                ];
            }
        }

        // Price-based decisions for 15-minute intervals
        $avgPrice = $priceContext['average_price'] ?? 0.50;

        if ($currentPrice < $avgPrice * 0.8 && $currentSOC < 80) {
            return [
                'action' => 'charge',
                'power' => self::MAX_CHARGE_POWER,
                'duration' => 15,
                'reason' => sprintf("Very cheap price: %.3f SEK/kWh (%.1f%% below average)",
                                  $currentPrice, (($avgPrice - $currentPrice) / $avgPrice) * 100),
                'confidence' => 'high'
            ];
        }

        if ($currentPrice > $avgPrice * 1.2 && $currentSOC > 30) {
            return [
                'action' => 'discharge',
                'power' => self::MAX_DISCHARGE_POWER,
                'duration' => 15,
                'reason' => sprintf("Very expensive price: %.3f SEK/kWh (%.1f%% above average)",
                                  $currentPrice, (($currentPrice - $avgPrice) / $avgPrice) * 100),
                'confidence' => 'high'
            ];
        }

        // Load balancing for 15-minute interval
        if (abs($netLoad) > 1.0) {
            if ($netLoad > 0 && $currentSOC > 25) {
                return [
                    'action' => 'discharge',
                    'power' => min(self::MAX_DISCHARGE_POWER, $netLoad),
                    'duration' => 15,
                    'reason' => 'Provide load balancing power',
                    'confidence' => 'medium'
                ];
            }

            if ($netLoad < -1.0 && $currentSOC < 85) {
                return [
                    'action' => 'charge',
                    'power' => min(self::MAX_CHARGE_POWER, abs($netLoad)),
                    'duration' => 15,
                    'reason' => 'Absorb excess solar power',
                    'confidence' => 'medium'
                ];
            }
        }

        return $action;
    }

    /**
     * Analyze price context for immediate decisions
     */
    private function analyzePriceContext(float $currentPrice, array $nextIntervals, array $allPrices): array
    {
        $allPriceValues = array_column($allPrices, 'value');
        $nextPriceValues = array_column($nextIntervals, 'value');

        $context = [
            'is_local_minimum' => false,
            'is_local_maximum' => false,
            'trend' => 'stable',
            'volatility' => 'low',
            'average_price' => array_sum($allPriceValues) / count($allPriceValues)
        ];

        if (count($nextPriceValues) >= 4) {
            $avgNext4 = array_sum(array_slice($nextPriceValues, 1, 4)) / 4;

            // Check if current price is significantly lower/higher than next intervals
            $context['is_local_minimum'] = $currentPrice < $avgNext4 * 0.95;
            $context['is_local_maximum'] = $currentPrice > $avgNext4 * 1.05;

            // Trend analysis
            if ($avgNext4 > $currentPrice * 1.1) {
                $context['trend'] = 'increasing';
            } elseif ($avgNext4 < $currentPrice * 0.9) {
                $context['trend'] = 'decreasing';
            }
        }

        // Overall volatility
        $priceStd = $this->calculateStandardDeviation($allPriceValues);
        $priceAvg = $context['average_price'];
        $volatilityRatio = $priceStd / $priceAvg;

        if ($volatilityRatio > 0.3) {
            $context['volatility'] = 'high';
        } elseif ($volatilityRatio > 0.15) {
            $context['volatility'] = 'medium';
        }

        return $context;
    }

    // Helper methods
    private function validateInputs(array $intervalPrices, float $currentSOC): void
    {
        if (empty($intervalPrices)) {
            throw new \InvalidArgumentException('Interval prices array cannot be empty');
        }

        if ($currentSOC < 0 || $currentSOC > 100) {
            throw new \InvalidArgumentException('Current SOC must be between 0 and 100');
        }

        foreach ($intervalPrices as $interval) {
            if (!isset($interval['value']) || $interval['value'] < 0 || $interval['value'] > 10) {
                throw new \InvalidArgumentException('Invalid price detected in interval data');
            }
        }
    }

    private function calculateMedian(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }
        return $values[$middle];
    }

    private function calculateVolatility(array $prices): float
    {
        $mean = array_sum($prices) / count($prices);
        $variance = array_sum(array_map(fn($p) => pow($p - $mean, 2), $prices)) / count($prices);
        return sqrt($variance);
    }

    private function calculateStandardDeviation(array $values): float
    {
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn($v) => pow($v - $mean, 2), $values)) / count($values);
        return sqrt($variance);
    }

    private function calculatePriority(float $price, array $stats, string $action): float
    {
        if ($action === 'charge') {
            // Lower prices = higher priority
            $maxSavings = $stats['avg'] - $stats['min'];
            $actualSavings = $stats['avg'] - $price;
            return max(0, ($actualSavings / $maxSavings)) * 100;
        } else {
            // Higher prices = higher priority
            $maxEarnings = $stats['max'] - $stats['avg'];
            $actualEarnings = $price - $stats['avg'];
            return max(0, ($actualEarnings / $maxEarnings)) * 100;
        }
    }

    private function optimizeSchedule(array $schedule, float $currentSOC): array
    {
        // Remove conflicting sessions and ensure SOC constraints
        $optimized = [];
        $simulatedSOC = $currentSOC;

        foreach ($schedule as $session) {
            // Check if session is still valid given current SOC
            if ($session['action'] === 'charge' && $simulatedSOC >= self::MAX_SOC * 0.98) {
                // Skip charging if nearly full
                $session['action'] = 'idle';
                $session['power'] = 0;
                $session['energy_change'] = 0;
                $session['target_soc'] = $simulatedSOC;
                $session['reason'] = 'Skipped charge - SOC near maximum';
            }

            if ($session['action'] === 'discharge' && $simulatedSOC <= self::MIN_SOC * 1.02) {
                // Skip discharging if nearly empty
                $session['action'] = 'idle';
                $session['power'] = 0;
                $session['energy_change'] = 0;
                $session['target_soc'] = $simulatedSOC;
                $session['reason'] = 'Skipped discharge - SOC near minimum';
            }

            $optimized[] = $session;

            // Update simulated SOC
            $simulatedSOC = $session['target_soc'];
        }

        return $optimized;
    }

    private function generateScheduleSummary(array $schedule, float $currentSOC): array
    {
        $chargeIntervals = array_filter($schedule, fn($s) => $s['action'] === 'charge');
        $dischargeIntervals = array_filter($schedule, fn($s) => $s['action'] === 'discharge');
        $idleIntervals = array_filter($schedule, fn($s) => $s['action'] === 'idle');

        // Calculate total energy and financial impact
        $totalChargeEnergy = count($chargeIntervals) * self::ENERGY_PER_INTERVAL;
        $totalDischargeEnergy = count($dischargeIntervals) * self::ENERGY_PER_INTERVAL;

        $totalSavings = array_sum(array_map(function($interval) {
            return $interval['energy_change'] * ($interval['savings'] ?? 0);
        }, $chargeIntervals));

        $totalEarnings = array_sum(array_map(function($interval) {
            return $interval['energy_change'] * ($interval['earnings'] ?? 0) * self::ROUND_TRIP_EFFICIENCY;
        }, $dischargeIntervals));

        return [
            'total_intervals' => count($schedule),
            'charge_intervals' => count($chargeIntervals),
            'discharge_intervals' => count($dischargeIntervals),
            'idle_intervals' => count($idleIntervals),
            'charge_hours' => count($chargeIntervals) * 0.25, // 15 minutes = 0.25 hours
            'discharge_hours' => count($dischargeIntervals) * 0.25,
            'total_charge_energy' => $totalChargeEnergy,
            'total_discharge_energy' => $totalDischargeEnergy,
            'estimated_savings' => $totalSavings,
            'estimated_earnings' => $totalEarnings,
            'net_benefit' => $totalEarnings + $totalSavings,
            'starting_soc' => $currentSOC,
            'efficiency_utilized' => self::ROUND_TRIP_EFFICIENCY
        ];
    }
}
