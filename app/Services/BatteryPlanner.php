<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BatteryPlanner
{

    // 15-minute interval constants
    private const int INTERVAL_DURATION = 15; // minutes

    // Planning parameters
    private const int PLANNING_HORIZON_INTERVALS = 192; // 48 hours (2 days)

    /**
     * Generate a complete charge/discharge schedule for 15-minute intervals
     */
    public function generateSchedule(array $intervalPrices, float $currentSOC, ?Carbon $startTime = null): array
    {
        $startTime = $startTime ?? now();

        $this->validateInputs($intervalPrices, $currentSOC);

        $priceAnalysis = $this->analyzePricePatterns($intervalPrices);

        // Generate price-based schedule for each 15-minute interval
        $schedule = $this->createOptimal15MinuteSchedule($intervalPrices, $currentSOC, $priceAnalysis, $startTime);

        return [
            'schedule' => $schedule,
            'analysis' => $priceAnalysis,
            'generated_at' => now(),
            'valid_until' => $startTime->copy()->addMinutes(self::PLANNING_HORIZON_INTERVALS * self::INTERVAL_DURATION)
        ];
    }

    /**
     * Make immediate price-based decision for current 15-minute interval
     * Returns pure price analysis without operational constraints
     */
    public function makeImmediateDecision(array $intervalPrices, ?int $currentInterval = null, ?Carbon $currentTime = null): array {
        // If no interval specified, calculate from current time
        if ($currentInterval === null) {
            $currentTime = $currentTime ?? now();
            $currentInterval = $this->timeToInterval($currentTime);
        }

        // Ensure interval is valid
        if ($currentInterval < 0 || $currentInterval >= count($intervalPrices)) {
            $currentInterval = 0; // Default to first interval if calculation fails
        }

        $currentPrice = $intervalPrices[$currentInterval]['value'] ?? 0.50;

        // Use the same price analysis as generateSchedule
        $priceAnalysis = $this->analyzePricePatterns($intervalPrices);

        // Check if current interval is in charge or discharge windows (same logic as determineIntervalAction)
        $chargeWindows = $priceAnalysis['charge_windows'];
        $dischargeWindows = $priceAnalysis['discharge_windows'];

        $inChargeWindow = collect($chargeWindows)->contains('interval', $currentInterval);
        $inDischargeWindow = collect($dischargeWindows)->contains('interval', $currentInterval);

        if ($inChargeWindow) {
            // Find the specific charge window for details
            $chargeWindow = collect($chargeWindows)->firstWhere('interval', $currentInterval);
            $tier = $chargeWindow['tier'] ?? 'unknown';
            $savings = $chargeWindow['savings'] ?? 0;

            $decision = [
                'action' => 'charge',
                'reason' => sprintf("Charge window: %.3f SEK/kWh (%s tier, %.3f SEK savings)",
                                  $currentPrice, $tier, $savings),
            ];
        } elseif ($inDischargeWindow) {
            // Find the specific discharge window for details
            $dischargeWindow = collect($dischargeWindows)->firstWhere('interval', $currentInterval);
            $earnings = $dischargeWindow['earnings'] ?? 0;

            $decision = [
                'action' => 'discharge',
                'reason' => sprintf("Discharge window: %.3f SEK/kWh (expensive tier, %.3f SEK premium)",
                                  $currentPrice, $earnings),
            ];
        } else {
            // Not in any specific window - idle
            $decision = [
                'action' => 'idle',
                'reason' => sprintf("Idle: %.3f SEK/kWh (neutral tier)", $currentPrice),
            ];
        }

        // Add price context to decision
        $decision['current_price'] = $currentPrice;
        $decision['current_interval'] = $currentInterval;
        $decision['price_analysis'] = [
            'charge_opportunities' => count($chargeWindows),
            'discharge_opportunities' => count($dischargeWindows)
        ];

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
     * Create optimal schedule for 15-minute intervals based on pure price analysis
     */
    private function createOptimal15MinuteSchedule(
        array $intervalPrices,
        float $currentSOC,
        array $priceAnalysis,
        Carbon $startTime
    ): array {
        $schedule = [];

        // Create schedule for each 15-minute interval (pure price-based, no SOC simulation)
        $totalIntervals = min(count($intervalPrices), self::PLANNING_HORIZON_INTERVALS);

        for ($i = 0; $i < $totalIntervals; $i++) {
            $interval = $intervalPrices[$i];
            $price = $interval['value'];
            $timestamp = Carbon::parse($interval['time_start']);

            // Determine action based on pure price analysis
            $action = $this->determineIntervalAction($price, $currentSOC, $priceAnalysis, $i);

            $scheduleEntry = [
                'interval' => $i,
                'action' => $action,
                'start_time' => $timestamp,
                'end_time' => $timestamp->copy()->addMinutes(15),
                'price' => $price,
                'reason' => $this->getActionReason($action, $price, $priceAnalysis, $i)
            ];

            $schedule[] = $scheduleEntry;
        }

        return $schedule;
    }

    /**
     * Determine action for a specific 15-minute interval (pure price-based, consistent with makeImmediateDecision)
     */
    private function determineIntervalAction(float $price, float $currentSOC, array $priceAnalysis, int $intervalIndex): string
    {
        // Check if this interval is in charge or discharge windows (same logic as the new method)
        $chargeWindows = $priceAnalysis['charge_windows'];
        $dischargeWindows = $priceAnalysis['discharge_windows'];

        $inChargeWindow = collect($chargeWindows)->contains('interval', $intervalIndex);
        $inDischargeWindow = collect($dischargeWindows)->contains('interval', $intervalIndex);

        if ($inChargeWindow) {
            return 'charge';
        }

        if ($inDischargeWindow) {
            return 'discharge';
        }

        // Not in any specific window - default to idle
        return 'idle';
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
        // Ensure both times are in the same timezone to avoid negative calculations
        $timeInCorrectTz = $time->copy();
        $startOfDay = $timeInCorrectTz->copy()->startOfDay();
        
        // Calculate minutes since start of day in local time
        $minutesSinceStart = $startOfDay->diffInMinutes($timeInCorrectTz);
        
        return intval($minutesSinceStart / self::INTERVAL_DURATION);
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

        foreach ($intervalPrices as $index => $interval) {
            if (!isset($interval['value'])) {
                throw new \InvalidArgumentException("Missing price value at interval {$index}");
            }

            $price = $interval['value'];
            if (!is_numeric($price)) {
                throw new \InvalidArgumentException("Non-numeric price detected at interval {$index}: " . var_export($price, true));
            }

            // Allow negative prices (common with high renewable generation) and wide range for different units
            if ($price < -1000 || $price > 5000) {
                throw new \InvalidArgumentException("Price out of reasonable range at interval {$index}: {$price} (expected -1000 to 5000)");
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



}
