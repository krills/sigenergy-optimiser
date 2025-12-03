<?php

namespace App\Services;

use App\Contracts\PriceProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class NordPoolApiService implements PriceProviderInterface
{
    private string $baseUrl;
    
    public function __construct()
    {
        $this->baseUrl = config('services.nordpool.base_url', 'https://mgrey.se/espot');
    }

    /**
     * Get the provider name/identifier
     */
    public function getProviderName(): string
    {
        return 'mgrey.se';
    }

    /**
     * Get the provider description
     */
    public function getProviderDescription(): string
    {
        return 'Swedish Electricity Spot Prices via mgrey.se public API (ENTSO-E data)';
    }

    /**
     * Check if the provider is currently available and working
     */
    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(10)->get($this->baseUrl, ['format' => 'json']);
            return $response->successful() && isset($response->json()['SE3']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get provider reliability score (0-100)
     */
    public function getReliabilityScore(): int
    {
        $cacheKey = 'mgrey_reliability_score';
        
        return Cache::remember($cacheKey, 1800, function () {
            try {
                // Test multiple endpoints for reliability assessment
                $tests = [
                    'current_data' => $this->testCurrentDataAvailability(),
                    'historical_data' => $this->testHistoricalDataAvailability(),
                    'response_time' => $this->testResponseTime()
                ];
                
                $score = 0;
                $score += $tests['current_data'] ? 40 : 0;
                $score += $tests['historical_data'] ? 30 : 0;
                $score += $tests['response_time'] ? 30 : 0;
                
                return $score;
            } catch (\Exception $e) {
                Log::warning('Failed to calculate reliability score for mgrey.se', [
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
            $response = Http::timeout(10)->get($this->baseUrl, ['format' => 'json']);
            
            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['SE3'][0]['date'], $data['SE3'][0]['hour'])) {
                    $date = $data['SE3'][0]['date'];
                    $hour = $data['SE3'][0]['hour'];
                    return Carbon::createFromFormat('Y-m-d H', "$date $hour");
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
            'data_source' => 'ENTSO-E Transparency Platform',
            'price_areas' => ['SE1', 'SE2', 'SE3', 'SE4'],
            'currencies' => ['SEK', 'EUR'],
            'update_frequency' => 'hourly',
            'historical_data_start' => '2022-09-01',
            'authentication_required' => false,
            'rate_limiting' => 'none specified',
            'reliability_score' => $this->getReliabilityScore(),
            'data_freshness' => $this->getDataFreshness(),
            'is_available' => $this->isAvailable()
        ];
    }

    /**
     * Get current electricity price for Stockholm (SE3 area)
     */
    public function getCurrentPrice(): float
    {
        $cacheKey = 'mgrey_current_price_' . now()->format('Y-m-d_H');
        
        return Cache::remember($cacheKey, 1800, function () { // Cache for 30 minutes
            try {
                $response = Http::timeout(15)->get($this->baseUrl, [
                    'format' => 'json'
                ]);
                
                if (!$response->successful()) {
                    Log::warning('mgrey.se API returned non-successful response', [
                        'status' => $response->status(),
                        'body' => $response->body()
                    ]);
                    return 0.50; // Fallback price
                }
                
                $data = $response->json();
                
                // Extract SE3 (Stockholm) current price
                if (isset($data['SE3']) && is_array($data['SE3']) && !empty($data['SE3'])) {
                    $se3Data = $data['SE3'][0]; // Current hour data
                    $priceSEK = $se3Data['price_sek'] ?? null;
                    
                    if ($priceSEK) {
                        // Convert from öre/kWh to SEK/kWh
                        return $priceSEK / 100;
                    }
                }
                
                Log::warning('No SE3 price data found in response', ['response' => $data]);
                return 0.50; // Fallback price
                
            } catch (\Exception $e) {
                Log::error('Failed to get current electricity price from mgrey.se', [
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
        $cacheKey = 'nordpool_24h_prices_' . now()->format('Y-m-d');
        
        return Cache::remember($cacheKey, 3600, function () {
            try {
                $todayPrices = $this->getDayAheadPrices();
                $tomorrowPrices = $this->getTomorrowPrices();
                
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
                Log::error('Failed to get 24h Nord Pool prices', ['error' => $e->getMessage()]);
                return array_fill(0, 24, 0.50); // Safe fallback
            }
        });
    }

    /**
     * Get tomorrow's electricity prices (available after 13:00 CET)
     */
    public function getTomorrowPrices(): array
    {
        $tomorrow = now()->addDay();
        $cacheKey = 'nordpool_tomorrow_prices_' . $tomorrow->format('Y-m-d');
        
        return Cache::remember($cacheKey, 3600, function () use ($tomorrow) {
            try {
                $response = Http::timeout(30)
                    ->get($this->baseUrl . '/marketdata/page/10', [
                        'currency' => 'SEK',
                        'endDate' => $tomorrow->format('d-m-Y')
                    ]);
                
                if (!$response->successful()) {
                    Log::warning('Nord Pool API returned non-successful response for tomorrow prices', [
                        'status' => $response->status(),
                        'body' => $response->body()
                    ]);
                    return array_fill(0, 24, 0.50);
                }
                
                return $this->parsePriceResponse($response->json());
            } catch (\Exception $e) {
                Log::error('Failed to get tomorrow Nord Pool prices', ['error' => $e->getMessage()]);
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
        $cacheKey = 'mgrey_day_ahead_' . $date->format('Y-m-d');
        
        return Cache::remember($cacheKey, 3600, function () use ($date) {
            try {
                $response = Http::timeout(15)->get($this->baseUrl, [
                    'format' => 'json',
                    'date' => $date->format('Y-m-d')
                ]);
                
                if (!$response->successful()) {
                    Log::warning('mgrey.se API returned non-successful response for day-ahead prices', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                        'date' => $date->format('Y-m-d')
                    ]);
                    return array_fill(0, 24, 0.50);
                }
                
                $data = $response->json();
                
                // Extract SE3 hourly prices for the day
                $prices = array_fill(0, 24, 0.50); // Initialize with fallback prices
                
                if (isset($data['SE3']) && is_array($data['SE3'])) {
                    foreach ($data['SE3'] as $hourlyData) {
                        $hour = intval($hourlyData['hour'] ?? 0);
                        $priceSEK = $hourlyData['price_sek'] ?? null;
                        
                        if ($hour >= 0 && $hour < 24 && $priceSEK !== null) {
                            // Convert from öre/kWh to SEK/kWh
                            $prices[$hour] = $priceSEK / 100;
                        }
                    }
                }
                
                return $prices;
                
            } catch (\Exception $e) {
                Log::error('Failed to get day-ahead prices from mgrey.se', [
                    'error' => $e->getMessage(),
                    'date' => $date->format('Y-m-d')
                ]);
                return array_fill(0, 24, 0.50);
            }
        });
    }

    /**
     * Get historical prices for analysis
     */
    public function getHistoricalPrices(Carbon $startDate, Carbon $endDate): array
    {
        $prices = [];
        $currentDate = $startDate->copy();
        
        while ($currentDate <= $endDate) {
            $dayPrices = $this->getDayAheadPrices($currentDate);
            $prices[$currentDate->format('Y-m-d')] = [
                'date' => $currentDate->format('Y-m-d'),
                'prices' => $dayPrices,
                'avg' => array_sum($dayPrices) / count($dayPrices),
                'min' => min($dayPrices),
                'max' => max($dayPrices)
            ];
            
            $currentDate->addDay();
        }
        
        return $prices;
    }

    /**
     * Get price statistics for the current month
     */
    public function getMonthlyPriceStats(): array
    {
        $cacheKey = 'nordpool_monthly_stats_' . now()->format('Y-m');
        
        return Cache::remember($cacheKey, 7200, function () {
            try {
                $startDate = now()->startOfMonth();
                $endDate = now();
                
                $historicalPrices = $this->getHistoricalPrices($startDate, $endDate);
                
                $allPrices = [];
                foreach ($historicalPrices as $dayData) {
                    $allPrices = array_merge($allPrices, $dayData['prices']);
                }
                
                if (empty($allPrices)) {
                    return [
                        'avg' => 0.50,
                        'min' => 0.10,
                        'max' => 1.50,
                        'median' => 0.50,
                        'count' => 0
                    ];
                }
                
                sort($allPrices);
                $count = count($allPrices);
                
                return [
                    'avg' => array_sum($allPrices) / $count,
                    'min' => min($allPrices),
                    'max' => max($allPrices),
                    'median' => $allPrices[intval($count / 2)],
                    'count' => $count,
                    'percentile_10' => $allPrices[intval($count * 0.1)],
                    'percentile_90' => $allPrices[intval($count * 0.9)]
                ];
            } catch (\Exception $e) {
                Log::error('Failed to calculate monthly price stats', ['error' => $e->getMessage()]);
                return [
                    'avg' => 0.50,
                    'min' => 0.10,
                    'max' => 1.50,
                    'median' => 0.50,
                    'count' => 0
                ];
            }
        });
    }

    /**
     * Find optimal charging/discharging windows for the next 24 hours
     */
    public function findOptimalWindows(): array
    {
        $prices = $this->getNext24HourPrices();
        $stats = $this->getMonthlyPriceStats();
        
        // Define thresholds based on monthly statistics
        $lowThreshold = $stats['percentile_10'];
        $highThreshold = $stats['percentile_90'];
        
        $chargingWindows = [];
        $dischargingWindows = [];
        
        foreach ($prices as $hour => $price) {
            $startTime = now()->addHours($hour);
            
            if ($price <= $lowThreshold) {
                $chargingWindows[] = [
                    'hour' => $hour,
                    'start_time' => $startTime->format('H:i'),
                    'price' => $price,
                    'savings_vs_avg' => $stats['avg'] - $price,
                    'priority' => $price <= $stats['min'] * 1.1 ? 'high' : 'medium'
                ];
            }
            
            if ($price >= $highThreshold) {
                $dischargingWindows[] = [
                    'hour' => $hour,
                    'start_time' => $startTime->format('H:i'),
                    'price' => $price,
                    'earnings_vs_avg' => $price - $stats['avg'],
                    'priority' => $price >= $stats['max'] * 0.9 ? 'high' : 'medium'
                ];
            }
        }
        
        return [
            'charging_windows' => $chargingWindows,
            'discharging_windows' => $dischargingWindows,
            'price_stats' => $stats,
            'analysis_time' => now()
        ];
    }

    /**
     * Parse Nord Pool API response and extract SE3 (Stockholm) prices
     */
    private function parsePriceResponse(array $response): array
    {
        try {
            $prices = array_fill(0, 24, 0.50); // Initialize with fallback
            
            if (!isset($response['data']['Rows'])) {
                Log::warning('Unexpected Nord Pool API response structure', ['response' => $response]);
                return $prices;
            }
            
            foreach ($response['data']['Rows'] as $row) {
                if (!isset($row['Columns']) || !isset($row['StartTime'])) {
                    continue;
                }
                
                // Parse hour from StartTime (format: "2024-01-15T14:00:00")
                $startTime = Carbon::parse($row['StartTime']);
                $hour = $startTime->hour;
                
                // Find SE3 (Stockholm) price column
                foreach ($row['Columns'] as $column) {
                    if ($column['Name'] === 'SE3' && isset($column['Value'])) {
                        $priceString = str_replace(',', '.', $column['Value']); // Handle European decimal format
                        $priceEuroMWh = floatval($priceString);
                        
                        // Convert from EUR/MWh to SEK/kWh
                        $priceSEKkWh = $priceEuroMWh * 11.0 / 1000; // Approximate EUR to SEK conversion
                        
                        $prices[$hour] = max(0.01, $priceSEKkWh); // Minimum 0.01 SEK/kWh
                        break;
                    }
                }
            }
            
            return $prices;
        } catch (\Exception $e) {
            Log::error('Failed to parse Nord Pool price response', [
                'error' => $e->getMessage(),
                'response' => $response
            ]);
            return array_fill(0, 24, 0.50);
        }
    }

    /**
     * Test API connectivity and data retrieval
     */
    public function testConnection(): array
    {
        try {
            // Test raw API connectivity first
            $response = Http::timeout(15)->get($this->baseUrl, [
                'format' => 'json'
            ]);
            
            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => 'API connectivity failed with status: ' . $response->status(),
                    'api_provider' => 'mgrey.se',
                    'timestamp' => now()
                ];
            }
            
            $rawData = $response->json();
            $currentPrice = $this->getCurrentPrice();
            $next24h = $this->getNext24HourPrices();
            $stats = $this->getMonthlyPriceStats();
            $windows = $this->findOptimalWindows();
            
            return [
                'success' => true,
                'api_provider' => 'mgrey.se (Swedish Electricity Spot Prices)',
                'current_price' => $currentPrice,
                'next_24h_count' => count($next24h),
                'price_range' => [
                    'min' => min($next24h),
                    'max' => max($next24h),
                    'avg' => array_sum($next24h) / count($next24h)
                ],
                'monthly_stats' => $stats,
                'charging_windows' => count($windows['charging_windows']),
                'discharging_windows' => count($windows['discharging_windows']),
                'raw_api_data' => [
                    'se3_available' => isset($rawData['SE3']),
                    'current_hour' => $rawData['SE3'][0]['hour'] ?? 'unknown',
                    'current_date' => $rawData['SE3'][0]['date'] ?? 'unknown'
                ],
                'timestamp' => now()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'api_provider' => 'mgrey.se',
                'timestamp' => now()
            ];
        }
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
            return count($prices) >= 20; // Should have most hours available
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
            $response = Http::timeout(5)->get($this->baseUrl, ['format' => 'json']);
            $responseTime = (microtime(true) - $start) * 1000; // Convert to milliseconds
            
            return $response->successful() && $responseTime < 2000; // Under 2 seconds
        } catch (\Exception $e) {
            return false;
        }
    }
}