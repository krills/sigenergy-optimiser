<?php

namespace App\Services;

use App\Contracts\PriceProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class VattenfallPriceService implements PriceProviderInterface
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = 'https://www.vattenfall.se/api/price/spot/pricearea';
    }

    /**
     * Get the provider name/identifier
     */
    public function getProviderName(): string
    {
        return 'vattenfall';
    }

    /**
     * Get the provider description
     */
    public function getProviderDescription(): string
    {
        return 'Vattenfall Swedish Electricity Spot Prices API';
    }

    /**
     * Get current electricity price for Stockholm (SE3 area)
     */
    public function getCurrentPrice(): float
    {
        $cacheKey = 'vattenfall_current_price_' . now()->format('Y-m-d_H');
        
        return Cache::remember($cacheKey, 1800, function () {
            try {
                $today = now()->format('Y-m-d');
                $response = Http::timeout(15)->get("{$this->baseUrl}/{$today}/{$today}/SN3");
                
                if (!$response->successful()) {
                    Log::warning('Vattenfall API returned non-successful response', [
                        'status' => $response->status(),
                        'body' => $response->body()
                    ]);
                    return 0.50; // Fallback price
                }
                
                $data = $response->json();
                $currentHour = now()->hour;
                
                // Find current hour price
                if (isset($data) && is_array($data)) {
                    foreach ($data as $entry) {
                        if (isset($entry['TimeStamp'], $entry['Value'])) {
                            $timestamp = Carbon::parse($entry['TimeStamp']);
                            if ($timestamp->hour === $currentHour && $timestamp->isToday()) {
                                // Convert from öre/kWh to SEK/kWh
                                return floatval($entry['Value']) / 100;
                            }
                        }
                    }
                }
                
                Log::warning('No current hour price found in Vattenfall response');
                return 0.50; // Fallback price
                
            } catch (\Exception $e) {
                Log::error('Failed to get current price from Vattenfall', [
                    'error' => $e->getMessage()
                ]);
                return 0.50; // Safe fallback price
            }
        });
    }

    /**
     * Get next 24 hours of electricity prices
     */
    public function getNext24HourPrices(): array
    {
        $cacheKey = 'vattenfall_24h_prices_' . now()->format('Y-m-d');
        
        return Cache::remember($cacheKey, 3600, function () {
            try {
                $today = now()->format('Y-m-d');
                $tomorrow = now()->addDay()->format('Y-m-d');
                
                $todayPrices = $this->getDayAheadPrices();
                $tomorrowPrices = $this->getDayAheadPrices(now()->addDay());
                
                $currentHour = now()->hour;
                $next24Hours = [];
                
                // Get remaining hours today
                for ($i = $currentHour; $i < 24; $i++) {
                    $next24Hours[] = $todayPrices[$i] ?? 0.50;
                }
                
                // Fill with tomorrow's prices
                $remainingHours = 24 - count($next24Hours);
                for ($i = 0; $i < $remainingHours; $i++) {
                    $next24Hours[] = $tomorrowPrices[$i] ?? 0.50;
                }
                
                return $next24Hours;
            } catch (\Exception $e) {
                Log::error('Failed to get 24h prices from Vattenfall', [
                    'error' => $e->getMessage()
                ]);
                return array_fill(0, 24, 0.50);
            }
        });
    }

    /**
     * Get day-ahead prices for today
     */
    public function getDayAheadPrices(Carbon $date = null): array
    {
        $date = $date ?? now();
        $cacheKey = 'vattenfall_day_ahead_' . $date->format('Y-m-d');
        
        return Cache::remember($cacheKey, 3600, function () use ($date) {
            try {
                $dateStr = $date->format('Y-m-d');
                $response = Http::timeout(15)->get("{$this->baseUrl}/{$dateStr}/{$dateStr}/SN3");
                
                if (!$response->successful()) {
                    Log::warning('Vattenfall API returned non-successful response for day-ahead', [
                        'status' => $response->status(),
                        'date' => $dateStr
                    ]);
                    return array_fill(0, 24, 0.50);
                }
                
                $data = $response->json();
                $prices = array_fill(0, 24, 0.50); // Initialize with fallback prices
                
                if (isset($data) && is_array($data)) {
                    foreach ($data as $entry) {
                        if (isset($entry['TimeStamp'], $entry['Value'])) {
                            $timestamp = Carbon::parse($entry['TimeStamp']);
                            $hour = $timestamp->hour;
                            
                            if ($hour >= 0 && $hour < 24) {
                                // Convert from öre/kWh to SEK/kWh
                                $prices[$hour] = floatval($entry['Value']) / 100;
                            }
                        }
                    }
                }
                
                return $prices;
                
            } catch (\Exception $e) {
                Log::error('Failed to get day-ahead prices from Vattenfall', [
                    'error' => $e->getMessage(),
                    'date' => $date->format('Y-m-d')
                ]);
                return array_fill(0, 24, 0.50);
            }
        });
    }

    /**
     * Get tomorrow's electricity prices
     */
    public function getTomorrowPrices(): array
    {
        try {
            return $this->getDayAheadPrices(now()->addDay());
        } catch (\Exception $e) {
            Log::error('Failed to get tomorrow prices from Vattenfall', [
                'error' => $e->getMessage()
            ]);
            return array_fill(0, 24, 0.50);
        }
    }

    /**
     * Get historical prices for analysis
     */
    public function getHistoricalPrices(Carbon $startDate, Carbon $endDate): array
    {
        $prices = [];
        $currentDate = $startDate->copy();
        
        while ($currentDate <= $endDate && $prices->count() < 30) { // Limit to 30 days
            try {
                $dayPrices = $this->getDayAheadPrices($currentDate);
                $prices[$currentDate->format('Y-m-d')] = [
                    'date' => $currentDate->format('Y-m-d'),
                    'prices' => $dayPrices,
                    'avg' => array_sum($dayPrices) / count($dayPrices),
                    'min' => min($dayPrices),
                    'max' => max($dayPrices)
                ];
            } catch (\Exception $e) {
                Log::warning('Failed to get historical price for date', [
                    'date' => $currentDate->format('Y-m-d'),
                    'error' => $e->getMessage()
                ]);
            }
            
            $currentDate->addDay();
        }
        
        return $prices;
    }

    /**
     * Test API connectivity and data retrieval
     */
    public function testConnection(): array
    {
        try {
            $today = now()->format('Y-m-d');
            $response = Http::timeout(10)->get("{$this->baseUrl}/{$today}/{$today}/SN3");
            
            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => 'API connectivity failed with status: ' . $response->status(),
                    'api_provider' => 'vattenfall',
                    'timestamp' => now()
                ];
            }
            
            $data = $response->json();
            $dataCount = is_array($data) ? count($data) : 0;
            
            return [
                'success' => $dataCount > 0,
                'api_provider' => 'Vattenfall Swedish Electricity Spot Prices',
                'data_points' => $dataCount,
                'expected_points' => 24,
                'data_quality' => $dataCount >= 20 ? 'good' : 'limited',
                'timestamp' => now()
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'api_provider' => 'vattenfall',
                'timestamp' => now()
            ];
        }
    }

    /**
     * Check if the provider is currently available and working
     */
    public function isAvailable(): bool
    {
        try {
            $today = now()->format('Y-m-d');
            $response = Http::timeout(8)->get("{$this->baseUrl}/{$today}/{$today}/SN3");
            $data = $response->json();
            
            return $response->successful() && is_array($data) && count($data) > 10;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get provider reliability score (0-100)
     */
    public function getReliabilityScore(): int
    {
        $cacheKey = 'vattenfall_reliability_score';
        
        return Cache::remember($cacheKey, 1800, function () {
            try {
                $tests = [
                    'current_data' => $this->testCurrentDataAvailability(),
                    'historical_data' => $this->testHistoricalDataAvailability(),
                    'response_time' => $this->testResponseTime()
                ];
                
                $score = 0;
                $score += $tests['current_data'] ? 35 : 0;
                $score += $tests['historical_data'] ? 35 : 0;
                $score += $tests['response_time'] ? 30 : 0;
                
                return $score;
            } catch (\Exception $e) {
                Log::warning('Failed to calculate reliability score for Vattenfall', [
                    'error' => $e->getMessage()
                ]);
                return 0;
            }
        });
    }

    /**
     * Get data freshness timestamp
     */
    public function getDataFreshness(): Carbon
    {
        try {
            $today = now()->format('Y-m-d');
            $response = Http::timeout(8)->get("{$this->baseUrl}/{$today}/{$today}/SN3");
            
            if ($response->successful()) {
                $data = $response->json();
                if (is_array($data) && !empty($data)) {
                    // Find the most recent timestamp
                    $latest = null;
                    foreach ($data as $entry) {
                        if (isset($entry['TimeStamp'])) {
                            $timestamp = Carbon::parse($entry['TimeStamp']);
                            if ($latest === null || $timestamp->isAfter($latest)) {
                                $latest = $timestamp;
                            }
                        }
                    }
                    
                    if ($latest) {
                        return $latest;
                    }
                }
            }
        } catch (\Exception $e) {
            // Fallback to current time minus 1 hour
        }
        
        return now()->subHour();
    }

    /**
     * Get provider configuration and metadata
     */
    public function getProviderInfo(): array
    {
        return [
            'name' => $this->getProviderName(),
            'description' => $this->getProviderDescription(),
            'base_url' => $this->baseUrl,
            'data_source' => 'Vattenfall',
            'price_areas' => ['SN3'], // Stockholm area
            'currencies' => ['SEK'],
            'update_frequency' => 'hourly',
            'historical_data_start' => 'variable',
            'authentication_required' => false,
            'rate_limiting' => 'unknown',
            'reliability_score' => $this->getReliabilityScore(),
            'data_freshness' => $this->getDataFreshness(),
            'is_available' => $this->isAvailable()
        ];
    }

    /**
     * Test current data availability
     */
    private function testCurrentDataAvailability(): bool
    {
        try {
            $price = $this->getCurrentPrice();
            return $price > 0 && $price < 10; // Reasonable price range check
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Test historical data availability
     */
    private function testHistoricalDataAvailability(): bool
    {
        try {
            $yesterday = now()->subDay();
            $prices = $this->getDayAheadPrices($yesterday);
            return count($prices) >= 15; // Should have most hours available
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Test response time
     */
    private function testResponseTime(): bool
    {
        try {
            $start = microtime(true);
            $today = now()->format('Y-m-d');
            $response = Http::timeout(5)->get("{$this->baseUrl}/{$today}/{$today}/SN3");
            $responseTime = (microtime(true) - $start) * 1000;
            
            return $response->successful() && $responseTime < 3000; // Under 3 seconds
        } catch (\Exception $e) {
            return false;
        }
    }
}