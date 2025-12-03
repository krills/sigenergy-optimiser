<?php

namespace App\Services;

use App\Contracts\PriceProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ElectricityPriceAggregator
{
    private array $providers = [];
    private array $providerWeights = [];
    private float $consensusThreshold = 0.15; // 15% difference threshold for consensus
    private int $minProvidersForConsensus = 2;

    public function __construct()
    {
        $this->initializeProviders();
    }

    /**
     * Initialize and register price providers
     */
    private function initializeProviders(): void
    {
        // Register providers with their weights (higher = more trusted)
        $this->registerProvider(new NordPoolApiService(), 10); // Primary provider
        $this->registerProvider(new VattenfallPriceService(), 8); // Secondary provider
        
        Log::info('ElectricityPriceAggregator initialized with providers', [
            'providers' => array_keys($this->providers),
            'weights' => $this->providerWeights
        ]);
    }

    /**
     * Register a price provider with weight
     */
    public function registerProvider(PriceProviderInterface $provider, $weight = 5): void
    {
        $name = $provider->getProviderName();
        $this->providers[$name] = $provider;
        $this->providerWeights[$name] = $weight;
        
        Log::info('Price provider registered', [
            'provider' => $name,
            'weight' => $weight,
            'description' => $provider->getProviderDescription()
        ]);
    }

    /**
     * Get current electricity price using voting mechanism
     */
    public function getCurrentPrice(): float
    {
        $cacheKey = 'aggregated_current_price_' . now()->format('Y-m-d_H');
        
        return Cache::remember($cacheKey, 900, function () { // Cache for 15 minutes
            return $this->getConsensusPrice('current');
        });
    }

    /**
     * Get next 24 hours of electricity prices using aggregation
     */
    public function getNext24HourPrices(): array
    {
        $cacheKey = 'aggregated_24h_prices_' . now()->format('Y-m-d_H');
        
        return Cache::remember($cacheKey, 1800, function () { // Cache for 30 minutes
            return $this->getConsensusPriceArray('next24h');
        });
    }

    /**
     * Get day-ahead prices using aggregation
     */
    public function getDayAheadPrices(Carbon $date = null): array
    {
        $date = $date ?? now();
        $cacheKey = 'aggregated_day_ahead_' . $date->format('Y-m-d');
        
        return Cache::remember($cacheKey, 3600, function () use ($date) {
            return $this->getConsensusPriceArray('dayahead', $date);
        });
    }

    /**
     * Get provider status and reliability information
     */
    public function getProviderStatus(): array
    {
        $status = [];
        
        foreach ($this->providers as $name => $provider) {
            $info = [
                'name' => $name,
                'description' => $provider->getProviderDescription(),
                'weight' => $this->providerWeights[$name],
                'is_available' => $provider->isAvailable(),
                'reliability_score' => $provider->getReliabilityScore(),
                'data_freshness' => $provider->getDataFreshness(),
                'info' => $provider->getProviderInfo()
            ];
            
            // Test current price availability
            try {
                $testPrice = $provider->getCurrentPrice();
                $info['current_price'] = $testPrice;
                $info['price_test'] = $testPrice > 0 && $testPrice < 10 ? 'pass' : 'suspicious';
            } catch (\Exception $e) {
                $info['current_price'] = null;
                $info['price_test'] = 'failed';
                $info['error'] = $e->getMessage();
            }
            
            $status[$name] = $info;
        }
        
        return $status;
    }

    /**
     * Get voting results and consensus information
     */
    public function getPriceConsensus(): array
    {
        $providerPrices = [];
        $availableProviders = [];
        
        // Collect prices from all providers
        foreach ($this->providers as $name => $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }
            
            try {
                $price = $provider->getCurrentPrice();
                if ($price > 0 && $price < 10) { // Basic sanity check
                    $providerPrices[$name] = $price;
                    $availableProviders[$name] = [
                        'price' => $price,
                        'weight' => $this->providerWeights[$name],
                        'reliability' => $provider->getReliabilityScore()
                    ];
                }
            } catch (\Exception $e) {
                Log::warning('Provider failed to provide price', [
                    'provider' => $name,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        if (empty($providerPrices)) {
            return [
                'consensus' => false,
                'consensus_price' => 0.50,
                'method' => 'fallback',
                'providers' => [],
                'variance' => null,
                'confidence' => 'low'
            ];
        }
        
        // Calculate consensus
        $consensus = $this->calculatePriceConsensus($availableProviders);
        
        return [
            'consensus' => $consensus['has_consensus'],
            'consensus_price' => $consensus['price'],
            'method' => $consensus['method'],
            'providers' => $availableProviders,
            'variance' => $consensus['variance'],
            'confidence' => $consensus['confidence'],
            'outliers' => $consensus['outliers'] ?? []
        ];
    }

    /**
     * Get consensus price using voting mechanism
     */
    private function getConsensusPrice(string $type, Carbon $date = null): float
    {
        $providerPrices = [];
        $providerWeights = [];
        
        foreach ($this->providers as $name => $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }
            
            try {
                $price = match ($type) {
                    'current' => $provider->getCurrentPrice(),
                    'dayahead' => $provider->getDayAheadPrices($date)[now()->hour] ?? null,
                    default => null
                };
                
                if ($price && $price > 0 && $price < 10) {
                    $providerPrices[$name] = $price;
                    $providerWeights[$name] = $this->providerWeights[$name];
                }
            } catch (\Exception $e) {
                Log::warning('Provider failed during consensus calculation', [
                    'provider' => $name,
                    'type' => $type,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        if (empty($providerPrices)) {
            Log::warning('No providers available for price consensus', ['type' => $type]);
            return 0.50; // Fallback price
        }
        
        $consensus = $this->calculatePriceConsensus($providerPrices, $providerWeights);
        
        Log::info('Price consensus calculated', [
            'type' => $type,
            'consensus_price' => $consensus['price'],
            'method' => $consensus['method'],
            'providers_used' => array_keys($providerPrices),
            'has_consensus' => $consensus['has_consensus']
        ]);
        
        return $consensus['price'];
    }

    /**
     * Get consensus price array for 24-hour data
     */
    private function getConsensusPriceArray(string $type, Carbon $date = null): array
    {
        $providerDataSets = [];
        
        foreach ($this->providers as $name => $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }
            
            try {
                $data = match ($type) {
                    'next24h' => $provider->getNext24HourPrices(),
                    'dayahead' => $provider->getDayAheadPrices($date),
                    default => []
                };
                
                if (count($data) >= 20) { // Must have reasonable amount of data
                    $providerDataSets[$name] = $data;
                }
            } catch (\Exception $e) {
                Log::warning('Provider failed during array consensus calculation', [
                    'provider' => $name,
                    'type' => $type,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        if (empty($providerDataSets)) {
            Log::warning('No providers available for array consensus', ['type' => $type]);
            return array_fill(0, 24, 0.50);
        }
        
        return $this->calculateArrayConsensus($providerDataSets);
    }

    /**
     * Calculate price consensus from multiple provider prices
     */
    private function calculatePriceConsensus(array $providers, array $weights = null): array
    {
        if (empty($providers)) {
            return [
                'price' => 0.50,
                'method' => 'fallback',
                'has_consensus' => false,
                'variance' => null,
                'confidence' => 'low'
            ];
        }
        
        if (count($providers) === 1) {
            $price = reset($providers);
            $price = is_array($price) ? $price['price'] : $price;
            return [
                'price' => $price,
                'method' => 'single_provider',
                'has_consensus' => true,
                'variance' => 0,
                'confidence' => 'medium'
            ];
        }
        
        // Extract prices if providers is an array of provider info
        $prices = [];
        $providerWeights = [];
        
        foreach ($providers as $name => $data) {
            if (is_array($data) && isset($data['price'])) {
                $prices[$name] = $data['price'];
                $providerWeights[$name] = $data['weight'] ?? $this->providerWeights[$name] ?? 5;
            } else {
                $prices[$name] = $data;
                $providerWeights[$name] = $weights[$name] ?? $this->providerWeights[$name] ?? 5;
            }
        }
        
        // Calculate basic statistics
        $priceValues = array_values($prices);
        $mean = array_sum($priceValues) / count($priceValues);
        $variance = $this->calculateVariance($priceValues);
        $maxDiff = max($priceValues) - min($priceValues);
        
        // Check for consensus (prices within threshold)
        $hasConsensus = $maxDiff <= ($mean * $this->consensusThreshold);
        
        if ($hasConsensus && count($providers) >= $this->minProvidersForConsensus) {
            // Weighted average for consensus
            $weightedSum = 0;
            $totalWeight = 0;
            
            foreach ($prices as $name => $price) {
                $weight = $providerWeights[$name];
                $weightedSum += $price * $weight;
                $totalWeight += $weight;
            }
            
            $consensusPrice = $totalWeight > 0 ? $weightedSum / $totalWeight : $mean;
            
            return [
                'price' => $consensusPrice,
                'method' => 'weighted_consensus',
                'has_consensus' => true,
                'variance' => $variance,
                'confidence' => 'high'
            ];
        }
        
        // No consensus - identify outliers and use median of non-outliers
        $outliers = $this->identifyOutliers($prices, $mean, $variance);
        $filteredPrices = array_diff_key($prices, array_flip($outliers));
        
        if (!empty($filteredPrices)) {
            $filteredValues = array_values($filteredPrices);
            sort($filteredValues);
            $medianPrice = $this->calculateMedian($filteredValues);
            
            return [
                'price' => $medianPrice,
                'method' => 'outlier_filtered_median',
                'has_consensus' => false,
                'variance' => $variance,
                'confidence' => 'medium',
                'outliers' => $outliers
            ];
        }
        
        // Fallback to simple median
        sort($priceValues);
        $medianPrice = $this->calculateMedian($priceValues);
        
        return [
            'price' => $medianPrice,
            'method' => 'simple_median',
            'has_consensus' => false,
            'variance' => $variance,
            'confidence' => 'low'
        ];
    }

    /**
     * Calculate consensus for array of 24 hourly prices
     */
    private function calculateArrayConsensus(array $providerDataSets): array
    {
        $consensusPrices = array_fill(0, 24, 0.50);
        
        for ($hour = 0; $hour < 24; $hour++) {
            $hourlyPrices = [];
            
            // Collect prices for this hour from all providers
            foreach ($providerDataSets as $providerName => $data) {
                if (isset($data[$hour]) && $data[$hour] > 0 && $data[$hour] < 10) {
                    $hourlyPrices[$providerName] = $data[$hour];
                }
            }
            
            if (!empty($hourlyPrices)) {
                $hourConsensus = $this->calculatePriceConsensus($hourlyPrices);
                $consensusPrices[$hour] = $hourConsensus['price'];
            }
        }
        
        return $consensusPrices;
    }

    /**
     * Calculate variance of price array
     */
    private function calculateVariance(array $values): float
    {
        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(fn($value) => pow($value - $mean, 2), $values);
        return array_sum($squaredDiffs) / count($squaredDiffs);
    }

    /**
     * Calculate median of array
     */
    private function calculateMedian(array $values): float
    {
        $count = count($values);
        $middle = floor($count / 2);
        
        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        } else {
            return $values[$middle];
        }
    }

    /**
     * Identify price outliers
     */
    private function identifyOutliers(array $prices, float $mean, float $variance): array
    {
        $outliers = [];
        $stdDev = sqrt($variance);
        $threshold = 2 * $stdDev; // 2 standard deviations
        
        foreach ($prices as $name => $price) {
            if (abs($price - $mean) > $threshold) {
                $outliers[] = $name;
            }
        }
        
        return $outliers;
    }

    /**
     * Get best available provider based on reliability and availability
     */
    public function getBestProvider(): ?PriceProviderInterface
    {
        $bestProvider = null;
        $bestScore = 0;
        
        foreach ($this->providers as $name => $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }
            
            $score = $provider->getReliabilityScore() * $this->providerWeights[$name];
            
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestProvider = $provider;
            }
        }
        
        return $bestProvider;
    }

    /**
     * Test all providers and return comprehensive status
     */
    public function testAllProviders(): array
    {
        $results = [];
        
        foreach ($this->providers as $name => $provider) {
            $results[$name] = [
                'provider_info' => $provider->getProviderInfo(),
                'connection_test' => $provider->testConnection(),
                'current_price_test' => $this->testProviderCurrentPrice($provider),
                'reliability_score' => $provider->getReliabilityScore(),
                'weight' => $this->providerWeights[$name]
            ];
        }
        
        // Add consensus analysis
        $consensus = $this->getPriceConsensus();
        $results['_consensus'] = $consensus;
        $results['_summary'] = [
            'total_providers' => count($this->providers),
            'available_providers' => count(array_filter($this->providers, fn($p) => $p->isAvailable())),
            'consensus_achieved' => $consensus['consensus'],
            'consensus_price' => $consensus['consensus_price'],
            'confidence_level' => $consensus['confidence']
        ];
        
        return $results;
    }

    /**
     * Test provider's current price functionality
     */
    private function testProviderCurrentPrice(PriceProviderInterface $provider): array
    {
        try {
            $start = microtime(true);
            $price = $provider->getCurrentPrice();
            $duration = (microtime(true) - $start) * 1000; // Convert to ms
            
            return [
                'success' => true,
                'price' => $price,
                'response_time_ms' => round($duration, 2),
                'price_reasonable' => $price > 0 && $price < 10,
                'timestamp' => now()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()
            ];
        }
    }
}