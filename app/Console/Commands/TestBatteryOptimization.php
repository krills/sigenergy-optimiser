<?php

namespace App\Console\Commands;

use App\Services\BatteryOptimizationService;
use App\Services\NordPoolApiService;
use App\Services\SigenEnergyApiService;
use Illuminate\Console\Command;

class TestBatteryOptimization extends Command
{
    protected $signature = 'battery:test
                          {--scenario= : Test scenario (low-price|high-price|medium-price|all)}
                          {--system-id= : Specific system ID to test}';

    protected $description = 'Test battery optimization algorithms with different price scenarios';

    public function handle()
    {
        $this->info('🧪 Testing Stockholm Battery Optimization System');
        $this->newLine();

        $scenario = $this->option('scenario') ?? 'all';
        $scenarios = $scenario === 'all' 
            ? ['low-price', 'medium-price', 'high-price'] 
            : [$scenario];

        foreach ($scenarios as $testScenario) {
            $this->runScenario($testScenario);
            $this->newLine();
        }

        $this->info('✅ All optimization tests completed!');
        return Command::SUCCESS;
    }

    private function runScenario(string $scenario): void
    {
        $this->info("📊 Testing scenario: {$scenario}");
        
        try {
            // Mock price data for testing
            $testPrices = $this->getTestPrices($scenario);
            $this->displayScenarioInfo($scenario, $testPrices);
            
            // Test Nord Pool API with mock data
            $this->testNordPoolApi($testPrices);
            
            // Test Sigenergy API connection
            $this->testSigenEnergyApi();
            
            // Test optimization decision making
            $this->testOptimizationLogic($scenario, $testPrices);
            
        } catch (\Exception $e) {
            $this->error("❌ Scenario {$scenario} failed: {$e->getMessage()}");
        }
    }

    private function getTestPrices(string $scenario): array
    {
        switch ($scenario) {
            case 'low-price':
                return [
                    'current' => 0.08, // Very low price - should trigger grid charging
                    'next_24h' => array_merge(
                        array_fill(0, 6, 0.08),   // 6 hours of very low prices
                        array_fill(0, 12, 0.45),  // 12 hours of medium prices  
                        array_fill(0, 6, 0.65)    // 6 hours of higher prices
                    )
                ];
                
            case 'high-price':
                return [
                    'current' => 1.25, // Very high price - should trigger battery discharge
                    'next_24h' => array_merge(
                        array_fill(0, 6, 1.25),   // 6 hours of very high prices
                        array_fill(0, 12, 0.75),  // 12 hours of high prices
                        array_fill(0, 6, 0.35)    // 6 hours of lower prices
                    )
                ];
                
            case 'medium-price':
                return [
                    'current' => 0.52, // Medium price - should optimize based on solar/SOC
                    'next_24h' => array_merge(
                        array_fill(0, 8, 0.52),   // 8 hours of medium prices
                        array_fill(0, 8, 0.48),   // 8 hours slightly lower
                        array_fill(0, 8, 0.58)    // 8 hours slightly higher
                    )
                ];
                
            default:
                return [
                    'current' => 0.50,
                    'next_24h' => array_fill(0, 24, 0.50)
                ];
        }
    }

    private function displayScenarioInfo(string $scenario, array $prices): void
    {
        $this->line("Current Price: {$prices['current']} SEK/kWh");
        $this->line("24h Average: " . number_format(array_sum($prices['next_24h']) / 24, 3) . " SEK/kWh");
        $this->line("24h Range: " . number_format(min($prices['next_24h']), 3) . " - " . number_format(max($prices['next_24h']), 3) . " SEK/kWh");
    }

    private function testNordPoolApi(array $testPrices): void
    {
        $this->line('Testing Nord Pool API...');
        
        try {
            $nordpool = new NordPoolApiService();
            $testResult = $nordpool->testConnection();
            
            if ($testResult['success']) {
                $this->info('✅ Nord Pool API connection successful');
                $this->line("  Real current price: {$testResult['current_price']} SEK/kWh");
                $this->line("  Real price range: {$testResult['price_range']['min']} - {$testResult['price_range']['max']} SEK/kWh");
            } else {
                $this->warn("⚠️ Nord Pool API issue: {$testResult['error']}");
                $this->line('  Using test prices for simulation');
            }
        } catch (\Exception $e) {
            $this->warn("⚠️ Nord Pool API error: {$e->getMessage()}");
            $this->line('  Using test prices for simulation');
        }
    }

    private function testSigenEnergyApi(): void
    {
        $this->line('Testing Sigenergy API...');
        
        try {
            $sigenergy = new SigenEnergyApiService();
            $token = $sigenergy->authenticate();
            
            if ($token) {
                $this->info('✅ Sigenergy API authentication successful');
                
                // Try to get system list
                try {
                    $systems = $sigenergy->getSystemList();
                    $this->line("  Found " . count($systems) . " system(s)");
                } catch (\Exception $e) {
                    $this->line("  System discovery: {$e->getMessage()}");
                }
            } else {
                $this->error('❌ Sigenergy API authentication failed');
                $this->line('  Check SIGENERGY_USERNAME and SIGENERGY_PASSWORD in .env');
            }
        } catch (\Exception $e) {
            $this->error("❌ Sigenergy API error: {$e->getMessage()}");
        }
    }

    private function testOptimizationLogic(string $scenario, array $testPrices): void
    {
        $this->line('Testing optimization decision logic...');
        
        // Create mock system state for different scenarios
        $mockSystemStates = [
            'low_soc' => [
                'batterySoc' => 25,
                'pvPower' => 1.5,
                'gridPower' => 2.0,
                'loadPower' => 3.0,
                'batteryPower' => 0.5
            ],
            'medium_soc' => [
                'batterySoc' => 55,
                'pvPower' => 4.2,
                'gridPower' => -1.5,
                'loadPower' => 2.7,
                'batteryPower' => 0
            ],
            'high_soc' => [
                'batterySoc' => 85,
                'pvPower' => 6.1,
                'gridPower' => -3.0,
                'loadPower' => 3.1,
                'batteryPower' => -2.5
            ]
        ];

        foreach ($mockSystemStates as $stateType => $mockState) {
            $this->line("  Testing with {$stateType} ({$mockState['batterySoc']}% SOC):");
            
            $decision = $this->simulateOptimizationDecision($scenario, $testPrices, $mockState);
            $this->line("    Recommended mode: {$decision['mode']}");
            $this->line("    Reason: {$decision['reason']}");
            
            if (isset($decision['chargingPower'])) {
                $this->line("    Charging power: {$decision['chargingPower']} kW");
            }
            
            if (isset($decision['dischargingPower'])) {
                $this->line("    Discharging power: {$decision['dischargingPower']} kW");
            }
        }
        
        $this->info('✅ Optimization logic test completed');
    }

    private function simulateOptimizationDecision(string $scenario, array $testPrices, array $mockState): array
    {
        // Simplified version of the optimization decision logic for testing
        $currentPrice = $testPrices['current'];
        $soc = $mockState['batterySoc'];
        $pvPower = $mockState['pvPower'];
        $isHighSolar = $pvPower > 3.0;
        
        // Price thresholds from config
        $veryLowPrice = 0.05;
        $lowPrice = 0.30;
        $highPrice = 0.80;
        $veryHighPrice = 1.20;
        
        if ($currentPrice <= $veryLowPrice) {
            return [
                'mode' => 'charge',
                'reason' => 'Very cheap electricity - force charge from grid',
                'chargingPower' => 3.0,
                'priority' => 'grid_charge'
            ];
        }
        
        if ($currentPrice <= $lowPrice && $soc < 80) {
            return [
                'mode' => 'charge', 
                'reason' => 'Low price + SOC below 80% - charge with grid supplement',
                'chargingPower' => 3.0
            ];
        }
        
        if ($currentPrice >= $veryHighPrice) {
            if ($isHighSolar) {
                return [
                    'mode' => 'selfConsumption-grid',
                    'reason' => 'Very high price + high solar - export solar, use battery for home',
                    'dischargingPower' => 3.0,
                    'maxExportPower' => 5.0
                ];
            } else {
                return [
                    'mode' => 'discharge',
                    'reason' => 'Very high price + low solar - use battery to avoid grid import',
                    'dischargingPower' => 3.0
                ];
            }
        }
        
        if ($currentPrice >= $highPrice) {
            return [
                'mode' => $isHighSolar ? 'selfConsumption-grid' : 'discharge',
                'reason' => $isHighSolar 
                    ? 'High price + high solar - prioritize solar export'
                    : 'High price + low solar - use battery to avoid grid costs',
                'dischargingPower' => $isHighSolar ? null : 3.0,
                'maxExportPower' => $isHighSolar ? 4.0 : null
            ];
        }
        
        // Medium price logic
        if ($isHighSolar && $soc < 60) {
            return [
                'mode' => 'selfConsumption',
                'reason' => 'Medium price + high solar + low SOC - prioritize battery charging',
                'chargingPower' => min(3.0, $pvPower - ($mockState['loadPower'] - $pvPower))
            ];
        }
        
        return [
            'mode' => 'selfConsumption',
            'reason' => 'Medium price - maintain standard self consumption mode'
        ];
    }
}