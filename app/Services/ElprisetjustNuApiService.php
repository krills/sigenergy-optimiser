<?php

namespace App\Services;

use App\Contracts\PriceProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ElprisetjustNuApiService implements PriceProviderInterface
{
    private string $baseUrl;
    private string $priceArea;
    private int $timeout;
    
    public function __construct()
    {
        $this->baseUrl = config('services.elprisetjustnu.base_url', 'https://www.elprisetjustnu.se/api/v1/prices');
        $this->priceArea = config('services.elprisetjustnu.default_area', 'SE3');
        $this->timeout = 15;
    }

    /**
     * Get the provider name/identifier
     */
    public function getProviderName(): string
    {
        return 'elprisetjustnu.se';
    }

    /**
     * Get the provider description
     */
    public function getProviderDescription(): string
    {
        $areaName = config('services.elprisetjustnu.areas.' . $this->priceArea, $this->priceArea);
        return "Swedish Electricity Spot Prices ({$areaName}) with 15-minute granularity via elprisetjustnu.se";
    }

    /**
     * Get current electricity price for Stockholm (SE3 area)
     */
    public function getCurrentPrice(): float
    {
        $currentTime = now();
        $todaysPrices = $this->getTodaysPrices();
        
        if (empty($todaysPrices)) {
            Log::warning('No current price data available from elprisetjustnu.se, using fallback');
            return 0.50; // Fallback price in SEK/kWh
        }
        
        // Find the 15-minute interval that contains the current time
        foreach ($todaysPrices as $priceData) {
            $startTime = Carbon::parse($priceData['time_start']);
            $endTime = Carbon::parse($priceData['time_end']);
            
            if ($currentTime->between($startTime, $endTime)) {
                return $priceData['value'];
            }
        }
        
        // Fallback to the latest available price
        $latestPrice = end($todaysPrices);
        return $latestPrice['value'] ?? 0.50;
    }

    /**
     * Get next 24 hours of electricity prices (15-minute intervals)
     */
    public function getNext24HourPrices(): array
    {
        $cacheKey = 'elprisetjustnu_24h_prices_' . now()->format('Y-m-d-H-i');
        
        return Cache::remember($cacheKey, 900, function () { // 15 minutes cache
            try {
                $currentTime = now();
                $todaysPrices = $this->getTodaysPrices();
                $tomorrowPrices = $this->getTomorrowPrices();
                
                $next24Hours = [];
                
                // Get remaining intervals today (starting from current time)
                foreach ($todaysPrices as $priceData) {
                    $startTime = Carbon::parse($priceData['time_start']);
                    if ($startTime->gte($currentTime)) {
                        $next24Hours[] = $priceData;
                    }
                }
                
                // Add tomorrow's prices to fill 24 hours
                $cutoffTime = $currentTime->copy()->addHours(24);
                foreach ($tomorrowPrices as $priceData) {
                    $startTime = Carbon::parse($priceData['time_start']);
                    if ($startTime->lt($cutoffTime)) {
                        $next24Hours[] = $priceData;
                    }
                }
                
                // Limit to exactly 24 hours worth of data (96 intervals)
                return array_slice($next24Hours, 0, 96);
                
            } catch (\Exception $e) {
                Log::error('Failed to get 24h prices from elprisetjustnu.se', [
                    'error' => $e->getMessage()
                ]);
                return [];
            }
        });
    }

    /**
     * Get day-ahead prices for a specific date
     */
    public function getDayAheadPrices(?Carbon $date = null): array
    {
        return $this->getTodaysPrices($date ?? now());
    }

    /**
     * Get electricity prices for a specific date
     */
    public function getTodaysPrices(?Carbon $date = null): array
    {
        $date = $date ?? now();
        $cacheKey = 'elprisetjustnu_day_' . $date->format('Y-m-d');
        
        return Cache::remember($cacheKey, 3600, function () use ($date) { // 1 hour cache
            try {
                // Format: https://www.elprisetjustnu.se/api/v1/prices/2025/12-03_SE3.json
                $endpoint = sprintf(
                    '%s/%s/%s_%s.json',
                    $this->baseUrl,
                    $date->format('Y'),
                    $date->format('m-d'),
                    $this->priceArea
                );
                
                $response = Http::timeout($this->timeout)->get($endpoint);
                
                if (!$response->successful()) {
                    Log::warning('elprisetjustnu.se API returned non-successful response', [
                        'status' => $response->status(),
                        'endpoint' => $endpoint,
                        'date' => $date->format('Y-m-d')
                    ]);
                    return [];
                }
                
                $rawData = $response->json();
                
                if (!is_array($rawData)) {
                    Log::warning('elprisetjustnu.se API returned non-array data', [
                        'endpoint' => $endpoint,
                        'response_type' => gettype($rawData)
                    ]);
                    return [];
                }
                
                // Convert to our standard format
                $prices = [];
                foreach ($rawData as $interval) {
                    if (!isset($interval['SEK_per_kWh'], $interval['time_start'], $interval['time_end'])) {
                        continue;
                    }
                    
                    $prices[] = [
                        'time_start' => $interval['time_start'],
                        'time_end' => $interval['time_end'],
                        'value' => (float) $interval['SEK_per_kWh'], // Already in SEK/kWh
                        'area' => $this->priceArea,
                        'currency' => 'SEK',
                        'granularity' => '15min'
                    ];
                }
                
                Log::info('Successfully fetched elprisetjustnu.se prices', [
                    'date' => $date->format('Y-m-d'),
                    'intervals_count' => count($prices)
                ]);
                
                return $prices;
                
            } catch (\Exception $e) {
                Log::error('Failed to get prices from elprisetjustnu.se', [
                    'error' => $e->getMessage(),
                    'date' => $date->format('Y-m-d')
                ]);
                return [];
            }
        });
    }

    /**
     * Get tomorrow's electricity prices (available after 13:00 CET)
     */
    public function getTomorrowPrices(): array
    {
        $tomorrow = now()->addDay();
        
        // Tomorrow's prices are usually published around 13:00 CET
        if (now()->hour < 13) {
            Log::info('Tomorrow prices not yet available from elprisetjustnu.se (published after 13:00)');
            return [];
        }
        
        return $this->getTodaysPrices($tomorrow);
    }

    /**
     * Get historical prices for analysis
     */
    public function getHistoricalPrices(Carbon $startDate, Carbon $endDate): array
    {
        $prices = [];
        $currentDate = $startDate->copy();
        
        while ($currentDate <= $endDate) {
            $dayPrices = $this->getTodaysPrices($currentDate);
            
            if (!empty($dayPrices)) {
                $values = array_column($dayPrices, 'value');
                $prices[$currentDate->format('Y-m-d')] = [
                    'date' => $currentDate->format('Y-m-d'),
                    'prices' => $dayPrices,
                    'intervals_count' => count($dayPrices),
                    'avg' => array_sum($values) / count($values),
                    'min' => min($values),
                    'max' => max($values),
                    'granularity' => '15min'
                ];
            }
            
            $currentDate->addDay();
            
            // Add a small delay to be respectful to the API
            if ($currentDate <= $endDate) {
                usleep(200000); // 200ms delay
            }
        }
        
        return $prices;
    }

    /**
     * Test API connectivity and functionality
     */
    public function testConnection(): array
    {
        try {
            Log::info('Testing elprisetjustnu.se API connectivity');
            
            $currentPrice = $this->getCurrentPrice();
            $todaysPrices = $this->getTodaysPrices();
            $next24h = $this->getNext24HourPrices();
            
            $success = !empty($todaysPrices) && $currentPrice > 0;
            
            $priceStats = [];
            if (!empty($todaysPrices)) {
                $values = array_column($todaysPrices, 'value');
                $priceStats = [
                    'min' => min($values),
                    'max' => max($values),
                    'avg' => array_sum($values) / count($values),
                    'intervals_count' => count($values)
                ];
            }
            
            return [
                'success' => $success,
                'api_provider' => 'elprisetjustnu.se (15-minute intervals)',
                'base_url' => $this->baseUrl,
                'current_price_sek' => $currentPrice,
                'todays_intervals_count' => count($todaysPrices),
                'next_24h_intervals_count' => count($next24h),
                'price_range_today' => $priceStats,
                'granularity' => '15 minutes',
                'timestamp' => now(),
                'area_tested' => 'SE3'
            ];
            
        } catch (\Exception $e) {
            Log::error('elprisetjustnu.se API test failed', ['error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'api_provider' => 'elprisetjustnu.se',
                'timestamp' => now()
            ];
        }
    }

    /**
     * Check if API is currently available
     */
    public function isAvailable(): bool
    {
        try {
            $today = now();
            $endpoint = sprintf(
                '%s/%s/%s_%s.json',
                $this->baseUrl,
                $today->format('Y'),
                $today->format('m-d'),
                $this->priceArea
            );
            
            $response = Http::timeout(10)->get($endpoint);
            return $response->successful() && is_array($response->json());
            
        } catch (\Exception $e) {
            Log::warning('elprisetjustnu.se API availability check failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get provider reliability score (0-100)
     */
    public function getReliabilityScore(): int
    {
        $cacheKey = 'elprisetjustnu_reliability_score';
        
        return Cache::remember($cacheKey, 1800, function () { // 30 minutes cache
            try {
                $score = 0;
                
                // Test current data availability (40 points)
                $currentPrice = $this->getCurrentPrice();
                if ($currentPrice > 0 && $currentPrice < 5) { // Reasonable price range
                    $score += 40;
                }
                
                // Test today's data completeness (30 points)  
                $todaysPrices = $this->getTodaysPrices();
                if (count($todaysPrices) >= 90) { // Should have most 15-min intervals (96 total)
                    $score += 30;
                }
                
                // Test API response time (30 points)
                $start = microtime(true);
                $isAvailable = $this->isAvailable();
                $responseTime = (microtime(true) - $start) * 1000; // Convert to ms
                
                if ($isAvailable && $responseTime < 3000) { // Under 3 seconds
                    $score += 30;
                }
                
                Log::info('elprisetjustnu.se reliability score calculated', [
                    'score' => $score,
                    'current_price_ok' => $currentPrice > 0,
                    'todays_intervals_count' => count($todaysPrices),
                    'response_time_ms' => $responseTime,
                    'is_available' => $isAvailable
                ]);
                
                return $score;
                
            } catch (\Exception $e) {
                Log::warning('Failed to calculate elprisetjustnu.se reliability score', [
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
            $todaysPrices = $this->getTodaysPrices();
            
            if (!empty($todaysPrices)) {
                // Find the latest interval
                $latestInterval = end($todaysPrices);
                if (isset($latestInterval['time_end'])) {
                    return Carbon::parse($latestInterval['time_end']);
                }
            }
            
        } catch (\Exception $e) {
            Log::warning('Failed to get data freshness from elprisetjustnu.se', [
                'error' => $e->getMessage()
            ]);
        }
        
        // Fallback to current time minus 15 minutes
        return now()->subMinutes(15);
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
            'data_source' => 'entsoe.eu electricity prices with 15-minute granularity',
            'price_areas' => config('services.elprisetjustnu.areas', ['SE1', 'SE2', 'SE3', 'SE4']),
            'current_area' => $this->priceArea,
            'currencies' => ['SEK'],
            'update_frequency' => '15 minutes',
            'intervals_per_hour' => 4,
            'intervals_per_day' => 96,
            'tomorrow_prices_available' => 'after 13:00 CET',
            'historical_data_limit' => 'from November 1, 2022',
            'authentication_required' => false,
            'rate_limiting' => 'fair use (respectful delays implemented)',
            'reliability_score' => $this->getReliabilityScore(),
            'data_freshness' => $this->getDataFreshness(),
            'is_available' => $this->isAvailable(),
            'default_area' => $this->priceArea,
            'default_currency' => 'SEK',
            'granularity' => '15-minute intervals'
        ];
    }

    /**
     * Get optimized battery charging/discharging windows with 15-minute precision
     */
    public function findOptimalWindows(): array
    {
        $next24h = $this->getNext24HourPrices();
        
        if (empty($next24h)) {
            Log::warning('No price data available for optimization windows');
            return [
                'charging_windows' => [],
                'discharging_windows' => [],
                'analysis_time' => now()
            ];
        }
        
        $values = array_column($next24h, 'value');
        $stats = [
            'min' => min($values),
            'max' => max($values),
            'avg' => array_sum($values) / count($values),
            'percentile_10' => $values[intval(count($values) * 0.1)] ?? $values[0],
            'percentile_90' => $values[intval(count($values) * 0.9)] ?? end($values)
        ];
        sort($values);
        
        // Define thresholds for 15-minute optimization
        $lowThreshold = $stats['percentile_10'];
        $highThreshold = $stats['percentile_90'];
        
        $chargingWindows = [];
        $dischargingWindows = [];
        
        foreach ($next24h as $interval) {
            $price = $interval['value'];
            $startTime = Carbon::parse($interval['time_start']);
            
            if ($price <= $lowThreshold) {
                $chargingWindows[] = [
                    'time_start' => $interval['time_start'],
                    'time_end' => $interval['time_end'],
                    'price_sek' => $price,
                    'savings_vs_avg' => $stats['avg'] - $price,
                    'duration_minutes' => 15,
                    'priority' => $price <= $stats['min'] * 1.1 ? 'high' : 'medium'
                ];
            }
            
            if ($price >= $highThreshold) {
                $dischargingWindows[] = [
                    'time_start' => $interval['time_start'],
                    'time_end' => $interval['time_end'],
                    'price_sek' => $price,
                    'earnings_vs_avg' => $price - $stats['avg'],
                    'duration_minutes' => 15,
                    'priority' => $price >= $stats['max'] * 0.9 ? 'high' : 'medium'
                ];
            }
        }
        
        return [
            'charging_windows' => $chargingWindows,
            'discharging_windows' => $dischargingWindows,
            'price_stats' => $stats,
            'analysis_time' => now(),
            'next_update' => now()->addMinutes(15),
            'granularity' => '15-minute intervals'
        ];
    }
}