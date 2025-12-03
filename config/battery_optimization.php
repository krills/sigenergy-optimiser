<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Battery Optimization Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration settings for the Stockholm battery optimization system.
    | These settings control the behavior of the automated optimization
    | algorithms based on Nord Pool electricity prices.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Price Thresholds (SEK/kWh)
    |--------------------------------------------------------------------------
    |
    | Define electricity price levels that trigger different optimization modes.
    | Adjust these values based on historical Stockholm price patterns.
    |
    */
    'price_thresholds' => [
        'very_low' => env('BATTERY_VERY_LOW_PRICE', 0.05),  // Force charge from grid
        'low' => env('BATTERY_LOW_PRICE', 0.30),            // Charge with grid supplement
        'high' => env('BATTERY_HIGH_PRICE', 0.80),          // Use battery, avoid grid
        'very_high' => env('BATTERY_VERY_HIGH_PRICE', 1.20), // Max discharge + solar export
    ],

    /*
    |--------------------------------------------------------------------------
    | Battery Safety Constraints
    |--------------------------------------------------------------------------
    |
    | Safety limits to protect battery health and system integrity.
    | These should not be exceeded under any circumstances.
    |
    */
    'battery_limits' => [
        'min_soc' => env('BATTERY_MIN_SOC', 20),           // Never discharge below 20%
        'max_soc' => env('BATTERY_MAX_SOC', 95),           // Never charge above 95%
        'safe_charge_power' => env('BATTERY_SAFE_CHARGE_POWER', 3.0),     // kW
        'safe_discharge_power' => env('BATTERY_SAFE_DISCHARGE_POWER', 3.0), // kW
        'emergency_reserve' => env('BATTERY_EMERGENCY_RESERVE', 15),       // % SOC to keep for emergencies
    ],

    /*
    |--------------------------------------------------------------------------
    | Optimization Scheduling
    |--------------------------------------------------------------------------
    |
    | Control when and how often the optimization algorithms run.
    | Balances responsiveness with API rate limits.
    |
    */
    'scheduling' => [
        'optimization_interval' => env('BATTERY_OPTIMIZATION_INTERVAL', 15), // minutes
        'price_update_interval' => env('BATTERY_PRICE_UPDATE_INTERVAL', 60), // minutes
        'active_hours_start' => env('BATTERY_ACTIVE_HOURS_START', '06:00'),
        'active_hours_end' => env('BATTERY_ACTIVE_HOURS_END', '22:00'),
        'weekend_optimization' => env('BATTERY_WEEKEND_OPTIMIZATION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Power Management Strategy
    |--------------------------------------------------------------------------
    |
    | Fine-tune the optimization strategy for different scenarios.
    | These settings affect how aggressively the system optimizes.
    |
    */
    'strategy' => [
        'prioritize_solar_charging' => env('BATTERY_PRIORITIZE_SOLAR', true),
        'export_excess_solar' => env('BATTERY_EXPORT_SOLAR', true),
        'grid_charge_threshold' => env('BATTERY_GRID_CHARGE_THRESHOLD', 0.20), // SEK/kWh
        'grid_discharge_threshold' => env('BATTERY_GRID_DISCHARGE_THRESHOLD', 0.60), // SEK/kWh
        'peak_shaving_enabled' => env('BATTERY_PEAK_SHAVING', true),
        'demand_response_enabled' => env('BATTERY_DEMAND_RESPONSE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Economic Parameters
    |--------------------------------------------------------------------------
    |
    | Economic factors that influence optimization decisions.
    | These help calculate the financial benefit of different strategies.
    |
    */
    'economics' => [
        'battery_efficiency' => env('BATTERY_EFFICIENCY', 0.93),           // Round-trip efficiency
        'grid_connection_fee' => env('GRID_CONNECTION_FEE', 0.05),         // SEK/kWh
        'solar_feed_in_tariff' => env('SOLAR_FEED_IN_TARIFF', 0.40),      // SEK/kWh
        'peak_demand_penalty' => env('PEAK_DEMAND_PENALTY', 50.0),        // SEK/kW/month
        'currency_conversion_margin' => env('CURRENCY_CONVERSION_MARGIN', 0.02), // 2% margin
    ],

    /*
    |--------------------------------------------------------------------------
    | System Integration
    |--------------------------------------------------------------------------
    |
    | Settings for integration with external systems and APIs.
    | Configure timeouts, retries, and error handling behavior.
    |
    */
    'integration' => [
        'api_timeout' => env('BATTERY_API_TIMEOUT', 30),                   // seconds
        'max_retries' => env('BATTERY_MAX_RETRIES', 3),
        'retry_delay' => env('BATTERY_RETRY_DELAY', 5),                    // seconds
        'cache_ttl' => env('BATTERY_CACHE_TTL', 300),                      // seconds
        'log_level' => env('BATTERY_LOG_LEVEL', 'info'),
        'alert_on_failure' => env('BATTERY_ALERT_ON_FAILURE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stockholm Specific Settings
    |--------------------------------------------------------------------------
    |
    | Configuration specific to Stockholm/Sweden electricity market.
    | Timezone, market hours, and regulatory considerations.
    |
    */
    'stockholm' => [
        'timezone' => env('STOCKHOLM_TIMEZONE', 'Europe/Stockholm'),
        'market_open_time' => env('STOCKHOLM_MARKET_OPEN', '06:00'),
        'market_close_time' => env('STOCKHOLM_MARKET_CLOSE', '23:00'),
        'price_area' => env('NORDPOOL_PRICE_AREA', 'SE3'),                 // Stockholm area
        'dst_adjustment' => env('STOCKHOLM_DST_ADJUSTMENT', true),
        'holiday_mode' => env('STOCKHOLM_HOLIDAY_MODE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Weather Integration (Future Enhancement)
    |--------------------------------------------------------------------------
    |
    | Placeholder for future weather-based optimization features.
    | Weather forecasts can improve solar generation predictions.
    |
    */
    'weather' => [
        'enabled' => env('WEATHER_INTEGRATION_ENABLED', false),
        'api_provider' => env('WEATHER_API_PROVIDER', 'openweathermap'),
        'forecast_hours' => env('WEATHER_FORECAST_HOURS', 24),
        'solar_irradiance_factor' => env('WEATHER_SOLAR_FACTOR', 0.8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Advanced Features
    |--------------------------------------------------------------------------
    |
    | Advanced optimization features for power users.
    | These features may require additional configuration.
    |
    */
    'advanced' => [
        'machine_learning_enabled' => env('ML_OPTIMIZATION_ENABLED', false),
        'predictive_modeling' => env('PREDICTIVE_MODELING_ENABLED', false),
        'grid_services_participation' => env('GRID_SERVICES_ENABLED', false),
        'peer_to_peer_trading' => env('P2P_TRADING_ENABLED', false),
        'carbon_footprint_optimization' => env('CARBON_OPTIMIZATION_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug and Development
    |--------------------------------------------------------------------------
    |
    | Settings for development and debugging purposes.
    | Should be disabled in production environments.
    |
    */
    'debug' => [
        'simulation_mode' => env('BATTERY_SIMULATION_MODE', false),
        'mock_api_responses' => env('BATTERY_MOCK_APIS', false),
        'verbose_logging' => env('BATTERY_VERBOSE_LOGGING', false),
        'test_scenarios' => env('BATTERY_TEST_SCENARIOS', false),
        'override_safety_limits' => env('BATTERY_OVERRIDE_SAFETY', false), // DANGEROUS!
    ],
];