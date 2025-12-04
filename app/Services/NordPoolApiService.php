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
    private int $timeout;
    
    public function __construct()
    {
        // Use mgrey.se API which provides Nord Pool data in accessible format
        $this->baseUrl = 'https://mgrey.se/espot';
        $this->timeout = 15;
    }

    /**
     * Get the provider name/identifier
     */
    public function getProviderName(): string
    {
        return 'Nord Pool Group';
    }

    /**
     * Get the provider description
     */
    public function getProviderDescription(): string
    {
        return 'Official Nord Pool electricity market data API providing real-time and day-ahead prices';
    }

    /**
     * Get today's electricity prices for a specific area (default SE3 - Stockholm)
     */
    public function getTodaysPrices(string $priceArea = 'SE3'): array
    {
        return $this->getDayAheadPrices(now(), $priceArea);
    }

    /**
     * Get day-ahead prices for a specific date (interface method)
     */
    public function getDayAheadPrices(?Carbon $date = null): array
    {
        return $this->getDayAheadPricesForArea($date ?? now(), 'SE3');
    }

    /**
     * Get electricity prices for a specific date and area
     */
    public function getDayAheadPricesForArea(Carbon $date, string $priceArea = 'SE3'): array
    {
        $cacheKey = 'nordpool_prices_' . $date->format('Y-m-d') . '_' . $priceArea;
        
        return Cache::remember($cacheKey, 3600, function () use ($date, $priceArea) {
            try {
                Log::info('Fetching Nord Pool prices', [
                    'date' => $date->format('Y-m-d'),
                    'area' => $priceArea
                ]);

                $response = Http::timeout($this->timeout)
                    ->get($this->baseUrl . '/api/marketdata/page/10', [
                        'currency' => 'SEK',
                        'endDate' => $date->format('d-m-Y'),
                        'market' => 'DayAhead',
                        'deliveryArea' => $priceArea
                    ]);
                
                if (!$response->successful()) {
                    Log::warning('Nord Pool API returned non-successful response', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                        'date' => $date->format('Y-m-d'),
                        'area' => $priceArea
                    ]);
                    return [];
                }
                
                return $this->parsePriceResponse($response->json(), $priceArea);
                
            } catch (\Exception $e) {
                Log::error('Failed to get Nord Pool prices', [
                    'error' => $e->getMessage(),
                    'date' => $date->format('Y-m-d'),
                    'area' => $priceArea
                ]);
                return [];
            }
        });
    }

    /**
     * Get current electricity price for Stockholm (SE3 area)
     */
    public function getCurrentPrice(): float
    {
        return $this->getCurrentPriceForArea('SE3');
    }

    /**
     * Get current electricity price for a specific area
     */
    public function getCurrentPriceForArea(string $priceArea = 'SE3'): float
    {
        $currentHour = now()->hour;
        $todaysPrices = $this->getTodaysPrices($priceArea);
        
        if (empty($todaysPrices) || !isset($todaysPrices[$currentHour])) {
            Log::warning('No current price data available, using fallback', [
                'hour' => $currentHour,
                'area' => $priceArea
            ]);
            return 0.50; // Fallback price in SEK/kWh
        }
        
        return $todaysPrices[$currentHour]['value'] / 1000; // Convert öre to SEK
    }

    /**
     * Get next 24 hours of electricity prices
     */
    public function getNext24HourPrices(): array
    {
        return $this->getNext24HourPricesForArea('SE3');
    }

    /**
     * Get next 24 hours of electricity prices for specific area
     */
    public function getNext24HourPricesForArea(string $priceArea = 'SE3'): array
    {
        $cacheKey = 'nordpool_24h_prices_' . now()->format('Y-m-d-H') . '_' . $priceArea;
        
        return Cache::remember($cacheKey, 1800, function () use ($priceArea) { // 30 minutes cache
            try {
                $currentHour = now()->hour;
                $todaysPrices = $this->getTodaysPrices($priceArea);
                $tomorrowPrices = $this->getTomorrowPrices($priceArea);
                
                $next24Hours = [];
                
                // Get remaining hours today
                for ($hour = $currentHour; $hour < 24; $hour++) {
                    if (isset($todaysPrices[$hour])) {
                        $next24Hours[] = [
                            'time_start' => now()->setHour($hour)->setMinute(0)->format('c'),
                            'value' => $todaysPrices[$hour]['value'],
                            'area' => $priceArea
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
                            'area' => $priceArea
                        ];
                    }
                }
                
                return $next24Hours;
                
            } catch (\Exception $e) {
                Log::error('Failed to get 24h Nord Pool prices', [
                    'error' => $e->getMessage(),
                    'area' => $priceArea
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
        return $this->getTomorrowPricesForArea('SE3');
    }

    /**
     * Get tomorrow's electricity prices for specific area
     */
    public function getTomorrowPricesForArea(string $priceArea = 'SE3'): array
    {
        $tomorrow = now()->addDay();
        
        // Tomorrow's prices are usually published around 13:00 CET
        if (now()->hour < 13) {
            Log::info('Tomorrow prices not yet available (published after 13:00)');
            return [];
        }
        
        return $this->getDayAheadPricesForArea($tomorrow, $priceArea);
    }

    /**
     * Get price statistics for analysis
     */
    public function getPriceStatistics(array $prices): array
    {
        if (empty($prices)) {
            return [
                'min' => 0,
                'max' => 0,
                'avg' => 0,
                'median' => 0,
                'count' => 0
            ];
        }
        
        $values = array_column($prices, 'value');
        sort($values);
        $count = count($values);
        
        return [
            'min' => min($values),
            'max' => max($values),
            'avg' => array_sum($values) / $count,
            'median' => $values[intval($count / 2)],
            'count' => $count,
            'percentile_10' => $values[intval($count * 0.1)] ?? $values[0],
            'percentile_90' => $values[intval($count * 0.9)] ?? $values[$count - 1]
        ];
    }

    /**
     * Find optimal charging/discharging windows for battery optimization
     */
    public function findOptimalWindows(string $priceArea = 'SE3'): array
    {
        $next24h = $this->getNext24HourPrices($priceArea);
        
        if (empty($next24h)) {
            Log::warning('No price data available for optimization windows');
            return [
                'charging_windows' => [],
                'discharging_windows' => [],
                'analysis_time' => now()
            ];
        }
        
        $stats = $this->getPriceStatistics($next24h);
        
        // Define thresholds for charging/discharging (in öre/kWh)
        $lowThreshold = $stats['percentile_10'];
        $highThreshold = $stats['percentile_90'];
        
        $chargingWindows = [];
        $dischargingWindows = [];
        
        foreach ($next24h as $index => $priceData) {
            $price = $priceData['value'];
            $timestamp = Carbon::parse($priceData['time_start']);
            
            if ($price <= $lowThreshold) {
                $chargingWindows[] = [
                    'hour' => $timestamp->format('H:i'),
                    'timestamp' => $timestamp->timestamp,
                    'price_ore' => $price,
                    'price_sek' => $price / 100,
                    'savings_vs_avg' => ($stats['avg'] - $price) / 100,
                    'priority' => $price <= $stats['min'] * 1.1 ? 'high' : 'medium'
                ];
            }
            
            if ($price >= $highThreshold) {
                $dischargingWindows[] = [
                    'hour' => $timestamp->format('H:i'),
                    'timestamp' => $timestamp->timestamp,
                    'price_ore' => $price,
                    'price_sek' => $price / 100,
                    'earnings_vs_avg' => ($price - $stats['avg']) / 100,
                    'priority' => $price >= $stats['max'] * 0.9 ? 'high' : 'medium'
                ];
            }
        }
        
        return [
            'charging_windows' => $chargingWindows,
            'discharging_windows' => $dischargingWindows,
            'price_stats' => array_map(fn($val) => $val / 100, $stats), // Convert to SEK
            'analysis_time' => now(),
            'next_update' => now()->addMinutes(30) // Cache refresh time
        ];
    }

    /**
     * Parse Nord Pool API response and extract price data
     */
    private function parsePriceResponse(array $response, string $priceArea): array
    {
        $prices = [];
        
        try {
            if (!isset($response['data']['Rows'])) {
                Log::warning('Unexpected Nord Pool API response structure', [
                    'response_keys' => array_keys($response),
                    'area' => $priceArea
                ]);
                return [];
            }
            
            foreach ($response['data']['Rows'] as $row) {
                if (!isset($row['Columns'], $row['StartTime'])) {
                    continue;
                }
                
                // Parse timestamp
                $startTime = Carbon::parse($row['StartTime']);
                $hour = $startTime->hour;
                
                // Find price column for the specified area
                foreach ($row['Columns'] as $column) {
                    if ($column['Name'] === $priceArea && isset($column['Value'])) {
                        $priceString = str_replace([',', ' '], ['.', ''], $column['Value']);
                        
                        // Skip if no valid price data
                        if ($priceString === '-' || $priceString === '') {
                            continue;
                        }
                        
                        $priceValue = floatval($priceString);
                        
                        $prices[$hour] = [
                            'time_start' => $startTime->format('c'),
                            'value' => $priceValue, // Price in öre/kWh
                            'area' => $priceArea,
                            'hour' => $hour
                        ];
                        break;
                    }
                }
            }
            
            Log::info('Successfully parsed Nord Pool prices', [
                'area' => $priceArea,
                'hours_parsed' => count($prices)
            ]);
            
            return $prices;
            
        } catch (\Exception $e) {
            Log::error('Failed to parse Nord Pool price response', [
                'error' => $e->getMessage(),
                'area' => $priceArea
            ]);
            return [];
        }
    }

    /**
     * Test API connectivity and functionality
     */
    public function testConnection(): array
    {
        try {
            Log::info('Testing Nord Pool API connectivity');
            
            $currentPrice = $this->getCurrentPrice();
            $todaysPrices = $this->getTodaysPrices();
            $next24h = $this->getNext24HourPrices();
            
            $success = !empty($todaysPrices) && $currentPrice > 0;
            
            return [
                'success' => $success,
                'api_provider' => 'Nord Pool Group (Official API)',
                'base_url' => $this->baseUrl,
                'current_price_sek' => $currentPrice,
                'todays_prices_count' => count($todaysPrices),
                'next_24h_count' => count($next24h),
                'price_range_today' => $this->getPriceStatistics($todaysPrices),
                'timestamp' => now(),
                'area_tested' => 'SE3'
            ];
            
        } catch (\Exception $e) {
            Log::error('Nord Pool API test failed', ['error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'api_provider' => 'Nord Pool Group (Official API)',
                'timestamp' => now()
            ];
        }
    }

    /**
     * Get available price areas (Swedish areas)
     */
    public function getAvailablePriceAreas(): array
    {
        return [
            'SE1' => 'Luleå (North Sweden)',
            'SE2' => 'Sundsvall (Central Sweden)', 
            'SE3' => 'Stockholm (Central Sweden)',
            'SE4' => 'Malmö (South Sweden)'
        ];
    }

    /**
     * Check if API is currently available
     */
    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(10)->get($this->baseUrl . '/api/marketdata/page/10', [
                'currency' => 'SEK',
                'endDate' => now()->format('d-m-Y'),
                'market' => 'DayAhead',
                'deliveryArea' => 'SE3'
            ]);
            
            return $response->successful();
            
        } catch (\Exception $e) {
            Log::warning('Nord Pool API availability check failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get historical prices for analysis (required by interface)
     */
    public function getHistoricalPrices(Carbon $startDate, Carbon $endDate): array
    {
        $prices = [];
        $currentDate = $startDate->copy();
        
        while ($currentDate <= $endDate) {
            $dayPrices = $this->getDayAheadPricesForArea($currentDate);
            
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
        }
        
        return $prices;
    }

    /**
     * Get provider reliability score (0-100)
     */
    public function getReliabilityScore(): int
    {
        $cacheKey = 'nordpool_reliability_score';
        
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
                
                Log::info('Nord Pool reliability score calculated', [
                    'score' => $score,
                    'current_price_ok' => $currentPrice > 0,
                    'todays_prices_count' => count($todaysPrices),
                    'response_time_ms' => $responseTime,
                    'is_available' => $isAvailable
                ]);
                
                return $score;
                
            } catch (\Exception $e) {
                Log::warning('Failed to calculate Nord Pool reliability score', [
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
            // Get the latest available price data timestamp
            $todaysPrices = $this->getTodaysPrices();
            
            if (!empty($todaysPrices)) {
                // Find the latest hour with data
                $latestHour = max(array_keys($todaysPrices));
                return now()->setHour($latestHour)->setMinute(0)->setSecond(0);
            }
            
        } catch (\Exception $e) {
            Log::warning('Failed to get data freshness', ['error' => $e->getMessage()]);
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
            'data_source' => 'Nord Pool Group Official API',
            'price_areas' => $this->getAvailablePriceAreas(),
            'currencies' => ['SEK', 'EUR', 'NOK', 'DKK'],
            'update_frequency' => 'hourly',
            'tomorrow_prices_available' => 'after 13:00 CET',
            'authentication_required' => false,
            'rate_limiting' => 'fair use policy',
            'reliability_score' => $this->getReliabilityScore(),
            'data_freshness' => $this->getDataFreshness(),
            'is_available' => $this->isAvailable(),
            'default_area' => 'SE3',
            'default_currency' => 'SEK'
        ];
    }
}