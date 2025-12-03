<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BatteryDecisionMaker
{
    // Battery system constraints
    private const MIN_SOC = 20; // Never discharge below 20%
    private const MAX_SOC = 95; // Never charge above 95%
    private const BATTERY_CAPACITY = 8.0; // kWh
    private const MAX_CHARGE_POWER = 3.0; // kW
    private const MAX_DISCHARGE_POWER = 3.0; // kW
    private const EFFICIENCY = 0.93; // Round-trip efficiency
    
    // Price decision thresholds (SEK/kWh)
    private const FORCE_CHARGE_PRICE = 0.08; // Very cheap - always charge
    private const CHEAP_PRICE = 0.25; // Cheap - charge if SOC low
    private const EXPENSIVE_PRICE = 0.65; // Expensive - discharge if SOC high  
    private const FORCE_DISCHARGE_PRICE = 1.20; // Very expensive - always discharge
    
    // Energy thresholds
    private const LOW_SOC_THRESHOLD = 30;  // SOC below this = prefer charging
    private const HIGH_SOC_THRESHOLD = 70; // SOC above this = prefer discharging
    private const CRITICAL_SOC_THRESHOLD = 25; // Emergency charging needed

    /**
     * Make real-time decision for current quarter hour
     * This is the core function called every 15 minutes
     */
    public function makeDecision(
        float $currentPrice,
        array $next24HourPrices,
        float $currentSOC,
        float $solarPowerKW = 0,
        float $homeLoodKW = 0,
        Carbon $timestamp = null
    ): array {
        
        $timestamp = $timestamp ?? now();
        $currentHour = $timestamp->hour;
        $netLoadKW = $homeLoodKW - $solarPowerKW; // Positive = need power, Negative = excess solar
        
        Log::info('BatteryDecisionMaker: Making real-time decision', [
            'timestamp' => $timestamp->format('Y-m-d H:i:s'),
            'current_price' => $currentPrice,
            'current_soc' => $currentSOC,
            'solar_power' => $solarPowerKW,
            'home_load' => $homeLoodKW,
            'net_load' => $netLoadKW
        ]);

        // Step 1: Safety checks - these override everything
        $safetyDecision = $this->checkSafetyConstraints($currentSOC);
        if ($safetyDecision) {
            return $safetyDecision;
        }

        // Step 2: Get price context for smarter decisions
        $priceContext = $this->analyzePriceContext($currentPrice, $next24HourPrices, $currentHour);
        
        // Step 3: Make primary decision based on price and SOC
        $decision = $this->makePrimaryDecision($currentPrice, $currentSOC, $priceContext);
        
        // Step 4: Adjust for solar/load conditions
        $adjustedDecision = $this->adjustForSolarAndLoad($decision, $solarPowerKW, $homeLoodKW, $currentSOC);
        
        // Step 5: Finalize power levels and timing
        $finalDecision = $this->finalizePowerLevels($adjustedDecision, $currentSOC, $netLoadKW);
        
        Log::info('BatteryDecisionMaker: Decision made', [
            'action' => $finalDecision['action'],
            'power_kw' => $finalDecision['power_kw'],
            'duration_minutes' => $finalDecision['duration_minutes'],
            'confidence' => $finalDecision['confidence'],
            'reason' => $finalDecision['reason']
        ]);

        return $finalDecision;
    }

    /**
     * Safety constraints that override all other logic
     */
    private function checkSafetyConstraints(float $currentSOC): ?array
    {
        // Emergency charging - SOC critically low
        if ($currentSOC <= self::CRITICAL_SOC_THRESHOLD) {
            return [
                'action' => 'charge',
                'power_kw' => self::MAX_CHARGE_POWER * 0.8, // Conservative power
                'duration_minutes' => 60, // Charge for full hour
                'reason' => "SAFETY: Critical SOC ({$currentSOC}%) - emergency charging",
                'confidence' => 'critical',
                'priority' => 'safety'
            ];
        }

        // Prevent overcharging
        if ($currentSOC >= self::MAX_SOC) {
            return [
                'action' => 'idle',
                'power_kw' => 0,
                'duration_minutes' => 15,
                'reason' => "SAFETY: SOC at maximum ({$currentSOC}%) - no charging allowed",
                'confidence' => 'high',
                'priority' => 'safety'
            ];
        }

        // Prevent over-discharging
        if ($currentSOC <= self::MIN_SOC) {
            return [
                'action' => 'idle',
                'power_kw' => 0,
                'duration_minutes' => 15,
                'reason' => "SAFETY: SOC at minimum ({$currentSOC}%) - no discharging allowed",
                'confidence' => 'high',
                'priority' => 'safety'
            ];
        }

        return null; // No safety issues
    }

    /**
     * Analyze price context - is this a good time to charge/discharge?
     */
    private function analyzePriceContext(float $currentPrice, array $next24HourPrices, int $currentHour): array
    {
        // Get price statistics for context
        $avgPrice = array_sum($next24HourPrices) / count($next24HourPrices);
        $minPrice = min($next24HourPrices);
        $maxPrice = max($next24HourPrices);
        
        // Look at next few hours
        $nextHours = array_slice($next24HourPrices, $currentHour, 6); // Next 6 hours
        $avgNextHours = array_sum($nextHours) / count($nextHours);
        
        // Price percentile (0-100, where 0 = cheapest hour, 100 = most expensive)
        $pricePercentile = (($currentPrice - $minPrice) / ($maxPrice - $minPrice)) * 100;
        
        return [
            'current_price' => $currentPrice,
            'avg_24h' => $avgPrice,
            'min_24h' => $minPrice,
            'max_24h' => $maxPrice,
            'price_percentile' => $pricePercentile,
            'is_cheap' => $currentPrice < $avgPrice * 0.8, // 20% below average
            'is_expensive' => $currentPrice > $avgPrice * 1.2, // 20% above average
            'is_bottom_quartile' => $pricePercentile < 25,
            'is_top_quartile' => $pricePercentile > 75,
            'next_hours_avg' => $avgNextHours,
            'trend' => $avgNextHours > $currentPrice ? 'increasing' : 'decreasing'
        ];
    }

    /**
     * Primary decision logic based on price and SOC
     */
    private function makePrimaryDecision(float $currentPrice, float $currentSOC, array $priceContext): array
    {
        // FORCE CHARGE: Very cheap electricity
        if ($currentPrice <= self::FORCE_CHARGE_PRICE && $currentSOC < self::MAX_SOC) {
            return [
                'action' => 'charge',
                'reason' => "FORCE CHARGE: Very cheap price {$currentPrice} SEK/kWh",
                'confidence' => 'very_high',
                'priority' => 'price'
            ];
        }

        // FORCE DISCHARGE: Very expensive electricity  
        if ($currentPrice >= self::FORCE_DISCHARGE_PRICE && $currentSOC > self::MIN_SOC) {
            return [
                'action' => 'discharge',
                'reason' => "FORCE DISCHARGE: Very expensive price {$currentPrice} SEK/kWh",
                'confidence' => 'very_high',
                'priority' => 'price'
            ];
        }

        // SMART CHARGE: Cheap price + low SOC
        if ($currentPrice <= self::CHEAP_PRICE && $currentSOC < self::HIGH_SOC_THRESHOLD) {
            $confidence = $priceContext['is_bottom_quartile'] ? 'high' : 'medium';
            return [
                'action' => 'charge',
                'reason' => "SMART CHARGE: Cheap price {$currentPrice} + SOC {$currentSOC}%",
                'confidence' => $confidence,
                'priority' => 'price_soc'
            ];
        }

        // SMART DISCHARGE: Expensive price + high SOC
        if ($currentPrice >= self::EXPENSIVE_PRICE && $currentSOC > self::LOW_SOC_THRESHOLD) {
            $confidence = $priceContext['is_top_quartile'] ? 'high' : 'medium';
            return [
                'action' => 'discharge', 
                'reason' => "SMART DISCHARGE: Expensive price {$currentPrice} + SOC {$currentSOC}%",
                'confidence' => $confidence,
                'priority' => 'price_soc'
            ];
        }

        // OPPORTUNISTIC: Based on price trends and SOC
        if ($priceContext['is_bottom_quartile'] && $currentSOC < 60) {
            return [
                'action' => 'charge',
                'reason' => "OPPORTUNISTIC CHARGE: Bottom quartile price + room for charging",
                'confidence' => 'medium',
                'priority' => 'opportunistic'
            ];
        }

        if ($priceContext['is_top_quartile'] && $currentSOC > 40) {
            return [
                'action' => 'discharge',
                'reason' => "OPPORTUNISTIC DISCHARGE: Top quartile price + energy available",
                'confidence' => 'medium', 
                'priority' => 'opportunistic'
            ];
        }

        // DEFAULT: No strong price signal
        return [
            'action' => 'idle',
            'reason' => "IDLE: No strong price signal (price: {$currentPrice}, SOC: {$currentSOC}%)",
            'confidence' => 'medium',
            'priority' => 'default'
        ];
    }

    /**
     * Adjust decision based on current solar production and home load
     */
    private function adjustForSolarAndLoad(array $decision, float $solarKW, float $homeLoadKW, float $currentSOC): array
    {
        $netLoadKW = $homeLoadKW - $solarKW;
        
        // High solar production - prefer charging if we're not already charging
        if ($solarKW > 2.0 && $netLoadKW < -1.0 && $currentSOC < 85) {
            // Excess solar available
            if ($decision['action'] === 'idle') {
                $decision['action'] = 'charge';
                $decision['reason'] .= " + SOLAR: Excess solar power available ({$solarKW} kW)";
                $decision['priority'] = 'solar';
            }
            
            // If we were going to discharge but have excess solar, reconsider
            if ($decision['action'] === 'discharge' && $decision['priority'] !== 'safety') {
                $decision['action'] = 'charge';
                $decision['reason'] = "SOLAR OVERRIDE: Excess solar power overrides discharge decision";
                $decision['priority'] = 'solar';
            }
        }

        // High home load - prefer discharging if price isn't super cheap
        if ($homeLoadKW > 2.0 && $netLoadKW > 1.0 && $currentSOC > 30) {
            if ($decision['action'] === 'idle' || ($decision['action'] === 'charge' && $decision['priority'] !== 'safety')) {
                // Only switch to discharge if price isn't super cheap
                $currentPrice = $decision['current_price'] ?? 0.50;
                if ($currentPrice > self::FORCE_CHARGE_PRICE) {
                    $decision['action'] = 'discharge';
                    $decision['reason'] = "LOAD BALANCING: High home load ({$homeLoadKW} kW) requires battery support";
                    $decision['priority'] = 'load_balancing';
                }
            }
        }

        return $decision;
    }

    /**
     * Finalize power levels and duration based on decision and constraints
     */
    private function finalizePowerLevels(array $decision, float $currentSOC, float $netLoadKW): array
    {
        $action = $decision['action'];
        $powerKW = 0;
        $duration = 15; // Default 15 minutes
        
        if ($action === 'charge') {
            // Calculate charging power
            $maxAllowedPower = $this->calculateMaxChargePower($currentSOC);
            
            // Adjust based on priority
            $powerKW = match($decision['priority']) {
                'safety' => $maxAllowedPower,
                'price' => $maxAllowedPower,
                'solar' => min($maxAllowedPower, max(0, -$netLoadKW)), // Use available solar
                'price_soc' => $maxAllowedPower * 0.8,
                'opportunistic' => $maxAllowedPower * 0.6,
                default => $maxAllowedPower * 0.5
            };
            
            // Duration based on confidence
            $duration = match($decision['confidence']) {
                'critical', 'very_high' => 60,
                'high' => 30,
                default => 15
            };
        }
        
        if ($action === 'discharge') {
            // Calculate discharging power
            $maxAllowedPower = $this->calculateMaxDischargePower($currentSOC);
            
            // Adjust based on priority and load
            $powerKW = match($decision['priority']) {
                'safety' => 0, // Safety constraints already handled
                'price' => $maxAllowedPower,
                'load_balancing' => min($maxAllowedPower, $netLoadKW),
                'price_soc' => $maxAllowedPower * 0.8,
                'opportunistic' => $maxAllowedPower * 0.6,
                default => $maxAllowedPower * 0.5
            };
            
            $duration = match($decision['confidence']) {
                'very_high' => 60,
                'high' => 30,
                default => 15
            };
        }

        return [
            'action' => $action,
            'power_kw' => round($powerKW, 2),
            'duration_minutes' => $duration,
            'reason' => $decision['reason'],
            'confidence' => $decision['confidence'],
            'priority' => $decision['priority'],
            'estimated_soc_change' => $this->estimateSOCChange($action, $powerKW, $duration),
            'timestamp' => now(),
            'valid_until' => now()->addMinutes($duration)
        ];
    }

    /**
     * Calculate maximum allowed charging power based on SOC
     */
    private function calculateMaxChargePower(float $currentSOC): float
    {
        if ($currentSOC >= self::MAX_SOC) {
            return 0;
        }
        
        // Reduce power as we approach max SOC (CC/CV charging curve simulation)
        if ($currentSOC > 85) {
            $reductionFactor = (self::MAX_SOC - $currentSOC) / (self::MAX_SOC - 85);
            return self::MAX_CHARGE_POWER * max(0.3, $reductionFactor);
        }
        
        return self::MAX_CHARGE_POWER;
    }

    /**
     * Calculate maximum allowed discharge power based on SOC
     */
    private function calculateMaxDischargePower(float $currentSOC): float
    {
        if ($currentSOC <= self::MIN_SOC) {
            return 0;
        }
        
        // Reduce power as we approach min SOC
        if ($currentSOC < 30) {
            $reductionFactor = ($currentSOC - self::MIN_SOC) / (30 - self::MIN_SOC);
            return self::MAX_DISCHARGE_POWER * max(0.3, $reductionFactor);
        }
        
        return self::MAX_DISCHARGE_POWER;
    }

    /**
     * Estimate SOC change for given action
     */
    private function estimateSOCChange(string $action, float $powerKW, int $durationMinutes): float
    {
        if ($action === 'idle' || $powerKW === 0) {
            return 0;
        }
        
        $energyKWh = $powerKW * ($durationMinutes / 60);
        $socChange = ($energyKWh / self::BATTERY_CAPACITY) * 100;
        
        if ($action === 'charge') {
            return $socChange * self::EFFICIENCY; // Account for charging losses
        } else {
            return -$socChange; // Negative for discharge
        }
    }

    /**
     * Generate a full day schedule by calling makeDecision for each quarter hour
     */
    public function generateDaySchedule(
        array $hourlyPrices, 
        float $startingSOC,
        array $solarForecast = [],
        array $loadForecast = [],
        Carbon $startTime = null
    ): array {
        
        $startTime = $startTime ?? now()->startOfDay();
        $schedule = [];
        $simulatedSOC = $startingSOC;
        
        // Generate decisions for every quarter hour (96 intervals per day)
        for ($interval = 0; $interval < 96; $interval++) {
            $currentTime = $startTime->copy()->addMinutes($interval * 15);
            $hour = floor($interval / 4);
            
            $currentPrice = $hourlyPrices[$hour] ?? 0.50;
            $solarPower = $solarForecast[$interval] ?? 0;
            $loadPower = $loadForecast[$interval] ?? 1.5; // Default 1.5kW base load
            
            $decision = $this->makeDecision(
                $currentPrice,
                $hourlyPrices,
                $simulatedSOC,
                $solarPower,
                $loadPower,
                $currentTime
            );
            
            $schedule[] = [
                'time' => $currentTime->format('H:i'),
                'timestamp' => $currentTime,
                'soc_before' => round($simulatedSOC, 1),
                'decision' => $decision,
                'price' => $currentPrice,
                'solar_kw' => $solarPower,
                'load_kw' => $loadPower
            ];
            
            // Update simulated SOC for next iteration
            $simulatedSOC += $decision['estimated_soc_change'];
            $simulatedSOC = max(self::MIN_SOC, min(self::MAX_SOC, $simulatedSOC));
        }
        
        return [
            'schedule' => $schedule,
            'summary' => $this->summarizeSchedule($schedule),
            'generated_at' => now(),
            'starting_soc' => $startingSOC,
            'ending_soc' => $simulatedSOC
        ];
    }

    /**
     * Summarize a day's schedule
     */
    private function summarizeSchedule(array $schedule): array
    {
        $chargeIntervals = array_filter($schedule, fn($s) => $s['decision']['action'] === 'charge');
        $dischargeIntervals = array_filter($schedule, fn($s) => $s['decision']['action'] === 'discharge');
        
        $totalChargeEnergy = array_sum(array_map(
            fn($s) => $s['decision']['power_kw'] * ($s['decision']['duration_minutes'] / 60),
            $chargeIntervals
        ));
        
        $totalDischargeEnergy = array_sum(array_map(
            fn($s) => $s['decision']['power_kw'] * ($s['decision']['duration_minutes'] / 60),
            $dischargeIntervals
        ));
        
        return [
            'total_intervals' => count($schedule),
            'charge_intervals' => count($chargeIntervals),
            'discharge_intervals' => count($dischargeIntervals),
            'idle_intervals' => count($schedule) - count($chargeIntervals) - count($dischargeIntervals),
            'total_charge_energy_kwh' => round($totalChargeEnergy, 2),
            'total_discharge_energy_kwh' => round($totalDischargeEnergy, 2),
            'efficiency_loss_kwh' => round($totalChargeEnergy * (1 - self::EFFICIENCY), 2),
            'net_energy_kwh' => round($totalDischargeEnergy - $totalChargeEnergy, 2)
        ];
    }
}