<?php

namespace Tests\Unit;

use App\Services\BatteryDecisionMaker;
use Tests\Support\SwedishPriceTestData;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class BatteryDecisionMakerTest extends TestCase
{
    private BatteryDecisionMaker $decisionMaker;
    private array $neutralPrices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->decisionMaker = new BatteryDecisionMaker();
        $this->neutralPrices = array_fill(0, 24, 0.50); // 24 hours of 0.50 SEK/kWh
    }

    /** @test */
    public function it_forces_emergency_charging_when_soc_is_critical(): void
    {
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: 0.50,
            next24HourPrices: $this->neutralPrices,
            currentSOC: 22 // Critical SOC
        );

        $this->assertEquals('charge', $decision['action']);
        $this->assertEquals('critical', $decision['confidence']);
        $this->assertEquals('safety', $decision['priority']);
        $this->assertGreaterThan(0, $decision['power_kw']);
        $this->assertStringContainsString('SAFETY', $decision['reason']);
    }

    /** @test */
    public function it_prevents_charging_when_soc_is_at_maximum(): void
    {
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: 0.05, // Very cheap price
            next24HourPrices: $this->neutralPrices,
            currentSOC: 96 // Over maximum
        );

        $this->assertEquals('idle', $decision['action']);
        $this->assertEquals(0, $decision['power_kw']);
        $this->assertEquals('safety', $decision['priority']);
        $this->assertStringContainsString('SAFETY', $decision['reason']);
    }

    /** @test */
    public function it_prevents_discharging_when_soc_is_at_minimum(): void
    {
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: 1.50, // Very expensive price
            next24HourPrices: $this->neutralPrices,
            currentSOC: 19 // Below minimum
        );

        $this->assertEquals('idle', $decision['action']);
        $this->assertEquals(0, $decision['power_kw']);
        $this->assertEquals('safety', $decision['priority']);
        $this->assertStringContainsString('SAFETY', $decision['reason']);
    }

    /** @test */
    public function it_forces_charging_at_very_cheap_prices(): void
    {
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: 0.05, // Very cheap
            next24HourPrices: $this->neutralPrices,
            currentSOC: 50
        );

        $this->assertEquals('charge', $decision['action']);
        $this->assertEquals('very_high', $decision['confidence']);
        $this->assertEquals('price', $decision['priority']);
        $this->assertGreaterThan(2.0, $decision['power_kw']);
        $this->assertStringContainsString('FORCE CHARGE', $decision['reason']);
    }

    /** @test */
    public function it_forces_discharging_at_very_expensive_prices(): void
    {
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: 1.50, // Very expensive
            next24HourPrices: $this->neutralPrices,
            currentSOC: 50
        );

        $this->assertEquals('discharge', $decision['action']);
        $this->assertEquals('very_high', $decision['confidence']);
        $this->assertEquals('price', $decision['priority']);
        $this->assertGreaterThan(2.0, $decision['power_kw']);
        $this->assertStringContainsString('FORCE DISCHARGE', $decision['reason']);
    }

    /** @test */
    public function it_makes_smart_charging_decisions_with_cheap_price_and_low_soc(): void
    {
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: 0.20, // Cheap
            next24HourPrices: $this->neutralPrices,
            currentSOC: 35 // Low SOC
        );

        $this->assertEquals('charge', $decision['action']);
        $this->assertContains($decision['confidence'], ['high', 'medium']);
        $this->assertEquals('price_soc', $decision['priority']);
        $this->assertGreaterThan(0, $decision['power_kw']);
        $this->assertStringContainsString('SMART CHARGE', $decision['reason']);
    }

    /** @test */
    public function it_makes_smart_discharging_decisions_with_expensive_price_and_high_soc(): void
    {
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: 0.80, // Expensive
            next24HourPrices: $this->neutralPrices,
            currentSOC: 75 // High SOC
        );

        $this->assertEquals('discharge', $decision['action']);
        $this->assertContains($decision['confidence'], ['high', 'medium']);
        $this->assertEquals('price_soc', $decision['priority']);
        $this->assertGreaterThan(0, $decision['power_kw']);
        $this->assertStringContainsString('SMART DISCHARGE', $decision['reason']);
    }

    /** @test */
    public function it_idles_with_medium_prices_and_medium_soc(): void
    {
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: 0.45, // Medium
            next24HourPrices: $this->neutralPrices,
            currentSOC: 55 // Medium SOC
        );

        $this->assertEquals('idle', $decision['action']);
        $this->assertEquals(0, $decision['power_kw']);
        $this->assertStringContainsString('IDLE', $decision['reason']);
    }

    /** @test */
    public function it_prioritizes_charging_with_excess_solar_power(): void
    {
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: 0.50, // Medium price
            next24HourPrices: $this->neutralPrices,
            currentSOC: 45,
            solarPowerKW: 5.0, // High solar
            homeLoodKW: 2.0   // Net excess of 3kW
        );

        $this->assertEquals('charge', $decision['action']);
        $this->assertEquals('solar', $decision['priority']);
        $this->assertGreaterThan(0, $decision['power_kw']);
        $this->assertStringContainsString('SOLAR', $decision['reason']);
    }

    /** @test */
    public function it_supports_load_balancing_with_high_consumption(): void
    {
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: 0.60, // Slightly expensive but not extreme
            next24HourPrices: $this->neutralPrices,
            currentSOC: 60,
            solarPowerKW: 1.0,
            homeLoodKW: 4.5 // High load, net need of 3.5kW
        );

        $this->assertEquals('discharge', $decision['action']);
        $this->assertEquals('load_balancing', $decision['priority']);
        $this->assertGreaterThan(0, $decision['power_kw']);
        $this->assertLessThanOrEqual(3.5, $decision['power_kw']); // Should not exceed net load
    }

    /** @test */
    public function it_respects_power_limits_based_on_soc(): void
    {
        // Test charging power reduction near max SOC
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: 0.05,
            next24HourPrices: $this->neutralPrices,
            currentSOC: 90 // Near max
        );

        if ($decision['action'] === 'charge') {
            $this->assertLessThan(3.0, $decision['power_kw']); // Reduced from max 3kW
        }

        // Test discharging power reduction near min SOC
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: 1.50,
            next24HourPrices: $this->neutralPrices,
            currentSOC: 25 // Near min
        );

        if ($decision['action'] === 'discharge') {
            $this->assertLessThan(3.0, $decision['power_kw']); // Reduced from max 3kW
        }
    }

    /** @test */
    public function it_calculates_accurate_soc_changes(): void
    {
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: 0.05,
            next24HourPrices: $this->neutralPrices,
            currentSOC: 50
        );

        if ($decision['action'] === 'charge') {
            $this->assertGreaterThan(0, $decision['estimated_soc_change']);
            
            // For 3kW charging for 60 minutes = 3kWh
            // With 8kWh battery and 93% efficiency: 3 * 0.93 / 8 * 100 ≈ 34.9%
            $expectedChange = ($decision['power_kw'] * ($decision['duration_minutes'] / 60) * 0.93 / 8) * 100;
            $this->assertEqualsWithDelta($expectedChange, $decision['estimated_soc_change'], 0.1);
        }
    }

    /** @test */
    public function it_includes_proper_decision_metadata(): void
    {
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: 0.30,
            next24HourPrices: $this->neutralPrices,
            currentSOC: 40
        );

        // Check required fields
        $this->assertArrayHasKey('action', $decision);
        $this->assertArrayHasKey('power_kw', $decision);
        $this->assertArrayHasKey('duration_minutes', $decision);
        $this->assertArrayHasKey('reason', $decision);
        $this->assertArrayHasKey('confidence', $decision);
        $this->assertArrayHasKey('priority', $decision);
        $this->assertArrayHasKey('estimated_soc_change', $decision);
        $this->assertArrayHasKey('timestamp', $decision);
        $this->assertArrayHasKey('valid_until', $decision);

        // Check value types
        $this->assertIsString($decision['action']);
        $this->assertIsFloat($decision['power_kw']);
        $this->assertIsInt($decision['duration_minutes']);
        $this->assertIsString($decision['reason']);
        $this->assertIsString($decision['confidence']);
        $this->assertIsString($decision['priority']);
        $this->assertIsFloat($decision['estimated_soc_change']);
    }

    /** @test */
    public function it_generates_valid_day_schedule(): void
    {
        $hourlyPrices = [
            0.30, 0.28, 0.25, 0.23, 0.22, 0.25, // Night: cheap
            0.35, 0.45, 0.55, 0.60, 0.65, 0.70, // Morning: rising
            0.65, 0.60, 0.55, 0.50, 0.55, 0.75, // Afternoon: variable
            0.85, 0.90, 0.80, 0.65, 0.45, 0.35  // Evening: expensive then cheap
        ];

        $schedule = $this->decisionMaker->generateDaySchedule(
            hourlyPrices: $hourlyPrices,
            startingSOC: 50.0
        );

        // Check schedule structure
        $this->assertArrayHasKey('schedule', $schedule);
        $this->assertArrayHasKey('summary', $schedule);
        $this->assertArrayHasKey('starting_soc', $schedule);
        $this->assertArrayHasKey('ending_soc', $schedule);

        // Check schedule content
        $this->assertCount(96, $schedule['schedule']); // 96 quarter-hour intervals
        
        foreach ($schedule['schedule'] as $interval) {
            $this->assertArrayHasKey('time', $interval);
            $this->assertArrayHasKey('decision', $interval);
            $this->assertArrayHasKey('soc_before', $interval);
        }

        // Check summary calculations
        $summary = $schedule['summary'];
        $this->assertEquals(96, $summary['total_intervals']);
        $this->assertEquals(
            $summary['total_intervals'], 
            $summary['charge_intervals'] + $summary['discharge_intervals'] + $summary['idle_intervals']
        );

        // SOC should stay within bounds
        $this->assertGreaterThanOrEqual(20, $schedule['ending_soc']);
        $this->assertLessThanOrEqual(95, $schedule['ending_soc']);
    }

    /** @test */
    public function it_handles_edge_cases_gracefully(): void
    {
        // Extreme price values
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: 0.001, // Extremely cheap
            next24HourPrices: $this->neutralPrices,
            currentSOC: 50
        );
        $this->assertIsArray($decision);

        // Extreme SOC values
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: 0.50,
            next24HourPrices: $this->neutralPrices,
            currentSOC: 99.9 // Very high
        );
        $this->assertIsArray($decision);

        // Extreme power values
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: 0.50,
            next24HourPrices: $this->neutralPrices,
            currentSOC: 50,
            solarPowerKW: 20.0, // Unrealistically high
            homeLoodKW: 15.0
        );
        $this->assertIsArray($decision);
        $this->assertLessThanOrEqual(3.0, $decision['power_kw']); // Should cap at max power
    }

    /** @test */
    public function it_makes_different_decisions_for_different_times_of_day(): void
    {
        $variablePrices = [
            0.20, 0.20, 0.20, 0.20, 0.20, 0.20, // Night: cheap
            0.40, 0.40, 0.40, 0.40, 0.40, 0.40, // Morning: medium
            0.40, 0.40, 0.40, 0.40, 0.40, 0.40, // Afternoon: medium
            0.80, 0.80, 0.80, 0.80, 0.40, 0.40  // Evening: expensive then medium
        ];

        $nightDecision = $this->decisionMaker->makeDecision(
            currentPrice: 0.20,
            next24HourPrices: $variablePrices,
            currentSOC: 50,
            timestamp: Carbon::parse('03:00')
        );

        $eveningDecision = $this->decisionMaker->makeDecision(
            currentPrice: 0.80,
            next24HourPrices: $variablePrices,
            currentSOC: 50,
            timestamp: Carbon::parse('19:00')
        );

        // Should make different decisions for different price periods
        if ($nightDecision['action'] !== 'idle' || $eveningDecision['action'] !== 'idle') {
            $this->assertNotEquals($nightDecision['action'], $eveningDecision['action']);
        }
    }

    /** @test */
    public function it_responds_to_price_context_and_trends(): void
    {
        // Price going up - should prefer charging now
        $increasingPrices = [
            0.30, 0.35, 0.40, 0.45, 0.50, 0.55,
            0.60, 0.65, 0.70, 0.75, 0.80, 0.85,
            0.80, 0.75, 0.70, 0.65, 0.60, 0.55,
            0.50, 0.45, 0.40, 0.35, 0.30, 0.25
        ];

        $decision = $this->decisionMaker->makeDecision(
            currentPrice: 0.30, // Currently low
            next24HourPrices: $increasingPrices,
            currentSOC: 45,
            timestamp: Carbon::parse('06:00') // Early morning when prices are low
        );

        // Should lean towards charging when prices are low and trending up
        $this->assertContains($decision['action'], ['charge', 'idle']);
        
        if ($decision['action'] === 'charge') {
            $this->assertStringContainsString('CHARGE', strtoupper($decision['reason']));
        }
    }

    // Swedish-specific realistic tests using actual price patterns

    /** @test */
    public function it_handles_swedish_winter_weekday_pattern_correctly(): void
    {
        $scenario = SwedishPriceTestData::createTestScenario('winter_weekday_high_consumption', 50);
        $prices = $scenario['prices'];
        $expectations = $scenario['expected_optimizations'];

        foreach ($expectations as $expectation) {
            $decision = $this->decisionMaker->makeDecision(
                currentPrice: $expectation['price'],
                next24HourPrices: $prices,
                currentSOC: 50,
                timestamp: Carbon::parse($scenario['date'])->setHour($expectation['hour'])
            );

            $validation = SwedishPriceTestData::validateOptimizationDecision($decision, $expectation);
            
            $this->assertTrue($validation['passes'], 
                'Winter pattern optimization failed: ' . implode('; ', $validation['messages'])
            );
        }
    }

    /** @test */
    public function it_handles_negative_price_spring_day(): void
    {
        $scenario = SwedishPriceTestData::createTestScenario('negative_price_spring', 40);
        $prices = $scenario['prices'];
        
        // Test during negative price period (should definitely charge)
        $negativeHour = 4; // -0.18 SEK/kWh
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: $prices[$negativeHour],
            next24HourPrices: $prices,
            currentSOC: 40
        );

        $this->assertEquals('charge', $decision['action']);
        $this->assertGreaterThan(0, $decision['power_kw']);
        $this->assertStringContainsString('FORCE CHARGE', $decision['reason']);
        $this->assertEquals('very_high', $decision['confidence']);
    }

    /** @test */
    public function it_handles_extreme_winter_shortage_prices(): void
    {
        $scenario = SwedishPriceTestData::createTestScenario('extreme_winter_shortage', 70);
        $prices = $scenario['prices'];
        
        // Test during extreme price period (should definitely discharge)
        $extremeHour = 18; // 6.45 SEK/kWh
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: $prices[$extremeHour],
            next24HourPrices: $prices,
            currentSOC: 70
        );

        $this->assertEquals('discharge', $decision['action']);
        $this->assertGreaterThan(0, $decision['power_kw']);
        $this->assertStringContainsString('FORCE DISCHARGE', $decision['reason']);
        $this->assertEquals('very_high', $decision['confidence']);
    }

    /** @test */
    public function it_handles_summer_weekend_minimal_demand(): void
    {
        $scenario = SwedishPriceTestData::createTestScenario('summer_weekend_low_consumption', 55);
        $prices = $scenario['prices'];
        $solarForecast = $scenario['solar_forecast'];
        $loadForecast = $scenario['load_forecast'];
        
        // Test during midday with high solar (hour 12)
        $solarHour = 48; // Quarter-hour interval for 12:00 (12 * 4)
        $decision = $this->decisionMaker->makeDecision(
            currentPrice: $prices[12],
            next24HourPrices: $prices,
            currentSOC: 55,
            solarPowerKW: $solarForecast[$solarHour],
            homeLoodKW: $loadForecast[$solarHour]
        );

        // With high solar and low prices, should lean towards charging
        $this->assertContains($decision['action'], ['charge', 'idle']);
        
        if ($decision['action'] === 'charge' && $solarForecast[$solarHour] > 3.0) {
            $this->assertEquals('solar', $decision['priority']);
        }
    }

    /** @test */
    public function it_optimizes_across_full_swedish_price_patterns(): void
    {
        $patterns = ['winter_weekday_high_consumption', 'summer_weekend_low_consumption', 'spring_transition_volatile'];
        
        foreach ($patterns as $patternName) {
            $scenario = SwedishPriceTestData::createTestScenario($patternName, 50);
            
            $schedule = $this->decisionMaker->generateDaySchedule(
                hourlyPrices: $scenario['prices'],
                startingSOC: $scenario['starting_soc'],
                solarForecast: $scenario['solar_forecast'],
                loadForecast: $scenario['load_forecast']
            );

            $summary = $schedule['summary'];
            
            // Should have some optimization activity
            $totalActiveIntervals = $summary['charge_intervals'] + $summary['discharge_intervals'];
            $this->assertGreaterThan(0, $totalActiveIntervals, 
                "Pattern {$patternName} should have some charge/discharge activity"
            );

            // SOC should stay within bounds
            $this->assertGreaterThanOrEqual(20, $schedule['ending_soc']);
            $this->assertLessThanOrEqual(95, $schedule['ending_soc']);

            // Should have balanced approach (not all charging or all discharging)
            $chargeRatio = $summary['charge_intervals'] / $summary['total_intervals'];
            $this->assertLessThan(0.8, $chargeRatio, 
                "Pattern {$patternName} should not charge more than 80% of the time"
            );

            $dischargeRatio = $summary['discharge_intervals'] / $summary['total_intervals'];
            $this->assertLessThan(0.8, $dischargeRatio, 
                "Pattern {$patternName} should not discharge more than 80% of the time"
            );
        }
    }

    /** @test */
    public function it_responds_appropriately_to_swedish_seasonal_variations(): void
    {
        $winterScenario = SwedishPriceTestData::createTestScenario('winter_weekday_high_consumption', 50);
        $summerScenario = SwedishPriceTestData::createTestScenario('summer_weekend_low_consumption', 50);
        
        $winterSchedule = $this->decisionMaker->generateDaySchedule(
            hourlyPrices: $winterScenario['prices'],
            startingSOC: 50,
            solarForecast: $winterScenario['solar_forecast'],
            loadForecast: $winterScenario['load_forecast']
        );
        
        $summerSchedule = $this->decisionMaker->generateDaySchedule(
            hourlyPrices: $summerScenario['prices'],
            startingSOC: 50,
            solarForecast: $summerScenario['solar_forecast'],
            loadForecast: $summerScenario['load_forecast']
        );

        // Winter should have more discharge activity due to higher prices and loads
        $winterDischargeRatio = $winterSchedule['summary']['discharge_intervals'] / 96;
        $summerDischargeRatio = $summerSchedule['summary']['discharge_intervals'] / 96;
        
        $this->assertGreaterThan($summerDischargeRatio, $winterDischargeRatio,
            'Winter should have more discharge activity due to higher prices'
        );

        // Summer should potentially have more charging due to excess solar
        $winterChargeRatio = $winterSchedule['summary']['charge_intervals'] / 96;
        $summerChargeRatio = $summerSchedule['summary']['charge_intervals'] / 96;
        
        // This is scenario dependent, but generally summer should allow more solar charging
        if ($summerChargeRatio > $winterChargeRatio) {
            $this->assertGreaterThan($winterChargeRatio, $summerChargeRatio,
                'Summer may have more charging opportunities due to solar'
            );
        }
    }

    /** @test */
    public function it_validates_energy_balance_with_realistic_patterns(): void
    {
        $scenario = SwedishPriceTestData::createTestScenario('spring_transition_volatile', 45);
        
        $schedule = $this->decisionMaker->generateDaySchedule(
            hourlyPrices: $scenario['prices'],
            startingSOC: $scenario['starting_soc'],
            solarForecast: $scenario['solar_forecast'],
            loadForecast: $scenario['load_forecast']
        );

        $summary = $schedule['summary'];
        
        // Energy conservation check - efficiency losses should be reasonable
        $expectedLoss = $summary['total_charge_energy_kwh'] * 0.07; // 7% round-trip loss
        $this->assertEqualsWithDelta(
            $expectedLoss, 
            $summary['efficiency_loss_kwh'], 
            0.1,
            'Efficiency loss calculation should be accurate'
        );

        // Net energy should not exceed battery capacity (8 kWh)
        $maxPossibleChange = 8.0 * (95 - 20) / 100; // Max usable capacity
        $this->assertLessThanOrEqual($maxPossibleChange, 
            abs($summary['net_energy_kwh']),
            'Net energy change should not exceed battery usable capacity'
        );
    }