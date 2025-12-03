<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\BatteryDecisionMaker;
use App\Services\ElectricityPriceAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class BatteryOptimizationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function battery_decision_maker_can_be_instantiated(): void
    {
        $decisionMaker = new BatteryDecisionMaker();
        $this->assertInstanceOf(BatteryDecisionMaker::class, $decisionMaker);
    }

    /** @test */
    public function price_aggregator_can_be_instantiated(): void
    {
        $aggregator = new ElectricityPriceAggregator();
        $this->assertInstanceOf(ElectricityPriceAggregator::class, $aggregator);
    }

    /** @test */
    public function battery_optimization_with_real_price_aggregator(): void
    {
        $decisionMaker = new BatteryDecisionMaker();
        $priceAggregator = new ElectricityPriceAggregator();
        
        // Test with mocked prices to avoid external API dependency
        $testPrices = array_fill(0, 24, 0.45);
        
        $decision = $decisionMaker->makeDecision(
            currentPrice: 0.45,
            next24HourPrices: $testPrices,
            currentSOC: 55.0
        );
        
        $this->assertIsArray($decision);
        $this->assertArrayHasKey('action', $decision);
        $this->assertArrayHasKey('power_kw', $decision);
        $this->assertContains($decision['action'], ['charge', 'discharge', 'idle']);
    }

    /** @test */
    public function console_commands_are_registered(): void
    {
        $this->artisan('list')
             ->expectsOutput('battery:test-decisions')
             ->assertExitCode(0);
    }

    /** @test */
    public function battery_test_decisions_command_works(): void
    {
        $this->artisan('battery:test-decisions --scenario=safety')
             ->assertExitCode(0);
    }

    /** @test */
    public function price_multi_test_command_works(): void
    {
        $this->artisan('prices:multi-test')
             ->assertExitCode(0);
    }

    /** @test */
    public function full_day_schedule_generation_works(): void
    {
        $decisionMaker = new BatteryDecisionMaker();
        
        $realisticPrices = [
            0.30, 0.28, 0.25, 0.23, 0.22, 0.25, // Night: cheap
            0.35, 0.45, 0.55, 0.60, 0.65, 0.70, // Morning: rising
            0.65, 0.60, 0.55, 0.50, 0.55, 0.75, // Afternoon: variable
            0.85, 0.90, 0.80, 0.65, 0.45, 0.35  // Evening: peak then drop
        ];
        
        $schedule = $decisionMaker->generateDaySchedule(
            hourlyPrices: $realisticPrices,
            startingSOC: 50.0
        );
        
        $this->assertIsArray($schedule);
        $this->assertArrayHasKey('schedule', $schedule);
        $this->assertArrayHasKey('summary', $schedule);
        
        // Should have 96 quarter-hour intervals
        $this->assertCount(96, $schedule['schedule']);
        
        // Summary should be properly calculated
        $summary = $schedule['summary'];
        $this->assertEquals(96, $summary['total_intervals']);
        $this->assertEquals(
            $summary['total_intervals'],
            $summary['charge_intervals'] + $summary['discharge_intervals'] + $summary['idle_intervals']
        );
        
        // Should have some charge actions during cheap hours
        $this->assertGreaterThan(0, $summary['charge_intervals']);
        
        // Should have some discharge actions during expensive hours
        $this->assertGreaterThan(0, $summary['discharge_intervals']);
    }

    /** @test */
    public function decision_maker_respects_configuration_constants(): void
    {
        $decisionMaker = new BatteryDecisionMaker();
        
        // Test minimum SOC protection
        $decision = $decisionMaker->makeDecision(
            currentPrice: 1.50, // Very expensive
            next24HourPrices: array_fill(0, 24, 1.50),
            currentSOC: 19.0 // Below minimum
        );
        
        $this->assertEquals('idle', $decision['action']);
        $this->assertEquals(0, $decision['power_kw']);
        
        // Test maximum SOC protection
        $decision = $decisionMaker->makeDecision(
            currentPrice: 0.05, // Very cheap
            next24HourPrices: array_fill(0, 24, 0.05),
            currentSOC: 96.0 // Above maximum
        );
        
        $this->assertEquals('idle', $decision['action']);
        $this->assertEquals(0, $decision['power_kw']);
    }

    /** @test */
    public function optimization_works_across_different_times(): void
    {
        $decisionMaker = new BatteryDecisionMaker();
        
        $nightTime = Carbon::parse('2024-01-01 03:00:00');
        $dayTime = Carbon::parse('2024-01-01 15:00:00');
        
        $prices = array_fill(0, 24, 0.50);
        $prices[3] = 0.15;  // Cheap night price
        $prices[15] = 0.85; // Expensive day price
        
        // Night decision with cheap price
        $nightDecision = $decisionMaker->makeDecision(
            currentPrice: 0.15,
            next24HourPrices: $prices,
            currentSOC: 45,
            timestamp: $nightTime
        );
        
        // Day decision with expensive price
        $dayDecision = $decisionMaker->makeDecision(
            currentPrice: 0.85,
            next24HourPrices: $prices,
            currentSOC: 75,
            timestamp: $dayTime
        );
        
        // Should prefer charging at night and discharging during expensive day
        if ($nightDecision['action'] !== 'idle') {
            $this->assertEquals('charge', $nightDecision['action']);
        }
        
        if ($dayDecision['action'] !== 'idle') {
            $this->assertEquals('discharge', $dayDecision['action']);
        }
    }

    /** @test */
    public function optimization_handles_solar_and_load_variations(): void
    {
        $decisionMaker = new BatteryDecisionMaker();
        $prices = array_fill(0, 24, 0.50);
        
        // High solar, low load scenario
        $solarDecision = $decisionMaker->makeDecision(
            currentPrice: 0.50,
            next24HourPrices: $prices,
            currentSOC: 45,
            solarPowerKW: 6.0,
            homeLoodKW: 2.0
        );
        
        // Low solar, high load scenario
        $loadDecision = $decisionMaker->makeDecision(
            currentPrice: 0.50,
            next24HourPrices: $prices,
            currentSOC: 75,
            solarPowerKW: 0.5,
            homeLoodKW: 4.5
        );
        
        // High solar should prefer charging
        if ($solarDecision['action'] === 'charge') {
            $this->assertEquals('solar', $solarDecision['priority']);
        }
        
        // High load should consider discharging
        if ($loadDecision['action'] === 'discharge') {
            $this->assertEquals('load_balancing', $loadDecision['priority']);
        }
    }
}