<?php

namespace App\Console\Commands;

use App\Services\SigenEnergyApiService;
use Illuminate\Console\Command;

class TestSigenEnergyApi extends Command
{
    protected $signature = 'sigenergy:test';
    protected $description = 'Test Sigenergy API connection and functionality';

    public function handle()
    {
        $this->info('Testing Sigenergy API connection...');
        
        $api = new SigenEnergyApiService();
        
        // Test authentication
        $this->info('Testing authentication...');
        $token = $api->authenticate();
        
        if ($token) {
            $this->info('✅ Authentication successful');
            $this->line("Token: " . substr($token, 0, 20) . '...');
        } else {
            $this->error('❌ Authentication failed');
            $this->error('Please check your SIGENERGY_EMAIL and SIGENERGY_PASSWORD in .env');
            return Command::FAILURE;
        }
        
        // Test system list
        $this->info('Testing system list...');
        $systems = $api->getSystemList();
        
        if ($systems) {
            $this->info('✅ System list retrieved');
            $this->line(json_encode($systems, JSON_PRETTY_PRINT));
            
            // Test device list for first system
            if (!empty($systems)) {
                $firstSystemId = $systems[0]['systemId'] ?? null;
                if ($firstSystemId) {
                    $this->info("Testing device list for system: {$firstSystemId}...");
                    $devices = $api->getDeviceList($firstSystemId);
                    
                    if ($devices) {
                        $this->info('✅ Device list retrieved');
                        $this->line(json_encode($devices, JSON_PRETTY_PRINT));
                    } else {
                        $this->warn('⚠️  Device list failed - endpoint may not exist or no devices');
                    }
                }
            }
        } else {
            $this->warn('⚠️  System list failed - endpoint may not exist');
        }
        
        // Test system overview (legacy)
        $this->info('Testing system overview...');
        $overview = $api->getSystemOverview();
        
        if ($overview) {
            $this->info('✅ System overview retrieved');
            $this->line(json_encode($overview, JSON_PRETTY_PRINT));
        } else {
            $this->warn('⚠️  System overview failed - endpoint may not exist');
        }
        
        // Test battery status
        $this->info('Testing battery status...');
        $batteryStatus = $api->getBatteryStatus();
        
        if ($batteryStatus) {
            $this->info('✅ Battery status retrieved');
            $this->line(json_encode($batteryStatus, JSON_PRETTY_PRINT));
        } else {
            $this->warn('⚠️  Battery status failed - endpoint may not exist');
        }
        
        // Test energy data
        $this->info('Testing energy data...');
        $energyData = $api->getEnergyData();
        
        if ($energyData) {
            $this->info('✅ Energy data retrieved');
            $this->line(json_encode($energyData, JSON_PRETTY_PRINT));
        } else {
            $this->warn('⚠️  Energy data failed - endpoint may not exist');
        }
        
        $this->info('Test completed! Review results above to identify working endpoints.');
        
        return Command::SUCCESS;
    }
}