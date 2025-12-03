<?php

namespace App\Console\Commands;

use App\Services\NordPoolApiService;
use Illuminate\Console\Command;

class TestElectricityPrices extends Command
{
    protected $signature = 'prices:test 
                          {--raw : Show raw API response}
                          {--historical : Test historical data access}
                          {--optimization : Show optimization recommendations}';

    protected $description = 'Test Stockholm electricity price API integration and data quality';

    private NordPoolApiService $nordpool;

    public function __construct()
    {
        parent::__construct();
        $this->nordpool = new NordPoolApiService();
    }

    public function handle()
    {
        $this->info('🔌 Testing Stockholm Electricity Price API Integration');
        $this->info('Provider: mgrey.se (Swedish Electricity Spot Prices)');
        $this->newLine();

        // Test basic connectivity
        $this->testConnectivity();
        $this->newLine();

        // Test current price retrieval
        $this->testCurrentPrice();
        $this->newLine();

        // Test 24-hour price data
        $this->test24HourPrices();
        $this->newLine();

        // Test price analysis and optimization windows
        $this->testPriceAnalysis();
        $this->newLine();

        if ($this->option('raw')) {
            $this->showRawApiData();
            $this->newLine();
        }

        if ($this->option('historical')) {
            $this->testHistoricalData();
            $this->newLine();
        }

        if ($this->option('optimization')) {
            $this->showOptimizationRecommendations();
            $this->newLine();
        }

        $this->info('✅ Electricity price API testing completed!');
        return Command::SUCCESS;
    }

    private function testConnectivity(): void
    {
        $this->info('🧪 Testing API connectivity...');

        $testResult = $this->nordpool->testConnection();

        if ($testResult['success']) {
            $this->info('✅ API connection successful');
            $this->line("   Provider: {$testResult['api_provider']}");
            
            if (isset($testResult['raw_api_data'])) {
                $rawData = $testResult['raw_api_data'];
                $this->line("   SE3 data available: " . ($rawData['se3_available'] ? 'Yes' : 'No'));
                $this->line("   Current hour: {$rawData['current_hour']}");
                $this->line("   Current date: {$rawData['current_date']}");
            }
        } else {
            $this->error('❌ API connection failed');
            $this->error("   Error: {$testResult['error']}");
        }
    }

    private function testCurrentPrice(): void
    {
        $this->info('💰 Testing current price retrieval...');

        try {
            $currentPrice = $this->nordpool->getCurrentPrice();
            
            $this->info("✅ Current Stockholm (SE3) price: {$currentPrice} SEK/kWh");
            
            // Categorize price level
            $priceCategory = $this->categorizePriceLevel($currentPrice);
            $this->line("   Price level: {$priceCategory}");
            
            // Show optimization suggestion
            $suggestion = $this->getOptimizationSuggestion($currentPrice);
            $this->line("   Optimization: {$suggestion}");
            
        } catch (\Exception $e) {
            $this->error('❌ Failed to get current price');
            $this->error("   Error: {$e->getMessage()}");
        }
    }

    private function test24HourPrices(): void
    {
        $this->info('📊 Testing 24-hour price data...');

        try {
            $prices = $this->nordpool->getNext24HourPrices();
            
            $this->info("✅ Retrieved " . count($prices) . " hourly prices");
            
            $minPrice = min($prices);
            $maxPrice = max($prices);
            $avgPrice = array_sum($prices) / count($prices);
            
            $this->table(['Metric', 'Value', 'Unit'], [
                ['Minimum Price', number_format($minPrice, 3), 'SEK/kWh'],
                ['Maximum Price', number_format($maxPrice, 3), 'SEK/kWh'],
                ['Average Price', number_format($avgPrice, 3), 'SEK/kWh'],
                ['Price Spread', number_format($maxPrice - $minPrice, 3), 'SEK/kWh'],
                ['Data Points', count($prices), 'hours']
            ]);
            
        } catch (\Exception $e) {
            $this->error('❌ Failed to get 24-hour prices');
            $this->error("   Error: {$e->getMessage()}");
        }
    }

    private function testPriceAnalysis(): void
    {
        $this->info('🎯 Testing price analysis and optimization windows...');

        try {
            $windows = $this->nordpool->findOptimalWindows();
            
            $chargingWindows = $windows['charging_windows'] ?? [];
            $dischargingWindows = $windows['discharging_windows'] ?? [];
            
            $this->info("✅ Found optimization opportunities:");
            $this->line("   Charging windows (low prices): " . count($chargingWindows));
            $this->line("   Discharging windows (high prices): " . count($dischargingWindows));
            
            // Show best charging windows
            if (!empty($chargingWindows)) {
                $this->line("\n   Best charging opportunities:");
                foreach (array_slice($chargingWindows, 0, 3) as $window) {
                    $savings = number_format($window['savings_vs_avg'] ?? 0, 3);
                    $this->line("     {$window['start_time']}: {$window['price']} SEK/kWh (save {$savings} vs avg)");
                }
            }
            
            // Show best discharging windows
            if (!empty($dischargingWindows)) {
                $this->line("\n   Best discharging opportunities:");
                foreach (array_slice($dischargingWindows, 0, 3) as $window) {
                    $earnings = number_format($window['earnings_vs_avg'] ?? 0, 3);
                    $this->line("     {$window['start_time']}: {$window['price']} SEK/kWh (earn {$earnings} vs avg)");
                }
            }
            
        } catch (\Exception $e) {
            $this->error('❌ Failed to analyze price windows');
            $this->error("   Error: {$e->getMessage()}");
        }
    }

    private function showRawApiData(): void
    {
        $this->info('🔍 Raw API response data...');

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)
                ->get('https://mgrey.se/espot', ['format' => 'json']);
            
            if ($response->successful()) {
                $data = $response->json();
                
                $this->line('SE3 (Stockholm) data:');
                
                if (isset($data['SE3']) && is_array($data['SE3'])) {
                    $se3Data = $data['SE3'][0] ?? null;
                    
                    if ($se3Data) {
                        $this->table(['Field', 'Value'], [
                            ['Date', $se3Data['date'] ?? 'N/A'],
                            ['Hour', $se3Data['hour'] ?? 'N/A'],
                            ['Price EUR', $se3Data['price_eur'] ?? 'N/A'],
                            ['Price SEK (öre)', $se3Data['price_sek'] ?? 'N/A'],
                            ['K-means Cluster', $se3Data['kmeans'] ?? 'N/A']
                        ]);
                    }
                } else {
                    $this->warn('No SE3 data found in response');
                }
                
            } else {
                $this->error('Failed to fetch raw data: ' . $response->status());
            }
            
        } catch (\Exception $e) {
            $this->error('Error fetching raw data: ' . $e->getMessage());
        }
    }

    private function testHistoricalData(): void
    {
        $this->info('📅 Testing historical data access...');

        try {
            $yesterday = now()->subDay();
            $historicalPrices = $this->nordpool->getDayAheadPrices($yesterday);
            
            if (!empty($historicalPrices) && count($historicalPrices) > 0) {
                $this->info("✅ Historical data available for {$yesterday->format('Y-m-d')}");
                
                $avgHistorical = array_sum($historicalPrices) / count($historicalPrices);
                $currentPrice = $this->nordpool->getCurrentPrice();
                $priceTrend = $currentPrice > $avgHistorical ? 'increasing' : 'decreasing';
                
                $this->line("   Yesterday's average: " . number_format($avgHistorical, 3) . " SEK/kWh");
                $this->line("   Today's current: " . number_format($currentPrice, 3) . " SEK/kWh");
                $this->line("   Price trend: {$priceTrend}");
            } else {
                $this->warn('⚠️ Historical data not available or incomplete');
            }
            
        } catch (\Exception $e) {
            $this->error('❌ Failed to access historical data');
            $this->error("   Error: {$e->getMessage()}");
        }
    }

    private function showOptimizationRecommendations(): void
    {
        $this->info('🎯 Battery optimization recommendations based on current prices...');

        try {
            $currentPrice = $this->nordpool->getCurrentPrice();
            $prices24h = $this->nordpool->getNext24HourPrices();
            
            // Simple optimization logic
            $avgPrice = array_sum($prices24h) / count($prices24h);
            $minPrice = min($prices24h);
            $maxPrice = max($prices24h);
            
            $this->table(['Scenario', 'Recommendation', 'Reason'], [
                [
                    'Current Price vs Average',
                    $currentPrice < $avgPrice ? 'CHARGE' : 'DISCHARGE',
                    $currentPrice < $avgPrice ? 'Below average price' : 'Above average price'
                ],
                [
                    'vs Daily Minimum',
                    $currentPrice <= ($minPrice * 1.1) ? 'FORCE CHARGE' : 'WAIT',
                    $currentPrice <= ($minPrice * 1.1) ? 'Near daily minimum' : 'Wait for lower prices'
                ],
                [
                    'vs Daily Maximum', 
                    $currentPrice >= ($maxPrice * 0.9) ? 'FORCE DISCHARGE' : 'NORMAL',
                    $currentPrice >= ($maxPrice * 0.9) ? 'Near daily maximum' : 'Normal operation'
                ]
            ]);
            
            // Show specific thresholds
            $this->newLine();
            $this->line('Optimization thresholds:');
            $this->line("  Very low (force charge): ≤ " . number_format($minPrice * 1.05, 3) . " SEK/kWh");
            $this->line("  Low (charge): ≤ " . number_format($avgPrice * 0.8, 3) . " SEK/kWh");
            $this->line("  High (discharge): ≥ " . number_format($avgPrice * 1.2, 3) . " SEK/kWh");
            $this->line("  Very high (force discharge): ≥ " . number_format($maxPrice * 0.95, 3) . " SEK/kWh");
            
        } catch (\Exception $e) {
            $this->error('❌ Failed to generate optimization recommendations');
            $this->error("   Error: {$e->getMessage()}");
        }
    }

    private function categorizePriceLevel(float $price): string
    {
        if ($price <= 0.10) return 'Very Low';
        if ($price <= 0.30) return 'Low';
        if ($price >= 1.00) return 'Very High';
        if ($price >= 0.60) return 'High';
        return 'Medium';
    }

    private function getOptimizationSuggestion(float $price): string
    {
        if ($price <= 0.10) return 'FORCE CHARGE from grid';
        if ($price <= 0.30) return 'CHARGE (supplement with grid)';
        if ($price >= 1.00) return 'FORCE DISCHARGE (avoid grid)';
        if ($price >= 0.60) return 'DISCHARGE (use battery)';
        return 'SELF-CONSUMPTION (normal operation)';
    }
}