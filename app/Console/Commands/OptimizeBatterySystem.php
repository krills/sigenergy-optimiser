<?php

namespace App\Console\Commands;

use App\Services\BatteryOptimizationService;
use App\Services\ElectricityPriceAggregator;
use App\Services\SigenEnergyApiService;
use Illuminate\Console\Command;

class OptimizeBatterySystem extends Command
{
    protected $signature = 'battery:optimize 
                          {system_id? : The system ID to optimize}
                          {--dry-run : Show recommendations without executing}
                          {--test-prices : Use test price scenarios}';

    protected $description = 'Optimize battery charging/discharging based on Stockholm electricity prices';

    private BatteryOptimizationService $optimizer;
    private ElectricityPriceAggregator $priceAggregator;
    private SigenEnergyApiService $sigenergy;

    public function __construct()
    {
        parent::__construct();
        $this->optimizer = new BatteryOptimizationService();
        $this->priceAggregator = new ElectricityPriceAggregator();
        $this->sigenergy = new SigenEnergyApiService();
    }

    public function handle()
    {
        $this->info('🔋 Stockholm Battery Optimization System');
        $this->newLine();

        // Test API connectivity first
        if (!$this->testConnectivity()) {
            return Command::FAILURE;
        }

        // Get or discover system ID
        $systemId = $this->argument('system_id') ?? $this->discoverSystemId();
        
        if (!$systemId) {
            $this->error('❌ No system ID provided and auto-discovery failed');
            return Command::FAILURE;
        }

        $this->info("📍 Optimizing system: {$systemId}");
        $this->newLine();

        try {
            if ($this->option('dry-run')) {
                return $this->showRecommendations($systemId);
            } else {
                return $this->executeOptimization($systemId);
            }
        } catch (\Exception $e) {
            $this->error("❌ Optimization failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function testConnectivity(): bool
    {
        $this->info('🧪 Testing API connectivity...');

        // Test Price Aggregator
        $this->line('Testing Price Aggregator (Multi-Provider)...');
        $priceStatus = $this->priceAggregator->getProviderStatus();
        $availableProviders = array_filter($priceStatus, fn($status) => $status['is_available']);
        
        $this->info("✅ Price aggregator ready with " . count($availableProviders) . " provider(s)");
        foreach ($availableProviders as $name => $status) {
            $this->line("  - {$name}: {$status['current_price']} SEK/kWh (reliability: {$status['reliability_score']}%)");
        }
        
        // Show consensus information
        $consensus = $this->priceAggregator->getPriceConsensus();
        $this->line("  Consensus: " . ($consensus['consensus'] ? '✅ Yes' : '⚠️ No') . 
                   " | Price: " . number_format($consensus['consensus_price'], 3) . 
                   " SEK/kWh | Method: {$consensus['method']}");
        
        if (!empty($availableProviders)) {
            $currentPrice = $this->priceAggregator->getCurrentPrice();
            $next24h = $this->priceAggregator->getNext24HourPrices();
            $this->line("Current aggregated price: " . number_format($currentPrice, 3) . " SEK/kWh");
            $this->line("Today's range: " . number_format(min($next24h), 3) . " - " . number_format(max($next24h), 3) . " SEK/kWh");
        } else {
            $this->warn("⚠️ No price providers available");
        }

        // Test Sigenergy API
        $this->line('Testing Sigenergy API...');
        $token = $this->sigenergy->authenticate();
        
        if ($token) {
            $this->info('✅ Sigenergy API authenticated');
        } else {
            $this->error('❌ Sigenergy API authentication failed');
            return false;
        }

        $this->newLine();
        return true;
    }

    private function discoverSystemId(): ?string
    {
        $this->line('🔍 Discovering available systems...');
        
        try {
            $systems = $this->sigenergy->getSystemList();
            
            if (empty($systems)) {
                $this->warn('No systems found in account');
                return null;
            }

            if (count($systems) === 1) {
                $system = $systems[0];
                $this->info("Found system: {$system['id']} ({$system['name']})");
                return $system['id'];
            }

            // Multiple systems - let user choose
            $this->table(['ID', 'Name', 'Location'], array_map(function($system) {
                return [$system['id'], $system['name'], $system['location'] ?? 'N/A'];
            }, $systems));

            $systemId = $this->ask('Enter system ID to optimize');
            return $systemId;
            
        } catch (\Exception $e) {
            $this->error("Failed to discover systems: {$e->getMessage()}");
            return null;
        }
    }

    private function showRecommendations(string $systemId): int
    {
        $this->info('📊 Generating optimization recommendations...');
        
        $recommendations = $this->optimizer->getOptimizationRecommendations($systemId);
        
        $this->displaySystemAnalysis($recommendations['analysis']);
        $this->displayOptimizationDecision($recommendations['decision']);
        
        $this->newLine();
        $this->info('💡 This was a dry run. Use without --dry-run to execute optimization.');
        
        return Command::SUCCESS;
    }

    private function executeOptimization(string $systemId): int
    {
        $this->info('⚡ Executing battery optimization...');
        
        $result = $this->optimizer->optimizeSystem($systemId);
        
        if (!$result['success']) {
            $this->error('❌ Optimization failed');
            return Command::FAILURE;
        }

        $this->displaySystemAnalysis($result['analysis']);
        $this->displayOptimizationDecision($result['decision']);
        $this->displayExecutionResult($result['result']);
        
        // Show scheduled optimizations
        if (!empty($result['decision']['schedule'])) {
            $this->newLine();
            $this->info('📅 Scheduled future optimizations:');
            foreach (array_slice($result['decision']['schedule'], 0, 5) as $scheduled) {
                $time = date('H:i', $scheduled['time']);
                $this->line("  {$time}: {$scheduled['action']} - {$scheduled['reason']}");
            }
        }
        
        $this->newLine();
        $this->info('✅ Optimization completed successfully!');
        
        return Command::SUCCESS;
    }

    private function displaySystemAnalysis(array $analysis): void
    {
        $this->newLine();
        $this->info('📋 System Analysis:');
        
        $this->table(['Metric', 'Value', 'Unit'], [
            ['Battery SOC', number_format($analysis['soc'], 1), '%'],
            ['Solar Power', number_format($analysis['pvPower'], 2), 'kW'],
            ['Grid Power', number_format($analysis['gridPower'], 2), 'kW'],
            ['Home Load', number_format($analysis['loadPower'], 2), 'kW'],
            ['Battery Power', number_format($analysis['batteryPower'], 2), 'kW'],
            ['Max Charge Power', number_format($analysis['maxChargePower'], 1), 'kW'],
            ['Max Discharge Power', number_format($analysis['maxDischargePower'], 1), 'kW'],
            ['Available Charge Capacity', number_format($analysis['availableChargeCapacity'], 2), 'kWh'],
            ['Available Discharge Capacity', number_format($analysis['availableDischargeCapacity'], 2), 'kWh'],
            ['System Status', $analysis['systemStatus'], '-']
        ]);
        
        // Status indicators
        if ($analysis['isExportingSolar']) {
            $this->line('☀️ Exporting solar energy to grid');
        }
        
        if ($analysis['isImportingFromGrid']) {
            $this->line('🔌 Importing power from grid');
        }
    }

    private function displayOptimizationDecision(array $decision): void
    {
        $this->newLine();
        $this->info('🎯 Optimization Decision:');
        
        $priceCategory = $decision['priceAnalysis']['priceCategory'] ?? 'unknown';
        $currentPrice = number_format($decision['currentPrice'], 3);
        
        $this->line("Current Price: {$currentPrice} SEK/kWh ({$priceCategory})");
        $this->line("Recommended Mode: {$decision['mode']}");
        $this->line("Reason: {$decision['reason']}");
        
        if (isset($decision['duration'])) {
            $durationHours = $decision['duration'] / 3600;
            $this->line("Duration: {$durationHours} hours");
        }
        
        if (isset($decision['chargingPower'])) {
            $this->line("Charging Power: {$decision['chargingPower']} kW");
        }
        
        if (isset($decision['dischargingPower'])) {
            $this->line("Discharging Power: {$decision['dischargingPower']} kW");
        }

        // Price analysis
        if (isset($decision['priceAnalysis'])) {
            $analysis = $decision['priceAnalysis'];
            $this->newLine();
            $this->line('💰 Price Analysis:');
            $this->line("  Today's Range: {$analysis['minPrice']} - {$analysis['maxPrice']} SEK/kWh");
            $this->line("  Average: " . number_format($analysis['avgPrice'], 3) . " SEK/kWh");
            $this->line("  Price Percentile: " . number_format($analysis['pricePercentile'], 1) . "%");
            $this->line("  Next Hour Trend: {$analysis['nextHourTrend']}");
            
            if (!empty($analysis['chargeWindows'])) {
                $this->line("  Optimal Charge Windows: " . count($analysis['chargeWindows']));
            }
            
            if (!empty($analysis['dischargeWindows'])) {
                $this->line("  Optimal Discharge Windows: " . count($analysis['dischargeWindows']));
            }
        }
    }

    private function displayExecutionResult(array $result): void
    {
        $this->newLine();
        
        if ($result['command_sent']) {
            $this->info('✅ Command sent to battery system');
            
            if (isset($result['api_result'])) {
                $this->line('API Response: ' . json_encode($result['api_result'], JSON_PRETTY_PRINT));
            }
        } else {
            $this->error('❌ Failed to send command to battery system');
            
            if (isset($result['error'])) {
                $this->error("Error: {$result['error']}");
            }
        }
    }
}