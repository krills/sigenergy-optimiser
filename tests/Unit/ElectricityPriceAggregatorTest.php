<?php

namespace Tests\Unit;

use App\Contracts\PriceProviderInterface;
use App\Services\ElectricityPriceAggregator;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Mockery;

class ElectricityPriceAggregatorTest extends TestCase
{
    private ElectricityPriceAggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aggregator = new ElectricityPriceAggregator();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_register_price_providers(): void
    {
        $mockProvider = $this->createMockProvider('test_provider', 'Test Provider');
        
        $this->aggregator->registerProvider($mockProvider, 8);
        
        $status = $this->aggregator->getProviderStatus();
        $this->assertArrayHasKey('test_provider', $status);
        $this->assertEquals(8, $status['test_provider']['weight']);
    }

    /** @test */
    public function it_returns_single_provider_price_when_only_one_available(): void
    {
        $mockProvider = $this->createMockProvider('solo_provider', 'Solo Provider');
        $mockProvider->shouldReceive('isAvailable')->andReturn(true);
        $mockProvider->shouldReceive('getCurrentPrice')->andReturn(0.45);
        
        // Create new aggregator without default providers
        $aggregator = new ElectricityPriceAggregator();
        $aggregator->registerProvider($mockProvider, 10);
        
        $consensus = $aggregator->getPriceConsensus();
        
        $this->assertTrue($consensus['consensus']);
        $this->assertEquals(0.45, $consensus['consensus_price']);
        $this->assertEquals('single_provider', $consensus['method']);
        $this->assertEquals('medium', $consensus['confidence']);
    }

    /** @test */
    public function it_calculates_weighted_consensus_when_providers_agree(): void
    {
        $provider1 = $this->createMockProvider('provider1', 'Provider 1');
        $provider1->shouldReceive('isAvailable')->andReturn(true);
        $provider1->shouldReceive('getCurrentPrice')->andReturn(0.50);
        
        $provider2 = $this->createMockProvider('provider2', 'Provider 2');
        $provider2->shouldReceive('isAvailable')->andReturn(true);
        $provider2->shouldReceive('getCurrentPrice')->andReturn(0.52);
        
        $aggregator = new ElectricityPriceAggregator();
        $aggregator->registerProvider($provider1, 10); // Higher weight
        $aggregator->registerProvider($provider2, 5);  // Lower weight
        
        $consensus = $aggregator->getPriceConsensus();
        
        $this->assertTrue($consensus['consensus']);
        $this->assertEquals('weighted_consensus', $consensus['method']);
        $this->assertEquals('high', $consensus['confidence']);
        
        // Weighted average: (0.50 * 10 + 0.52 * 5) / (10 + 5) = 0.507
        $this->assertEqualsWithDelta(0.507, $consensus['consensus_price'], 0.01);
    }

    /** @test */
    public function it_detects_outliers_and_uses_filtered_median(): void
    {
        $provider1 = $this->createMockProvider('provider1', 'Provider 1');
        $provider1->shouldReceive('isAvailable')->andReturn(true);
        $provider1->shouldReceive('getCurrentPrice')->andReturn(0.50);
        
        $provider2 = $this->createMockProvider('provider2', 'Provider 2');
        $provider2->shouldReceive('isAvailable')->andReturn(true);
        $provider2->shouldReceive('getCurrentPrice')->andReturn(0.52);
        
        $outlierProvider = $this->createMockProvider('outlier', 'Outlier Provider');
        $outlierProvider->shouldReceive('isAvailable')->andReturn(true);
        $outlierProvider->shouldReceive('getCurrentPrice')->andReturn(2.50); // Outlier
        
        $aggregator = new ElectricityPriceAggregator();
        $aggregator->registerProvider($provider1, 10);
        $aggregator->registerProvider($provider2, 10);
        $aggregator->registerProvider($outlierProvider, 5);
        
        $consensus = $aggregator->getPriceConsensus();
        
        $this->assertFalse($consensus['consensus']);
        $this->assertEquals('outlier_filtered_median', $consensus['method']);
        $this->assertContains('outlier', $consensus['outliers']);
        
        // Should be median of non-outliers (0.50, 0.52) = 0.51
        $this->assertEqualsWithDelta(0.51, $consensus['consensus_price'], 0.01);
    }

    /** @test */
    public function it_handles_all_providers_unavailable_gracefully(): void
    {
        $provider1 = $this->createMockProvider('provider1', 'Provider 1');
        $provider1->shouldReceive('isAvailable')->andReturn(false);
        
        $provider2 = $this->createMockProvider('provider2', 'Provider 2');
        $provider2->shouldReceive('isAvailable')->andReturn(false);
        
        $aggregator = new ElectricityPriceAggregator();
        $aggregator->registerProvider($provider1, 10);
        $aggregator->registerProvider($provider2, 8);
        
        $consensus = $aggregator->getPriceConsensus();
        
        $this->assertFalse($consensus['consensus']);
        $this->assertEquals('fallback', $consensus['method']);
        $this->assertEquals(0.50, $consensus['consensus_price']); // Default fallback
        $this->assertEquals('low', $consensus['confidence']);
    }

    /** @test */
    public function it_returns_best_provider_based_on_reliability_and_weight(): void
    {
        $lowReliabilityProvider = $this->createMockProvider('low_rel', 'Low Reliability');
        $lowReliabilityProvider->shouldReceive('isAvailable')->andReturn(true);
        $lowReliabilityProvider->shouldReceive('getReliabilityScore')->andReturn(30);
        
        $highReliabilityProvider = $this->createMockProvider('high_rel', 'High Reliability');
        $highReliabilityProvider->shouldReceive('isAvailable')->andReturn(true);
        $highReliabilityProvider->shouldReceive('getReliabilityScore')->andReturn(90);
        
        $aggregator = new ElectricityPriceAggregator();
        $aggregator->registerProvider($lowReliabilityProvider, 10);  // High weight, low reliability
        $aggregator->registerProvider($highReliabilityProvider, 8);  // Medium weight, high reliability
        
        $bestProvider = $aggregator->getBestProvider();
        
        // Should pick high reliability provider (90 * 8 = 720 vs 30 * 10 = 300)
        $this->assertEquals('high_rel', $bestProvider->getProviderName());
    }

    /** @test */
    public function it_provides_comprehensive_provider_status(): void
    {
        $mockProvider = $this->createMockProvider('test_provider', 'Test Provider');
        $mockProvider->shouldReceive('isAvailable')->andReturn(true);
        $mockProvider->shouldReceive('getReliabilityScore')->andReturn(75);
        $mockProvider->shouldReceive('getDataFreshness')->andReturn(Carbon::now()->subMinutes(10));
        $mockProvider->shouldReceive('getProviderInfo')->andReturn([
            'name' => 'test_provider',
            'base_url' => 'https://test.example.com'
        ]);
        $mockProvider->shouldReceive('getCurrentPrice')->andReturn(0.42);
        
        $aggregator = new ElectricityPriceAggregator();
        $aggregator->registerProvider($mockProvider, 9);
        
        $status = $aggregator->getProviderStatus();
        
        $this->assertArrayHasKey('test_provider', $status);
        
        $providerStatus = $status['test_provider'];
        $this->assertEquals('test_provider', $providerStatus['name']);
        $this->assertEquals(9, $providerStatus['weight']);
        $this->assertTrue($providerStatus['is_available']);
        $this->assertEquals(75, $providerStatus['reliability_score']);
        $this->assertEquals(0.42, $providerStatus['current_price']);
        $this->assertEquals('pass', $providerStatus['price_test']);
    }

    /** @test */
    public function it_tests_all_providers_comprehensively(): void
    {
        $workingProvider = $this->createMockProvider('working', 'Working Provider');
        $this->setupMockProvider($workingProvider, true, 85, 0.45);
        
        $failingProvider = $this->createMockProvider('failing', 'Failing Provider');
        $this->setupMockProvider($failingProvider, false, 20, null, new \Exception('API Error'));
        
        $aggregator = new ElectricityPriceAggregator();
        $aggregator->registerProvider($workingProvider, 10);
        $aggregator->registerProvider($failingProvider, 8);
        
        $testResults = $aggregator->testAllProviders();
        
        // Check structure
        $this->assertArrayHasKey('working', $testResults);
        $this->assertArrayHasKey('failing', $testResults);
        $this->assertArrayHasKey('_summary', $testResults);
        
        // Check summary
        $summary = $testResults['_summary'];
        $this->assertEquals(2, $summary['total_providers']);
        $this->assertEquals(1, $summary['available_providers']);
        
        // Check individual results
        $this->assertTrue($testResults['working']['current_price_test']['success']);
        $this->assertFalse($testResults['failing']['current_price_test']['success']);
    }

    /** @test */
    public function it_calculates_variance_correctly(): void
    {
        $provider1 = $this->createMockProvider('provider1', 'Provider 1');
        $provider1->shouldReceive('isAvailable')->andReturn(true);
        $provider1->shouldReceive('getCurrentPrice')->andReturn(0.40);
        
        $provider2 = $this->createMockProvider('provider2', 'Provider 2');
        $provider2->shouldReceive('isAvailable')->andReturn(true);
        $provider2->shouldReceive('getCurrentPrice')->andReturn(0.60);
        
        $aggregator = new ElectricityPriceAggregator();
        $aggregator->registerProvider($provider1, 10);
        $aggregator->registerProvider($provider2, 10);
        
        $consensus = $aggregator->getPriceConsensus();
        
        // Variance of [0.40, 0.60] with mean 0.50 should be 0.01
        $this->assertEqualsWithDelta(0.01, $consensus['variance'], 0.001);
    }

    /** @test */
    public function it_handles_price_consensus_edge_cases(): void
    {
        // Test with identical prices
        $provider1 = $this->createMockProvider('provider1', 'Provider 1');
        $provider1->shouldReceive('isAvailable')->andReturn(true);
        $provider1->shouldReceive('getCurrentPrice')->andReturn(0.50);
        
        $provider2 = $this->createMockProvider('provider2', 'Provider 2');
        $provider2->shouldReceive('isAvailable')->andReturn(true);
        $provider2->shouldReceive('getCurrentPrice')->andReturn(0.50);
        
        $aggregator = new ElectricityPriceAggregator();
        $aggregator->registerProvider($provider1, 10);
        $aggregator->registerProvider($provider2, 5);
        
        $consensus = $aggregator->getPriceConsensus();
        
        $this->assertTrue($consensus['consensus']);
        $this->assertEquals(0.50, $consensus['consensus_price']);
        $this->assertEquals(0.0, $consensus['variance']);
        $this->assertEquals('weighted_consensus', $consensus['method']);
    }

    private function createMockProvider(string $name, string $description): PriceProviderInterface
    {
        $mock = Mockery::mock(PriceProviderInterface::class);
        $mock->shouldReceive('getProviderName')->andReturn($name);
        $mock->shouldReceive('getProviderDescription')->andReturn($description);
        
        return $mock;
    }

    private function setupMockProvider(
        $mock, 
        bool $isAvailable, 
        int $reliabilityScore, 
        ?float $currentPrice,
        ?\Exception $exception = null
    ): void {
        $mock->shouldReceive('isAvailable')->andReturn($isAvailable);
        $mock->shouldReceive('getReliabilityScore')->andReturn($reliabilityScore);
        $mock->shouldReceive('getDataFreshness')->andReturn(Carbon::now()->subMinutes(5));
        $mock->shouldReceive('getProviderInfo')->andReturn([
            'name' => $mock->getProviderName(),
            'description' => $mock->getProviderDescription()
        ]);
        
        if ($exception) {
            $mock->shouldReceive('getCurrentPrice')->andThrow($exception);
        } elseif ($currentPrice !== null) {
            $mock->shouldReceive('getCurrentPrice')->andReturn($currentPrice);
        }
    }
}