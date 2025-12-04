<?php

namespace App\Console\Commands;

use App\Services\BatteryPlanner;
use App\Services\ElprisetjustNuApiService;
use Illuminate\Console\Command;
use Carbon\Carbon;

class TestBatteryPlanner extends Command
{
    protected $signature = 'battery:test-planner 
                          {--soc=50 : Current State of Charge (0-100)}
                          {--scenarios : Test multiple SOC scenarios}
                          {--detailed : Show detailed schedule output}
                          {--export : Export schedule to JSON file}';

    protected $description = 'Test the BatteryPlanner service with real Stockholm electricity price data';

    private BatteryPlanner $planner;
    private ElprisetjustNuApiService $priceApi;

    public function __construct(BatteryPlanner $planner, ElprisetjustNuApiService $priceApi)
    {
        parent::__construct();
        $this->planner = $planner;
        $this->priceApi = $priceApi;
    }

    public function handle(): int
    {
        $this->info('🔋 Battery Planner Test - Stockholm Price Data');
        $this->info('═══════════════════════════════════════════');

        try {
            // Get current Stockholm electricity prices
            $prices = $this->priceApi->getTodaysPrices();
            
            if (empty($prices)) {
                $this->error('❌ No price data available from elprisetjustnu.se');
                return 1;
            }

            $this->displayPriceAnalysis($prices);

            if ($this->option('scenarios')) {
                $this->runMultipleScenarios($prices);
            } else {
                $soc = (float) $this->option('soc');
                $this->runSingleScenario($prices, $soc);
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }
    }

    private function displayPriceAnalysis(array $prices): void
    {
        $values = array_column($prices, 'value');
        $stats = [
            'min' => min($values),
            'max' => max($values),
            'avg' => array_sum($values) / count($values),
            'count' => count($values)
        ];

        $this->newLine();
        $this->info('📊 Price Analysis for ' . date('Y-m-d'));
        $this->info('   Total Intervals: ' . $stats['count'] . ' (15-minute intervals)');
        $this->info('   Min Price: ' . round($stats['min'], 4) . ' SEK/kWh');
        $this->info('   Max Price: ' . round($stats['max'], 4) . ' SEK/kWh');
        $this->info('   Avg Price: ' . round($stats['avg'], 4) . ' SEK/kWh');
        $this->info('   Price Spread: ' . round(($stats['max'] - $stats['min']) / $stats['avg'] * 100, 1) . '%');

        // Find cheapest and most expensive periods
        $sortedByPrice = $prices;
        usort($sortedByPrice, fn($a, $b) => $a['value'] <=> $b['value']);
        
        $cheapest = array_slice($sortedByPrice, 0, 5);
        $mostExpensive = array_slice($sortedByPrice, -5);

        $this->newLine();
        $this->info('💰 Cheapest 5 intervals:');
        foreach ($cheapest as $interval) {
            $time = Carbon::parse($interval['time_start'])->format('H:i');
            $this->line('   ' . $time . ': ' . round($interval['value'], 4) . ' SEK/kWh');
        }

        $this->info('🔥 Most expensive 5 intervals:');
        foreach ($mostExpensive as $interval) {
            $time = Carbon::parse($interval['time_start'])->format('H:i');
            $this->line('   ' . $time . ': ' . round($interval['value'], 4) . ' SEK/kWh');
        }
    }

    private function runSingleScenario(array $prices, float $soc): void
    {
        $this->newLine();
        $this->info("🔋 Testing Battery Planning with {$soc}% SOC");
        $this->info('─────────────────────────────────────');

        $result = $this->planner->generateSchedule($prices, $soc);
        $this->displayResults($result);

        if ($this->option('detailed')) {
            $this->displayDetailedSchedule($result['schedule']);
        }

        if ($this->option('export')) {
            $this->exportSchedule($result, $soc);
        }
    }

    private function runMultipleScenarios(array $prices): void
    {
        $scenarios = [
            ['soc' => 20, 'name' => 'Low SOC (Emergency)'],
            ['soc' => 40, 'name' => 'Medium-Low SOC'],
            ['soc' => 60, 'name' => 'Medium SOC'],
            ['soc' => 80, 'name' => 'High SOC']
        ];

        $this->newLine();
        $this->info('🔋 Multiple SOC Scenario Analysis');
        $this->info('═══════════════════════════════════');

        $results = [];
        foreach ($scenarios as $scenario) {
            $result = $this->planner->generateSchedule($prices, $scenario['soc']);
            $results[] = array_merge($scenario, ['result' => $result]);
        }

        // Display comparison table
        $headers = ['Scenario', 'SOC', 'Charge Int.', 'Discharge Int.', 'Idle Int.', 'Net Benefit'];
        $rows = [];

        foreach ($results as $r) {
            $summary = $r['result']['summary'];
            $rows[] = [
                $r['name'],
                $r['soc'] . '%',
                $summary['charge_intervals'],
                $summary['discharge_intervals'], 
                $summary['idle_intervals'],
                round($summary['net_benefit'], 2) . ' SEK'
            ];
        }

        $this->table($headers, $rows);

        // Show optimization insights
        $this->newLine();
        $this->info('💡 Optimization Insights:');
        
        $bestNet = array_reduce($results, fn($max, $r) => 
            (!$max || $r['result']['summary']['net_benefit'] > $max['result']['summary']['net_benefit']) ? $r : $max);
        
        $this->line('   Best net benefit: ' . $bestNet['name'] . ' (' . 
                   round($bestNet['result']['summary']['net_benefit'], 2) . ' SEK)');

        $mostActive = array_reduce($results, fn($max, $r) => 
            (!$max || ($r['result']['summary']['charge_intervals'] + $r['result']['summary']['discharge_intervals']) > 
                      ($max['result']['summary']['charge_intervals'] + $max['result']['summary']['discharge_intervals'])) ? $r : $max);
        
        $this->line('   Most active planning: ' . $mostActive['name'] . ' (' . 
                   ($mostActive['result']['summary']['charge_intervals'] + $mostActive['result']['summary']['discharge_intervals']) . ' actions)');
    }

    private function displayResults(array $result): void
    {
        $analysis = $result['analysis'];
        $summary = $result['summary'];

        $this->info('📈 Price Analysis:');
        $this->line('   Daily average: ' . round($analysis['stats']['avg'], 4) . ' SEK/kWh');
        $this->line('   Charge opportunities: ' . $analysis['charge_opportunities'] . ' intervals');
        $this->line('   Discharge opportunities: ' . $analysis['discharge_opportunities'] . ' intervals');
        $this->line('   Price volatility: ' . round($analysis['price_volatility'], 4));

        $this->newLine();
        $this->info('⚡ Optimization Schedule:');
        $this->line('   Total intervals: ' . $summary['total_intervals']);
        $this->line('   Charge intervals: ' . $summary['charge_intervals'] . ' (' . round($summary['charge_hours'], 1) . ' hours)');
        $this->line('   Discharge intervals: ' . $summary['discharge_intervals'] . ' (' . round($summary['discharge_hours'], 1) . ' hours)');
        $this->line('   Idle intervals: ' . $summary['idle_intervals']);

        $this->newLine();
        $this->info('💰 Financial Impact:');
        $this->line('   Estimated savings: ' . round($summary['estimated_savings'], 2) . ' SEK');
        $this->line('   Estimated earnings: ' . round($summary['estimated_earnings'], 2) . ' SEK');
        $this->line('   Net benefit: ' . round($summary['net_benefit'], 2) . ' SEK');
        $this->line('   Energy efficiency: ' . round($summary['efficiency_utilized'] * 100, 1) . '%');

        if ($summary['net_benefit'] > 0) {
            $this->info('✅ Optimization profitable! Daily benefit: ' . round($summary['net_benefit'], 2) . ' SEK');
        } elseif ($summary['net_benefit'] < 0) {
            $this->warn('⚠️  Optimization cost: ' . round(abs($summary['net_benefit']), 2) . ' SEK');
        } else {
            $this->line('➡️  Neutral optimization (no financial benefit)');
        }
    }

    private function displayDetailedSchedule(array $schedule): void
    {
        $this->newLine();
        $this->info('📋 Detailed 15-Minute Schedule:');
        $this->info('═══════════════════════════════════════');

        $headers = ['Time', 'Action', 'Price', 'Power', 'SOC', 'Reason'];
        $rows = [];

        foreach ($schedule as $entry) {
            if ($entry['action'] === 'idle') continue; // Skip idle periods for brevity
            
            $rows[] = [
                $entry['start_time']->format('H:i') . '-' . $entry['end_time']->format('H:i'),
                ucfirst($entry['action']),
                round($entry['price'], 3) . ' SEK',
                round($entry['power'], 1) . ' kW',
                round($entry['target_soc'], 0) . '%',
                substr($entry['reason'], 0, 40) . '...'
            ];
        }

        if (empty($rows)) {
            $this->line('   No charge/discharge actions scheduled (all idle)');
        } else {
            $this->table($headers, $rows);
        }
    }

    private function exportSchedule(array $result, float $soc): void
    {
        $filename = storage_path('app/battery_schedule_' . date('Y-m-d') . '_soc' . $soc . '.json');
        file_put_contents($filename, json_encode($result, JSON_PRETTY_PRINT));
        
        $this->newLine();
        $this->info('📁 Schedule exported to: ' . $filename);
    }
}