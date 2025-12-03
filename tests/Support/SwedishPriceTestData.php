<?php

namespace Tests\Support;

use Carbon\Carbon;

class SwedishPriceTestData
{
    private static ?array $pricePatterns = null;

    public static function loadPricePatterns(): array
    {
        if (self::$pricePatterns === null) {
            $jsonPath = __DIR__ . '/../Fixtures/swedish_price_patterns.json';
            $jsonContent = file_get_contents($jsonPath);
            self::$pricePatterns = json_decode($jsonContent, true);
        }

        return self::$pricePatterns;
    }

    public static function getPattern(string $patternName): array
    {
        $patterns = self::loadPricePatterns();
        
        if (!isset($patterns[$patternName])) {
            throw new \InvalidArgumentException("Price pattern '{$patternName}' not found");
        }

        return $patterns[$patternName];
    }

    public static function getPrices(string $patternName): array
    {
        $pattern = self::getPattern($patternName);
        return $pattern['hourly_prices_sek_kwh'];
    }

    public static function getCharacteristics(string $patternName): array
    {
        $pattern = self::getPattern($patternName);
        return $pattern['characteristics'];
    }

    public static function getAllPatternNames(): array
    {
        return array_keys(self::loadPricePatterns());
    }

    /**
     * Get solar forecast that matches Swedish seasonal patterns
     */
    public static function getSolarForecast(string $season, string $date = null): array
    {
        $date = $date ? Carbon::parse($date) : Carbon::now();
        $dayOfYear = $date->dayOfYear;
        
        // Solar parameters based on Stockholm latitude (59.3°N)
        $maxSolarHours = match($season) {
            'winter' => ['start' => 8, 'end' => 15, 'peak' => 6.0],    // 7 hours, max 6kW
            'spring' => ['start' => 6, 'end' => 18, 'peak' => 8.5],   // 12 hours, max 8.5kW
            'summer' => ['start' => 4, 'end' => 20, 'peak' => 10.0],  // 16 hours, max 10kW
            'autumn' => ['start' => 7, 'end' => 17, 'peak' => 7.5],   // 10 hours, max 7.5kW
            default => ['start' => 6, 'end' => 18, 'peak' => 8.0]
        };

        $solar = [];
        for ($i = 0; $i < 96; $i++) { // 96 quarter-hour intervals
            $hour = $i / 4;
            
            if ($hour < $maxSolarHours['start'] || $hour > $maxSolarHours['end']) {
                $solar[] = 0; // No solar outside daylight hours
            } else {
                // Bell curve centered on solar noon (12:00)
                $peakHour = 12;
                $width = ($maxSolarHours['end'] - $maxSolarHours['start']) / 2;
                
                $solarFactor = exp(-0.5 * pow(($hour - $peakHour) / ($width * 0.6), 2));
                $power = $maxSolarHours['peak'] * $solarFactor;
                
                // Add some realistic variability (clouds, etc.)
                $variability = 1 + (sin($i * 0.1) * 0.15); // ±15% variation
                $solar[] = round(max(0, $power * $variability), 1);
            }
        }
        
        return $solar;
    }

    /**
     * Get load forecast that matches Swedish consumption patterns
     */
    public static function getLoadForecast(string $season, string $dayType = 'weekday'): array
    {
        $baseLoad = match($season) {
            'winter' => 2.5,  // High heating load
            'spring' => 1.8,  // Moderate load
            'summer' => 1.2,  // Low load (vacation, no heating)
            'autumn' => 2.0,  // Increasing load
            default => 1.8
        };

        $peakMultipliers = match($dayType) {
            'weekday' => [
                'morning' => 2.2,  // 6-9 AM
                'midday' => 1.4,   // 12-14 PM  
                'evening' => 2.8,  // 17-21 PM
                'night' => 0.6     // 22-6 AM
            ],
            'weekend' => [
                'morning' => 1.6,  // Later morning peak
                'midday' => 1.8,   // Higher midday activity
                'evening' => 2.2,  // Lower evening peak
                'night' => 0.7
            ],
            'holiday' => [
                'morning' => 1.2,
                'midday' => 1.5,
                'evening' => 1.8,
                'night' => 0.8
            ],
            default => [
                'morning' => 2.0,
                'midday' => 1.5,
                'evening' => 2.5,
                'night' => 0.7
            ]
        ];

        $load = [];
        for ($i = 0; $i < 96; $i++) { // 96 quarter-hour intervals
            $hour = $i / 4;
            
            $multiplier = match(true) {
                $hour >= 6 && $hour < 9 => $peakMultipliers['morning'],
                $hour >= 12 && $hour < 14 => $peakMultipliers['midday'],
                $hour >= 17 && $hour < 21 => $peakMultipliers['evening'],
                default => $peakMultipliers['night']
            };

            // Add heating adjustment for winter
            if ($season === 'winter' && ($hour >= 6 && $hour <= 22)) {
                $multiplier *= 1.3; // 30% increase for heating
            }

            // Add small random variation
            $variation = 1 + (sin($i * 0.05) * 0.1); // ±10% variation
            $load[] = round($baseLoad * $multiplier * $variation, 1);
        }

        return $load;
    }

    /**
     * Get expected optimization actions for a given price pattern
     */
    public static function getExpectedOptimizations(string $patternName): array
    {
        $pattern = self::getPattern($patternName);
        $characteristics = $pattern['characteristics'];
        $prices = $pattern['hourly_prices_sek_kwh'];
        
        $expectations = [];
        
        // During low price hours - should charge
        foreach ($characteristics['low_hours'] as $hour) {
            $expectations[] = [
                'hour' => $hour,
                'expected_action' => 'charge',
                'reason' => 'Low price period',
                'price' => $prices[$hour]
            ];
        }
        
        // During peak price hours - should discharge
        foreach ($characteristics['peak_hours'] as $hour) {
            $expectations[] = [
                'hour' => $hour,
                'expected_action' => 'discharge', 
                'reason' => 'High price period',
                'price' => $prices[$hour]
            ];
        }

        // Special cases
        if (isset($characteristics['negative_prices']) && $characteristics['negative_prices']) {
            foreach ($prices as $hour => $price) {
                if ($price < 0) {
                    $expectations[] = [
                        'hour' => $hour,
                        'expected_action' => 'charge',
                        'reason' => 'Negative price - paid to consume',
                        'price' => $price
                    ];
                }
            }
        }

        if (isset($characteristics['supply_shortage']) && $characteristics['supply_shortage']) {
            // During extreme prices, should definitely discharge if possible
            foreach ($prices as $hour => $price) {
                if ($price > 3.0) {
                    $expectations[] = [
                        'hour' => $hour,
                        'expected_action' => 'discharge',
                        'reason' => 'Critical price level - maximize discharge',
                        'price' => $price
                    ];
                }
            }
        }

        return $expectations;
    }

    /**
     * Create realistic test scenarios combining price, solar, and load data
     */
    public static function createTestScenario(
        string $pricePattern,
        float $startingSOC,
        string $testDescription = null
    ): array {
        $pattern = self::getPattern($pricePattern);
        $prices = $pattern['hourly_prices_sek_kwh'];
        
        return [
            'description' => $testDescription ?? $pattern['description'],
            'prices' => $prices,
            'starting_soc' => $startingSOC,
            'solar_forecast' => self::getSolarForecast($pattern['season'], $pattern['date']),
            'load_forecast' => self::getLoadForecast($pattern['season'], $pattern['day_type']),
            'characteristics' => $pattern['characteristics'],
            'expected_optimizations' => self::getExpectedOptimizations($pricePattern),
            'season' => $pattern['season'],
            'day_type' => $pattern['day_type'],
            'date' => $pattern['date']
        ];
    }

    /**
     * Get all realistic test scenarios for comprehensive testing
     */
    public static function getAllTestScenarios(): array
    {
        $scenarios = [];
        $socLevels = [25, 50, 75]; // Test different starting SOC levels
        
        foreach (self::getAllPatternNames() as $patternName) {
            foreach ($socLevels as $soc) {
                $scenario = self::createTestScenario($patternName, $soc);
                $scenarios["{$patternName}_soc_{$soc}"] = $scenario;
            }
        }
        
        return $scenarios;
    }

    /**
     * Helper to validate optimization decisions against expected behavior
     */
    public static function validateOptimizationDecision(
        array $decision,
        array $expectedOptimization
    ): array {
        $validation = [
            'passes' => true,
            'messages' => []
        ];

        // Check if action matches expectation
        if ($decision['action'] !== $expectedOptimization['expected_action'] && $decision['action'] !== 'idle') {
            $validation['passes'] = false;
            $validation['messages'][] = sprintf(
                "Expected %s but got %s at price %.3f SEK/kWh (hour %d)",
                $expectedOptimization['expected_action'],
                $decision['action'],
                $expectedOptimization['price'],
                $expectedOptimization['hour']
            );
        }

        // Check power levels are reasonable
        if ($decision['action'] !== 'idle' && $decision['power_kw'] <= 0) {
            $validation['passes'] = false;
            $validation['messages'][] = "Power level should be > 0 for active actions";
        }

        if ($decision['power_kw'] > 3.0) {
            $validation['passes'] = false;
            $validation['messages'][] = "Power level exceeds maximum (3.0 kW)";
        }

        return $validation;
    }
}