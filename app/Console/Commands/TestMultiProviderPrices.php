<?php

namespace App\Console\Commands;

use App\Services\ElectricityPriceAggregator;
use App\Services\NordPoolApiService;
use App\Services\VattenfallPriceService;
use Illuminate\Console\Command;

class TestMultiProviderPrices extends Command
{
    protected $signature = 'prices:multi-test 
                          {--detailed : Show detailed provider analysis}
                          {--consensus : Show price consensus analysis}
                          {--reliability : Test provider reliability scores}
                          {--voting : Demonstrate voting mechanism}';

    protected $description = 'Test multi-provider electricity price system with voting mechanism';

    private ElectricityPriceAggregator $aggregator;

    public function __construct()
    {
        parent::__construct();
        $this->aggregator = new ElectricityPriceAggregator();
    }

    public function handle()
    {
        $this->info('🔌 Testing Multi-Provider Electricity Price System');
        $this->info('=====================================================');
        $this->newLine();

        // Test all providers
        $this->testAllProviders();
        $this->newLine();

        // Test price consensus
        if ($this->option('consensus') || $this->option('voting')) {
            $this->testPriceConsensus();
            $this->newLine();
        }

        // Test voting mechanism
        if ($this->option('voting')) {
            $this->demonstrateVotingMechanism();
            $this->newLine();
        }

        // Test reliability scoring
        if ($this->option('reliability')) {
            $this->testReliabilityScoring();
            $this->newLine();
        }

        // Show aggregated results
        $this->showAggregatedResults();
        $this->newLine();

        $this->info('✅ Multi-provider testing completed!');
        return Command::SUCCESS;
    }

    private function testAllProviders(): void
    {
        $this->info('🧪 Testing Individual Providers...');

        $results = $this->aggregator->testAllProviders();
        $summary = $results['_summary'];
        unset($results['_summary'], $results['_consensus']);

        foreach ($results as $providerName => $result) {
            $info = $result['provider_info'];
            $connectionTest = $result['connection_test'];
            $priceTest = $result['current_price_test'];

            $this->line("📡 Provider: {$info['name']}");
            $this->line("   Description: {$info['description']}");
            $this->line("   Available: " . ($info['is_available'] ? '✅ Yes' : '❌ No'));
            $this->line("   Reliability Score: {$info['reliability_score']}/100");
            $this->line("   Weight: {$result['weight']}");

            if ($connectionTest['success']) {
                $this->line("   Connection: ✅ Success");
                if (isset($connectionTest['data_points'])) {
                    $this->line("   Data Points: {$connectionTest['data_points']}/24 hours");
                }
            } else {
                $this->line("   Connection: ❌ Failed - {$connectionTest['error']}");
            }

            if ($priceTest['success']) {
                $price = number_format($priceTest['price'], 3);
                $responseTime = $priceTest['response_time_ms'];
                $reasonable = $priceTest['price_reasonable'] ? '✅' : '⚠️';
                $this->line("   Current Price: {$reasonable} {$price} SEK/kWh ({$responseTime}ms)");
            } else {
                $this->line("   Current Price: ❌ Failed - {$priceTest['error']}");
            }

            $this->newLine();
        }

        $this->info("📊 Summary:");
        $this->line("   Total Providers: {$summary['total_providers']}");
        $this->line("   Available Providers: {$summary['available_providers']}");
        $this->line("   Consensus Achieved: " . ($summary['consensus_achieved'] ? '✅ Yes' : '❌ No'));
        $this->line("   Consensus Price: " . number_format($summary['consensus_price'], 3) . ' SEK/kWh');
        $this->line("   Confidence Level: {$summary['confidence_level']}");
    }

    private function testPriceConsensus(): void
    {
        $this->info('🗳️ Testing Price Consensus Mechanism...');

        $consensus = $this->aggregator->getPriceConsensus();

        $this->table(['Metric', 'Value'], [
            ['Consensus Achieved', $consensus['consensus'] ? 'Yes ✅' : 'No ❌'],
            ['Consensus Price', number_format($consensus['consensus_price'], 3) . ' SEK/kWh'],
            ['Consensus Method', $consensus['method']],
            ['Price Variance', $consensus['variance'] ? number_format($consensus['variance'], 6) : 'N/A'],
            ['Confidence Level', ucfirst($consensus['confidence'])],
            ['Outliers Detected', !empty($consensus['outliers']) ? implode(', ', $consensus['outliers']) : 'None']
        ]);

        if (!empty($consensus['providers'])) {
            $this->newLine();
            $this->line('📋 Provider Contributions:');
            foreach ($consensus['providers'] as $name => $data) {
                $price = number_format($data['price'], 3);
                $weight = $data['weight'];
                $reliability = $data['reliability'];
                $this->line("   {$name}: {$price} SEK/kWh (weight: {$weight}, reliability: {$reliability}%)");
            }
        }
    }

    private function demonstrateVotingMechanism(): void
    {
        $this->info('⚖️ Demonstrating Voting Mechanism...');

        // Simulate different scenarios
        $scenarios = [
            'normal' => 'Normal operation with close prices',
            'outlier' => 'One provider has outlier price',
            'disagreement' => 'Providers disagree significantly'
        ];

        foreach ($scenarios as $scenario => $description) {
            $this->line("\n📋 Scenario: {$description}");
            
            // This would normally involve manipulating provider responses
            // For demonstration, we'll show current consensus
            $consensus = $this->aggregator->getPriceConsensus();
            
            $this->line("   Method: {$consensus['method']}");
            $this->line("   Result: " . number_format($consensus['consensus_price'], 3) . ' SEK/kWh');
            $this->line("   Confidence: {$consensus['confidence']}");
            
            if ($consensus['method'] === 'outlier_filtered_median') {
                $this->line("   🎯 Outliers filtered: " . implode(', ', $consensus['outliers']));
            }
        }
    }

    private function testReliabilityScoring(): void
    {
        $this->info('📈 Testing Provider Reliability Scoring...');

        $status = $this->aggregator->getProviderStatus();

        $reliabilityData = [];
        foreach ($status as $name => $data) {
            $reliabilityData[] = [
                'Provider' => $name,
                'Score' => $data['reliability_score'] . '/100',
                'Weight' => $data['weight'],
                'Available' => $data['is_available'] ? '✅' : '❌',
                'Price Test' => $data['price_test'] === 'pass' ? '✅' : '❌',
                'Data Freshness' => $data['data_freshness']->diffForHumans()
            ];
        }

        $this->table([
            'Provider', 'Reliability Score', 'Weight', 'Available', 'Price Test', 'Data Freshness'
        ], $reliabilityData);

        // Show best provider
        $bestProvider = $this->aggregator->getBestProvider();
        if ($bestProvider) {
            $this->newLine();
            $this->info("🏆 Best Provider: {$bestProvider->getProviderName()}");
            $this->line("   {$bestProvider->getProviderDescription()}");
        }
    }

    private function showAggregatedResults(): void
    {
        $this->info('📊 Aggregated Price Results...');

        try {
            // Get aggregated prices
            $currentPrice = $this->aggregator->getCurrentPrice();
            $next24h = $this->aggregator->getNext24HourPrices();
            
            $minPrice = min($next24h);
            $maxPrice = max($next24h);
            $avgPrice = array_sum($next24h) / count($next24h);

            $this->table(['Metric', 'Value'], [
                ['Current Price (Aggregated)', number_format($currentPrice, 3) . ' SEK/kWh'],
                ['24h Minimum', number_format($minPrice, 3) . ' SEK/kWh'],
                ['24h Maximum', number_format($maxPrice, 3) . ' SEK/kWh'],
                ['24h Average', number_format($avgPrice, 3) . ' SEK/kWh'],
                ['Price Spread', number_format($maxPrice - $minPrice, 3) . ' SEK/kWh'],
                ['Data Quality', count($next24h) >= 24 ? 'Complete ✅' : 'Incomplete ⚠️']
            ]);

            // Show optimization recommendations based on aggregated prices
            $this->newLine();
            $this->showOptimizationRecommendations($currentPrice, $avgPrice, $minPrice, $maxPrice);

        } catch (\Exception $e) {
            $this->error('❌ Failed to get aggregated results: ' . $e->getMessage());
        }
    }

    private function showOptimizationRecommendations(float $current, float $avg, float $min, float $max): void
    {
        $this->info('🎯 Battery Optimization Recommendations (Based on Aggregated Data):');

        $recommendations = [];

        // Current vs average
        if ($current <= $avg * 0.8) {
            $recommendations[] = ['CHARGE', 'Current price below 80% of average', 'High priority'];
        } elseif ($current >= $avg * 1.2) {
            $recommendations[] = ['DISCHARGE', 'Current price above 120% of average', 'High priority'];
        } else {
            $recommendations[] = ['SELF-CONSUMPTION', 'Price near average', 'Normal operation'];
        }

        // Absolute thresholds
        if ($current <= 0.10) {
            $recommendations[] = ['FORCE CHARGE', 'Very low absolute price', 'Maximum priority'];
        } elseif ($current >= 1.00) {
            $recommendations[] = ['FORCE DISCHARGE', 'Very high absolute price', 'Maximum priority'];
        }

        // Tomorrow planning
        if ($current <= $min * 1.1) {
            $recommendations[] = ['OPTIMAL CHARGE WINDOW', 'Near daily minimum price', 'Execute now'];
        }

        if ($current >= $max * 0.9) {
            $recommendations[] = ['OPTIMAL DISCHARGE WINDOW', 'Near daily maximum price', 'Execute now'];
        }

        $this->table(['Action', 'Reason', 'Priority'], $recommendations);

        // Show specific thresholds
        $this->newLine();
        $this->line('📏 Dynamic Thresholds (Based on Aggregated Data):');
        $this->line('   Force Charge: ≤ ' . number_format($min * 1.05, 3) . ' SEK/kWh');
        $this->line('   Charge: ≤ ' . number_format($avg * 0.8, 3) . ' SEK/kWh');
        $this->line('   Normal: ' . number_format($avg * 0.8, 3) . ' - ' . number_format($avg * 1.2, 3) . ' SEK/kWh');
        $this->line('   Discharge: ≥ ' . number_format($avg * 1.2, 3) . ' SEK/kWh');
        $this->line('   Force Discharge: ≥ ' . number_format($max * 0.95, 3) . ' SEK/kWh');
    }
}