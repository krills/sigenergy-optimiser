<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'sigenergy' => [
        'base_url' => env('SIGENERGY_BASE_URL', 'https://api-eu.sigencloud.com'),
        'app_key' => env('SIGENERGY_APP_KEY'),
        'app_secret' => env('SIGENERGY_APP_SECRET'),
        
        // MQTT configuration for battery commands
        'mqtt_server' => env('SIGENERGY_MQTT_SERVER'),
        'mqtt_port' => env('SIGENERGY_MQTT_PORT', 1883),
        'mqtt_username' => env('SIGENERGY_MQTT_USERNAME'),
        'mqtt_password' => env('SIGENERGY_MQTT_PASSWORD'),
    ],

    'nordpool' => [
        'base_url' => env('NORDPOOL_BASE_URL', 'https://www.nordpoolgroup.com'),
        'provider' => 'Nord Pool Group',
        'description' => 'Official Nord Pool electricity market data API',
        'default_area' => env('NORDPOOL_DEFAULT_AREA', 'SE3'), // SE3 = Stockholm
        'default_currency' => env('NORDPOOL_DEFAULT_CURRENCY', 'SEK'),
    ],

    'elprisetjustnu' => [
        'base_url' => env('ELPRISETJUSTNU_BASE_URL', 'https://www.elprisetjustnu.se/api/v1/prices'),
        'provider' => 'elprisetjustnu.se',
        'description' => 'Swedish Electricity Spot Prices with 15-minute granularity',
        'default_area' => env('ELECTRICITY_PRICE_AREA', 'SE3'),
        'areas' => [
            'SE1' => 'Luleå (North Sweden)',
            'SE2' => 'Sundsvall (Central Sweden)',
            'SE3' => 'Stockholm (Central Sweden)',
            'SE4' => 'Malmö (South Sweden)'
        ],
    ],

];
