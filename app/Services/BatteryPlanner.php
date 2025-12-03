<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BatteryPlanner
{
    // Configuration constants
    private const MIN_SOC = 20; // Never discharge below 20%
    private const MAX_SOC = 95; // Never charge above 95%
    private const BATTERY_CAPACITY = 8.0; // kWh (8kWh system)
    private const MAX_CHARGE_POWER = 3.0; // kW
    private const MAX_DISCHARGE_POWER = 3.0; // kW
    private const ROUND_TRIP_EFFICIENCY = 0.93; // 93% efficiency
    
    // Price thresholds (SEK/kWh)
    private const VERY_CHEAP_THRESHOLD = 0.10;
    private const CHEAP_THRESHOLD = 0.30;
    private const EXPENSIVE_THRESHOLD = 0.70;
    private const VERY_EXPENSIVE_THRESHOLD = 1.20;
    
    // Planning parameters
    private const PLANNING_HORIZON_HOURS = 48; // Plan for next 48 hours
    private const MIN_SESSION_DURATION = 1; // Minimum 1 hour sessions
    private const MAX_DAILY_CYCLES = 2; // Maximum 2 charge/discharge cycles per day

    /**
     * Generate a complete charge/discharge schedule for the planning horizon
     */
    public function generateSchedule(array $hourlyPrices, float $currentSOC, Carbon $startTime = null): array
    {
        $startTime = $startTime ?? now();
        
        Log::info('BatteryPlanner: Generating schedule', [
            'current_soc' => $currentSOC,
            'price_points' => count($hourlyPrices),
            'start_time' => $startTime->format('Y-m-d H:i:s')
        ]);
        
        // Validate inputs
        $this->validateInputs($hourlyPrices, $currentSOC);
        
        // Analyze price patterns
        $priceAnalysis = $this->analyzePricePatterns($hourlyPrices);
        
        // Generate schedule
        $schedule = $this->createOptimalSchedule($hourlyPrices, $currentSOC, $priceAnalysis, $startTime);
        
        // Validate and optimize schedule
        $optimizedSchedule = $this->optimizeSchedule($schedule, $currentSOC);
        
        Log::info('BatteryPlanner: Schedule generated', [
            'total_actions' => count($optimizedSchedule),
            'charge_sessions' => count(array_filter($optimizedSchedule, fn($s) => $s['action'] === 'charge')),
            'discharge_sessions' => count(array_filter($optimizedSchedule, fn($s) => $s['action'] === 'discharge'))
        ]);
        
        return [
            'schedule' => $optimizedSchedule,
            'analysis' => $priceAnalysis,
            'summary' => $this->generateScheduleSummary($optimizedSchedule, $currentSOC),
            'generated_at' => now(),
            'valid_until' => $startTime->copy()->addHours(self::PLANNING_HORIZON_HOURS)
        ];
    }

    /**
     * Make immediate decision for current quarter-hour based on current conditions
     */
    public function makeImmediateDecision(
        array $hourlyPrices, 
        float $currentSOC, 
        float $currentSolarPower = 0,
        float $currentLoadPower = 0
    ): array {
        
        $now = now();
        $currentHour = $now->hour;
        $currentPrice = $hourlyPrices[$currentHour] ?? 0.50;
        
        Log::info('BatteryPlanner: Making immediate decision', [
            'current_soc' => $currentSOC,
            'current_price' => $currentPrice,
            'solar_power' => $currentSolarPower,
            'load_power' => $currentLoadPower,
            'hour' => $currentHour
        ]);
        
        // Get next few hours for context
        $nextHours = array_slice($hourlyPrices, $currentHour, 6);
        $priceContext = $this->analyzePriceContext($currentPrice, $nextHours, $hourlyPrices);
        
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
     * Analyze price patterns to identify optimal charge/discharge windows
     */
    private function analyzePricePatterns(array $hourlyPrices): array
    {
        $prices = array_slice($hourlyPrices, 0, self::PLANNING_HORIZON_HOURS);
        
        $stats = [
            'min' => min($prices),
            'max' => max($prices),
            'avg' => array_sum($prices) / count($prices),
            'median' => $this->calculateMedian($prices)
        ];
        
        // Identify price windows
        $chargeWindows = [];
        $dischargeWindows = [];
        
        // Dynamic thresholds based on price distribution
        $lowThreshold = $stats['min'] + ($stats['avg'] - $stats['min']) * 0.3;
        $highThreshold = $stats['max'] - ($stats['max'] - $stats['avg']) * 0.3;
        
        for ($hour = 0; $hour < count($prices); $hour++) {
            $price = $prices[$hour];
            $timestamp = now()->addHours($hour);
            
            // Identify charging windows (low prices)
            if ($price <= $lowThreshold || $price <= self::CHEAP_THRESHOLD) {
                $chargeWindows[] = [
                    'hour' => $hour,
                    'price' => $price,
                    'timestamp' => $timestamp,
                    'savings' => $stats['avg'] - $price,
                    'priority' => $this->calculatePriority($price, $stats, 'charge')
                ];
            }
            
            // Identify discharging windows (high prices)
            if ($price >= $highThreshold || $price >= self::EXPENSIVE_THRESHOLD) {
                $dischargeWindows[] = [
                    'hour' => $hour,
                    'price' => $price,
                    'timestamp' => $timestamp,
                    'earnings' => $price - $stats['avg'],
                    'priority' => $this->calculatePriority($price, $stats, 'discharge')
                ];
            }
        }
        
        // Sort by priority
        usort($chargeWindows, fn($a, $b) => $b['priority'] <=> $a['priority']);
        usort($dischargeWindows, fn($a, $b) => $b['priority'] <=> $a['priority']);
        
        return [
            'stats' => $stats,
            'thresholds' => [
                'low' => $lowThreshold,
                'high' => $highThreshold
            ],
            'charge_windows' => $chargeWindows,
            'discharge_windows' => $dischargeWindows,
            'price_volatility' => $this->calculateVolatility($prices)
        ];
    }

    /**
     * Create optimal schedule based on price analysis
     */
    private function createOptimalSchedule(
        array $hourlyPrices, 
        float $currentSOC, 
        array $priceAnalysis, 
        Carbon $startTime
    ): array {
        $schedule = [];
        $simulatedSOC = $currentSOC;
        
        // Select top charge and discharge windows
        $topChargeWindows = array_slice($priceAnalysis['charge_windows'], 0, 4);
        $topDischargeWindows = array_slice($priceAnalysis['discharge_windows'], 0, 4);
        
        // Create charging sessions
        foreach ($topChargeWindows as $window) {
            if ($simulatedSOC >= self::MAX_SOC) break;
            
            $session = $this->createChargeSession($window, $simulatedSOC, $startTime);
            if ($session) {
                $schedule[] = $session;
                $simulatedSOC = min(self::MAX_SOC, $simulatedSOC + $session['energy_change']);
            }
        }
        
        // Create discharging sessions
        foreach ($topDischargeWindows as $window) {
            if ($simulatedSOC <= self::MIN_SOC) break;
            
            $session = $this->createDischargeSession($window, $simulatedSOC, $startTime);
            if ($session) {
                $schedule[] = $session;
                $simulatedSOC = max(self::MIN_SOC, $simulatedSOC - $session['energy_change']);
            }
        }
        
        // Sort by start time
        usort($schedule, fn($a, $b) => $a['start_time']->timestamp <=> $b['start_time']->timestamp);
        
        return $schedule;
    }

    /**
     * Create a charging session
     */
    private function createChargeSession(array $window, float $currentSOC, Carbon $startTime): ?array
    {
        if ($currentSOC >= self::MAX_SOC * 0.9) return null;
        
        $availableCapacity = (self::MAX_SOC - $currentSOC) * self::BATTERY_CAPACITY / 100;
        $maxEnergyThisSession = min($availableCapacity, self::MAX_CHARGE_POWER * 2); // Max 2 hours
        
        $duration = min(
            ceil($maxEnergyThisSession / self::MAX_CHARGE_POWER),
            self::MIN_SESSION_DURATION * 2
        );
        
        $actualEnergy = min($maxEnergyThisSession, self::MAX_CHARGE_POWER * $duration);
        $socChange = ($actualEnergy / self::BATTERY_CAPACITY) * 100;
        
        return [
            'action' => 'charge',
            'start_time' => $startTime->copy()->addHours($window['hour']),
            'duration' => $duration, // hours
            'power' => self::MAX_CHARGE_POWER,
            'energy_change' => $socChange,
            'target_soc' => min(self::MAX_SOC, $currentSOC + $socChange),
            'price' => $window['price'],
            'priority' => $window['priority'],
            'savings_estimate' => $window['savings'] * $actualEnergy,
            'reason' => sprintf('Low price: %.3f SEK/kWh (save %.2f SEK)', 
                             $window['price'], $window['savings'] * $actualEnergy)
        ];
    }

    /**
     * Create a discharging session
     */
    private function createDischargeSession(array $window, float $currentSOC, Carbon $startTime): ?array
    {
        if ($currentSOC <= self::MIN_SOC * 1.1) return null;
        
        $availableCapacity = ($currentSOC - self::MIN_SOC) * self::BATTERY_CAPACITY / 100;
        $maxEnergyThisSession = min($availableCapacity, self::MAX_DISCHARGE_POWER * 2);
        
        $duration = min(
            ceil($maxEnergyThisSession / self::MAX_DISCHARGE_POWER),
            self::MIN_SESSION_DURATION * 2
        );
        
        $actualEnergy = min($maxEnergyThisSession, self::MAX_DISCHARGE_POWER * $duration);
        $socChange = ($actualEnergy / self::BATTERY_CAPACITY) * 100;
        
        return [
            'action' => 'discharge',
            'start_time' => $startTime->copy()->addHours($window['hour']),
            'duration' => $duration,
            'power' => self::MAX_DISCHARGE_POWER,
            'energy_change' => $socChange,
            'target_soc' => max(self::MIN_SOC, $currentSOC - $socChange),
            'price' => $window['price'],
            'priority' => $window['priority'],
            'earnings_estimate' => $window['earnings'] * $actualEnergy * self::ROUND_TRIP_EFFICIENCY,
            'reason' => sprintf('High price: %.3f SEK/kWh (earn %.2f SEK)', 
                              $window['price'], $window['earnings'] * $actualEnergy)
        ];
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
                'power' => self::MAX_CHARGE_POWER * 0.5,
                'duration' => 60,
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
        
        // Price-based decisions
        if ($currentPrice <= self::VERY_CHEAP_THRESHOLD && $currentSOC < 80) {
            return [
                'action' => 'charge',
                'power' => self::MAX_CHARGE_POWER,
                'duration' => 60,
                'reason' => "Very cheap price: {$currentPrice} SEK/kWh - maximize charging",
                'confidence' => 'high'
            ];
        }
        
        if ($currentPrice >= self::VERY_EXPENSIVE_THRESHOLD && $currentSOC > 30) {
            return [
                'action' => 'discharge',
                'power' => self::MAX_DISCHARGE_POWER,
                'duration' => 60,
                'reason' => "Very expensive price: {$currentPrice} SEK/kWh - maximize discharge",
                'confidence' => 'high'
            ];
        }
        
        // Context-based decisions
        if ($priceContext['is_local_minimum'] && $currentSOC < 70) {
            return [
                'action' => 'charge',
                'power' => self::MAX_CHARGE_POWER * 0.8,
                'duration' => 30,
                'reason' => 'Local price minimum detected',
                'confidence' => 'medium'
            ];
        }
        
        if ($priceContext['is_local_maximum'] && $currentSOC > 40) {
            return [
                'action' => 'discharge',
                'power' => self::MAX_DISCHARGE_POWER * 0.8,
                'duration' => 30,
                'reason' => 'Local price maximum detected',
                'confidence' => 'medium'
            ];
        }
        
        // Load balancing
        if (abs($netLoad) > 1.0) { // More than 1kW imbalance
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
    private function analyzePriceContext(float $currentPrice, array $nextHours, array $allPrices): array
    {
        $context = [
            'is_local_minimum' => false,
            'is_local_maximum' => false,
            'trend' => 'stable',
            'volatility' => 'low'
        ];
        
        if (count($nextHours) >= 3) {
            $avgNext3 = array_sum(array_slice($nextHours, 1, 3)) / 3;
            
            // Check if current price is significantly lower/higher than next hours
            $context['is_local_minimum'] = $currentPrice < $avgNext3 * 0.9;
            $context['is_local_maximum'] = $currentPrice > $avgNext3 * 1.1;
            
            // Trend analysis
            if ($avgNext3 > $currentPrice * 1.1) {
                $context['trend'] = 'increasing';
            } elseif ($avgNext3 < $currentPrice * 0.9) {
                $context['trend'] = 'decreasing';
            }
        }
        
        // Overall volatility
        $priceStd = $this->calculateStandardDeviation($allPrices);
        $priceAvg = array_sum($allPrices) / count($allPrices);
        $volatilityRatio = $priceStd / $priceAvg;
        
        if ($volatilityRatio > 0.3) {
            $context['volatility'] = 'high';
        } elseif ($volatilityRatio > 0.15) {
            $context['volatility'] = 'medium';
        }
        
        return $context;
    }

    // Helper methods
    private function validateInputs(array $hourlyPrices, float $currentSOC): void
    {
        if (empty($hourlyPrices)) {
            throw new \InvalidArgumentException('Hourly prices array cannot be empty');
        }
        
        if ($currentSOC < 0 || $currentSOC > 100) {
            throw new \InvalidArgumentException('Current SOC must be between 0 and 100');
        }
        
        foreach ($hourlyPrices as $price) {
            if ($price < 0 || $price > 10) {
                throw new \InvalidArgumentException('Invalid price detected');
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
            return max(0, ($stats['avg'] - $price) / ($stats['avg'] - $stats['min'])) * 100;
        } else {
            // Higher prices = higher priority
            return max(0, ($price - $stats['avg']) / ($stats['max'] - $stats['avg'])) * 100;
        }
    }

    private function optimizeSchedule(array $schedule, float $currentSOC): array
    {
        // Remove overlapping sessions and ensure SOC constraints
        $optimized = [];
        $simulatedSOC = $currentSOC;
        
        foreach ($schedule as $session) {
            // Check if session is still valid
            if ($session['action'] === 'charge' && $simulatedSOC >= self::MAX_SOC * 0.95) {
                continue;
            }
            
            if ($session['action'] === 'discharge' && $simulatedSOC <= self::MIN_SOC * 1.05) {
                continue;
            }
            
            $optimized[] = $session;
            
            // Update simulated SOC
            if ($session['action'] === 'charge') {
                $simulatedSOC = min(self::MAX_SOC, $simulatedSOC + $session['energy_change']);
            } else {
                $simulatedSOC = max(self::MIN_SOC, $simulatedSOC - $session['energy_change']);
            }
        }
        
        return $optimized;
    }

    private function generateScheduleSummary(array $schedule, float $currentSOC): array
    {
        $totalChargeSessions = count(array_filter($schedule, fn($s) => $s['action'] === 'charge'));
        $totalDischargeSessions = count(array_filter($schedule, fn($s) => $s['action'] === 'discharge'));
        
        $totalSavings = array_sum(array_column(
            array_filter($schedule, fn($s) => $s['action'] === 'charge'), 
            'savings_estimate'
        ));
        
        $totalEarnings = array_sum(array_column(
            array_filter($schedule, fn($s) => $s['action'] === 'discharge'), 
            'earnings_estimate'
        ));
        
        return [
            'total_sessions' => count($schedule),
            'charge_sessions' => $totalChargeSessions,
            'discharge_sessions' => $totalDischargeSessions,
            'estimated_savings' => $totalSavings,
            'estimated_earnings' => $totalEarnings,
            'net_benefit' => $totalEarnings + $totalSavings,
            'starting_soc' => $currentSOC
        ];
    }
}