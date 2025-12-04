<?php

namespace App\Contracts;

use Carbon\Carbon;

interface PriceProviderInterface
{
    /**
     * Get the provider name/identifier
     */
    public function getProviderName(): string;

    /**
     * Get the provider description
     */
    public function getProviderDescription(): string;

    /**
     * Get current electricity price for Stockholm (SE3 area) in SEK/kWh
     */
    public function getCurrentPrice(): float;

    /**
     * Get next 24 hours of electricity prices in SEK/kWh
     * Returns array with 24 hourly prices starting from current hour
     */
    public function getNext24HourPrices(): array;

    /**
     * Get day-ahead prices for a specific date in SEK/kWh
     * Returns array with 24 hourly prices (hour 0-23)
     */
    public function getDayAheadPrices(?Carbon $date = null): array;

    /**
     * Get tomorrow's electricity prices if available
     * Returns array with 24 hourly prices or empty array if not available
     */
    public function getTomorrowPrices(): array;

    /**
     * Get historical prices for a date range
     * Returns associative array with dates as keys and price data as values
     */
    public function getHistoricalPrices(Carbon $startDate, Carbon $endDate): array;

    /**
     * Test provider connectivity and data availability
     * Returns array with success status and diagnostic information
     */
    public function testConnection(): array;

    /**
     * Check if the provider is currently available and working
     */
    public function isAvailable(): bool;

    /**
     * Get provider reliability score (0-100)
     * Based on recent success rate and data quality
     */
    public function getReliabilityScore(): int;

    /**
     * Get data freshness timestamp
     * When was the data last updated
     */
    public function getDataFreshness(): Carbon;

    /**
     * Get provider configuration and metadata
     */
    public function getProviderInfo(): array;
}