<?php

namespace App\Services;

use App\Contracts\PriceProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MgreyApiService implements PriceProviderInterface
{
    private string $baseUrl;
    private int $timeout;
    
    public function __construct()
    {
        $this->baseUrl = 'https://mgrey.se/espot';
        $this->timeout = 15;
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
     * Get current electricity price for Stockholm (SE3 area)
     */
    public function getCurrentPrice(): float
    {
        $cacheKey = 'mgrey_current_price_' . now()->format('Y-m-d_H');
        
        return Cache::remember($cacheKey, 1800, function () { // Cache for 30 minutes
            try {
                $response = Http::timeout($this->timeout)->get($this->baseUrl, [
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
                    
                    if ($priceSEK !== null) {
                        // Convert from öre/kWh to SEK/kWh
                        return $priceSEK / 100;
                    }
                }
                
                Log::warning('No SE3 price data found in mgrey.se response', ['response' => $data]);
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
        $cacheKey = 'mgrey_24h_prices_' . now()->format('Y-m-d-H');
        
        return Cache::remember($cacheKey, 1800, function () { // 30 minutes cache
            try {
                $currentHour = now()->hour;
                $todaysPrices = $this->getTodaysPrices();
                $tomorrowPrices = $this->getTomorrowPrices();
                
                $next24Hours = [];
                
                // Get remaining hours today
                for ($hour = $currentHour; $hour < 24; $hour++) {
                    if (isset($todaysPrices[$hour])) {
                        $next24Hours[] = [
                            'time_start' => now()->setHour($hour)->setMinute(0)->format('c'),
                            'value' => $todaysPrices[$hour]['value'],
                            'area' => 'SE3'
                        ];
                    }
                }
                
                // Fill with tomorrow's prices if available
                $remainingHours = 24 - count($next24Hours);
                for ($hour = 0; $hour < $remainingHours; $hour++) {
                    if (isset($tomorrowPrices[$hour])) {
                        $next24Hours[] = [
                            'time_start' => now()->addDay()->setHour($hour)->setMinute(0)->format('c'),
                            'value' => $tomorrowPrices[$hour]['value'],
                            'area' => 'SE3'
                        ];
                    }
                }
                
                return $next24Hours;
                
            } catch (\Exception $e) {
                Log::error('Failed to get 24h prices from mgrey.se', [
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
     * Get today's electricity prices
     */
    public function getTodaysPrices(?Carbon $date = null): array
    {
        $date = $date ?? now();
        $cacheKey = 'mgrey_day_ahead_' . $date->format('Y-m-d');
        
        return Cache::remember($cacheKey, 3600, function () use ($date) { // 1 hour cache
            try {
                $response = Http::timeout($this->timeout)->get($this->baseUrl, [
                    'format' => 'json',
                    'date' => $date->format('Y-m-d')
                ]);
                
                if (!$response->successful()) {
                    Log::warning('mgrey.se API returned non-successful response for day-ahead prices', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                        'date' => $date->format('Y-m-d')
                    ]);
                    return [];
                }
                
                $data = $response->json();
                
                // Extract SE3 hourly prices for the day
                $prices = [];
                
                if (isset($data['SE3']) && is_array($data['SE3'])) {
                    foreach ($data['SE3'] as $hourlyData) {
                        $hour = intval($hourlyData['hour'] ?? 0);
                        $priceSEK = $hourlyData['price_sek'] ?? null;
                        
                        if ($hour >= 0 && $hour < 24 && $priceSEK !== null) {
                            $prices[$hour] = [
                                'time_start' => $date->copy()->setHour($hour)->setMinute(0)->format('c'),
                                'value' => $priceSEK, // Price in öre/kWh
                                'area' => 'SE3',
                                'hour' => $hour
                            ];
                        }
                    }
                }
                
                Log::info('Successfully fetched mgrey.se prices', [
                    'date' => $date->format('Y-m-d'),
                    'hours_count' => count($prices)
                ]);
                
                return $prices;
                
            } catch (\Exception $e) {
                Log::error('Failed to get day-ahead prices from mgrey.se', [
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
            Log::info('Tomorrow prices not yet available from mgrey.se (published after 13:00)');
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
                    'avg' => array_sum($values) / count($values),
                    'min' => min($values),
                    'max' => max($values)
                ];
            }
            
            $currentDate->addDay();
            
            // Add a small delay to be respectful to the free API
            if ($currentDate <= $endDate) {
                usleep(100000); // 100ms delay
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
            Log::info('Testing mgrey.se API connectivity');
            
            $currentPrice = $this->getCurrentPrice();
            $todaysPrices = $this->getTodaysPrices();
            $next24h = $this->getNext24HourPrices();
            
            $success = !empty($todaysPrices) && $currentPrice > 0;
            
            return [
                'success' => $success,
                'api_provider' => 'mgrey.se (ENTSO-E data)',
                'base_url' => $this->baseUrl,
                'current_price_sek' => $currentPrice,
                'todays_prices_count' => count($todaysPrices),
                'next_24h_count' => count($next24h),
                'timestamp' => now(),
                'area_tested' => 'SE3'
            ];
            
        } catch (\Exception $e) {
            Log::error('mgrey.se API test failed', ['error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'api_provider' => 'mgrey.se',
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
            $response = Http::timeout(10)->get($this->baseUrl, ['format' => 'json']);
            return $response->successful() && isset($response->json()['SE3']);
        } catch (\Exception $e) {
            Log::warning('mgrey.se API availability check failed', [
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
        $cacheKey = 'mgrey_reliability_score';
        
        return Cache::remember($cacheKey, 1800, function () { // 30 minutes cache
            try {
                $score = 0;
                
                // Test current data availability (40 points)
                $currentPrice = $this->getCurrentPrice();
                if ($currentPrice > 0 && $currentPrice < 10) {
                    $score += 40;
                }
                
                // Test today's data completeness (30 points)  
                $todaysPrices = $this->getTodaysPrices();
                if (count($todaysPrices) >= 20) { // Should have most hours
                    $score += 30;
                }
                
                // Test API response time (30 points)
                $start = microtime(true);
                $isAvailable = $this->isAvailable();
                $responseTime = (microtime(true) - $start) * 1000; // Convert to ms
                
                if ($isAvailable && $responseTime < 3000) { // Under 3 seconds
                    $score += 30;
                }
                
                Log::info('mgrey.se reliability score calculated', [
                    'score' => $score,
                    'current_price_ok' => $currentPrice > 0,
                    'todays_prices_count' => count($todaysPrices),
                    'response_time_ms' => $responseTime,
                    'is_available' => $isAvailable
                ]);
                
                return $score;
                
            } catch (\Exception $e) {
                Log::warning('Failed to calculate mgrey.se reliability score', [
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
            Log::warning('Failed to get data freshness from mgrey.se', [
                'error' => $e->getMessage()
            ]);
        }
        
        // Fallback to current time minus 1 hour
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
            'data_source' => 'ENTSO-E Transparency Platform via mgrey.se',
            'price_areas' => ['SE1', 'SE2', 'SE3', 'SE4'],
            'currencies' => ['SEK', 'EUR'],
            'update_frequency' => 'hourly',
            'historical_data_start' => '2022-09-01',
            'authentication_required' => false,
            'rate_limiting' => 'fair use (respectful delays implemented)',
            'reliability_score' => $this->getReliabilityScore(),
            'data_freshness' => $this->getDataFreshness(),
            'is_available' => $this->isAvailable(),
            'default_area' => 'SE3',
            'default_currency' => 'SEK'
        ];
    }
}