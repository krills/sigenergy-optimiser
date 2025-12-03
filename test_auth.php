<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load Laravel app
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Testing Sigenergy API Authentication...\n";
echo "=====================================\n\n";

try {
    // Get credentials from env
    $username = env('SIGENERGY_USERNAME');
    $password = env('SIGENERGY_PASSWORD');
    
    echo "Username: " . $username . "\n";
    echo "Password: " . str_repeat('*', strlen($password)) . "\n\n";
    
    if (!$username || !$password) {
        echo "❌ Missing credentials in .env file\n";
        echo "Please ensure SIGENERGY_USERNAME and SIGENERGY_PASSWORD are set\n";
        exit(1);
    }
    
    // Test authentication
    $apiService = new \App\Services\SigenEnergyApiService();
    
    echo "Attempting authentication...\n";
    $token = $apiService->authenticate();
    
    if ($token) {
        echo "✅ Authentication successful!\n";
        echo "Token (first 20 chars): " . substr($token, 0, 20) . "...\n\n";
        
        // Test getting system list
        echo "Testing system discovery...\n";
        try {
            $systems = $apiService->getSystemList();
            echo "✅ Found " . count($systems) . " system(s):\n";
            
            foreach ($systems as $system) {
                echo "  - ID: " . $system['id'] . "\n";
                echo "    Name: " . $system['name'] . "\n";
                if (isset($system['location'])) {
                    echo "    Location: " . $system['location'] . "\n";
                }
                echo "\n";
            }
            
        } catch (Exception $e) {
            echo "⚠️ System discovery error: " . $e->getMessage() . "\n";
        }
        
    } else {
        echo "❌ Authentication failed\n";
        echo "Please check your credentials\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\nTest completed.\n";