<?php

namespace App\Console\Commands;

use App\Services\BatteryDecisionMaker;
use App\Services\ElectricityPriceAggregator;
use Carbon\Carbon;
use Illuminate\Console\Command;

class TestBatteryDecisions extends Command
{
    protected $signature = 'battery:test-decisions 
                          {--scenario= : Test specific scenario (safety|price|solar|load|full-day)}
                          {--soc= : Starting SOC percentage (20-95)}
                          {--verbose : Show detailed decision reasoning}
                          {--schedule : Generate and show full day schedule}';

    protected $description = 'Test the core battery decision-making logic with various scenarios';

    private BatteryDecisionMaker $decisionMaker;
    private ElectricityPriceAggregator $priceAggregator;

    public function __construct()
    {
        parent::__construct();
        $this->decisionMaker = new BatteryDecisionMaker();
        $this->priceAggregator = new ElectricityPriceAggregator();
    }

    public function handle()
    {
        $this->info('🧠 Testing Battery Decision Maker - Core Intelligence');
        $this->info('=========================================================');
        $this->newLine();

        $scenario = $this->option('scenario') ?? 'all';
        $startSOC = floatval($this->option('soc')) ?: 50.0;

        if ($this->option('schedule')) {
            return $this->testFullDaySchedule($startSOC);
        }

        switch ($scenario) {
            case 'safety':
                $this->testSafetyConstraints();
                break;
            case 'price':
                $this->testPriceBasedDecisions($startSOC);
                break;
            case 'solar':
                $this->testSolarScenarios($startSOC);
                break;
            case 'load':
                $this->testLoadScenarios($startSOC);
                break;
            case 'full-day':
                $this->testFullDaySchedule($startSOC);
                break;
            case 'all':
            default:
                $this->testAllScenarios($startSOC);
                break;
        }

        $this->newLine();
        $this->info('✅ Battery decision testing completed!');
        return Command::SUCCESS;
    }

    private function testAllScenarios(float $startSOC): void
    {
        $this->testSafetyConstraints();
        $this->newLine();
        $this->testPriceBasedDecisions($startSOC);
        $this->newLine();
        $this->testSolarScenarios($startSOC);
        $this->newLine();
        $this->testLoadScenarios($startSOC);
        $this->newLine();
        $this->testRealWorldScenarios($startSOC);
    }

    private function testSafetyConstraints(): void
    {
        $this->info('🛡️ Testing Safety Constraints...');
        
        $scenarios = [
            ['soc' => 15, 'expected' => 'charge', 'description' => 'Critical low SOC'],
            ['soc' => 22, 'expected' => 'charge', 'description' => 'Emergency charging threshold'],
            ['soc' => 96, 'expected' => 'idle', 'description' => 'Maximum SOC reached'],
            ['soc' => 19, 'expected' => 'idle', 'description' => 'Minimum SOC protection'],
        ];

        $testPrices = array_fill(0, 24, 0.50); // Neutral prices

        foreach ($scenarios as $scenario) {
            $decision = $this->decisionMaker->makeDecision(
                0.50, // Neutral price
                $testPrices,
                $scenario['soc']
            );

            $this->displayDecision($scenario['description'], $decision, [
                'SOC' => $scenario['soc'] . '%',
                'Expected' => $scenario['expected']
            ]);
        }
    }

    private function testPriceBasedDecisions(float $startSOC): void
    {
        $this->info('💰 Testing Price-Based Decisions...');
        
        $priceScenarios = [
            ['price' => 0.05, 'description' => 'Very cheap electricity (force charge)'],
            ['price' => 0.20, 'description' => 'Cheap electricity'],
            ['price' => 0.50, 'description' => 'Medium electricity price'],
            ['price' => 0.80, 'description' => 'Expensive electricity'],
            ['price' => 1.50, 'description' => 'Very expensive electricity (force discharge)'],
        ];

        // Create realistic price patterns
        $basePrices = [
            0.30, 0.28, 0.25, 0.23, 0.22, 0.25, // Night hours (0-5)
            0.35, 0.45, 0.55, 0.60, 0.65, 0.70, // Morning peak (6-11)
            0.65, 0.60, 0.55, 0.50, 0.55, 0.75, // Afternoon (12-17)
            0.85, 0.90, 0.80, 0.65, 0.45, 0.35  // Evening peak + night (18-23)
        ];

        foreach ($priceScenarios as $scenario) {
            // Replace current hour price with test price
            $testPrices = $basePrices;
            $testPrices[now()->hour] = $scenario['price'];

            $decision = $this->decisionMaker->makeDecision(
                $scenario['price'],
                $testPrices,
                $startSOC
            );

            $this->displayDecision($scenario['description'], $decision, [
                'Price' => number_format($scenario['price'], 3) . ' SEK/kWh',
                'SOC' => $startSOC . '%'
            ]);
        }
    }

    private function testSolarScenarios(float $startSOC): void
    {
        $this->info('☀️ Testing Solar Production Scenarios...');
        
        $solarScenarios = [
            ['solar' => 0, 'load' => 2.0, 'description' => 'No solar, high load (import needed)'],
            ['solar' => 1.5, 'load' => 2.0, 'description' => 'Low solar, medium load (slight import)'],
            ['solar' => 3.0, 'load' => 2.0, 'description' => 'Good solar, medium load (export available)'],
            ['solar' => 6.0, 'load' => 2.0, 'description' => 'High solar, medium load (excess export)'],
            ['solar' => 8.0, 'load' => 1.0, 'description' => 'Very high solar, low load (maximum export)'],
        ];

        $testPrices = array_fill(0, 24, 0.45); // Medium prices

        foreach ($solarScenarios as $scenario) {
            $decision = $this->decisionMaker->makeDecision(
                0.45, // Medium price
                $testPrices,
                $startSOC,
                $scenario['solar'],
                $scenario['load']
            );

            $netLoad = $scenario['load'] - $scenario['solar'];
            $this->displayDecision($scenario['description'], $decision, [
                'Solar' => $scenario['solar'] . ' kW',
                'Load' => $scenario['load'] . ' kW',
                'Net Load' => ($netLoad > 0 ? '+' : '') . number_format($netLoad, 1) . ' kW',
                'SOC' => $startSOC . '%'
            ]);
        }
    }

    private function testLoadScenarios(float $startSOC): void
    {
        $this->info('🏠 Testing Load Balancing Scenarios...');
        
        $loadScenarios = [
            ['load' => 0.5, 'description' => 'Very low load (night time)'],
            ['load' => 1.5, 'description' => 'Normal load (base consumption)'],
            ['load' => 3.5, 'description' => 'High load (cooking, washing)'],
            ['load' => 6.0, 'description' => 'Very high load (heating + appliances)'],
            ['load' => 8.0, 'description' => 'Peak load (everything running)'],
        ];

        $testPrices = array_fill(0, 24, 0.55); // Slightly expensive

        foreach ($loadScenarios as $scenario) {
            $decision = $this->decisionMaker->makeDecision(
                0.55,
                $testPrices,
                $startSOC,
                1.0, // Small solar
                $scenario['load']
            );

            $this->displayDecision($scenario['description'], $decision, [
                'Load' => $scenario['load'] . ' kW',
                'Solar' => '1.0 kW',
                'Net Need' => number_format($scenario['load'] - 1.0, 1) . ' kW',
                'SOC' => $startSOC . '%'
            ]);
        }
    }

    private function testRealWorldScenarios(float $startSOC): void
    {
        $this->info('🌍 Testing Real-World Scenarios...');
        
        // Get real current prices
        try {
            $realPrices = $this->priceAggregator->getNext24HourPrices();
            $currentPrice = $this->priceAggregator->getCurrentPrice();
            
            $this->line("Using REAL Stockholm prices - Current: " . number_format($currentPrice, 3) . " SEK/kWh");
            $this->newLine();
            
        } catch (\Exception $e) {
            $this->warn('Could not get real prices, using simulated data');
            $realPrices = [
                0.30, 0.28, 0.25, 0.23, 0.22, 0.25, // Night
                0.35, 0.45, 0.55, 0.60, 0.65, 0.70, // Morning
                0.65, 0.60, 0.55, 0.50, 0.55, 0.75, // Afternoon  
                0.85, 0.90, 0.80, 0.65, 0.45, 0.35  // Evening
            ];
            $currentPrice = $realPrices[now()->hour];
        }

        $realScenarios = [
            [
                'time' => '06:00',
                'solar' => 0,
                'load' => 2.5,
                'description' => 'Morning startup (no solar, medium load)'
            ],
            [
                'time' => '12:00', 
                'solar' => 4.5,
                'load' => 1.8,
                'description' => 'Midday peak solar (low load, excess solar)'
            ],
            [
                'time' => '18:00',
                'solar' => 1.0,
                'load' => 4.2,
                'description' => 'Evening peak (low solar, high load)'
            ],
            [
                'time' => '22:00',
                'solar' => 0,
                'load' => 1.2,
                'description' => 'Night time (no solar, low load)'
            ],
        ];

        foreach ($realScenarios as $scenario) {
            $timeHour = intval(explode(':', $scenario['time'])[0]);
            $scenarioPrice = $realPrices[$timeHour];
            
            $decision = $this->decisionMaker->makeDecision(
                $scenarioPrice,
                $realPrices,
                $startSOC,
                $scenario['solar'],
                $scenario['load'],
                now()->setHour($timeHour)->setMinute(0)
            );

            $this->displayDecision(
                $scenario['time'] . ' - ' . $scenario['description'],
                $decision,
                [
                    'Price' => number_format($scenarioPrice, 3) . ' SEK/kWh',
                    'Solar' => $scenario['solar'] . ' kW',
                    'Load' => $scenario['load'] . ' kW',
                    'SOC' => $startSOC . '%'
                ]
            );
        }
    }

    private function testFullDaySchedule(float $startSOC): void
    {
        $this->info('📅 Testing Full Day Schedule Generation...');
        
        try {
            $realPrices = $this->priceAggregator->getNext24HourPrices();
            $this->line("Using REAL Stockholm prices for schedule");
        } catch (\Exception $e) {
            $this->warn('Using simulated prices for schedule');
            $realPrices = $this->generateRealisticPricePattern();
        }

        // Generate typical solar and load patterns
        $solarForecast = $this->generateSolarForecast();
        $loadForecast = $this->generateLoadForecast();

        $this->line("Generating 24-hour schedule (96 quarter-hour intervals)...");
        $this->newLine();

        $schedule = $this->decisionMaker->generateDaySchedule(
            $realPrices,
            $startSOC,
            $solarForecast,
            $loadForecast
        );

        $this->displayScheduleSummary($schedule);
        
        if ($this->option('verbose')) {
            $this->displayDetailedSchedule($schedule);
        }
    }

    private function displayDecision(string $scenario, array $decision, array $context = []): void
    {
        $action = strtoupper($decision['action']);
        $actionEmoji = match($decision['action']) {
            'charge' => '🔋',
            'discharge' => '⚡',
            'idle' => '⏸️',
            default => '❓'
        };

        $confidenceEmoji = match($decision['confidence']) {
            'critical' => '🚨',
            'very_high' => '✅',
            'high' => '🟢',
            'medium' => '🟡',
            'low' => '🔶',
            default => '⚪'
        };

        $this->line("  {$actionEmoji} {$scenario}:");
        $this->line("     Action: {$confidenceEmoji} {$action} @ " . number_format($decision['power_kw'], 1) . " kW for {$decision['duration_minutes']} min");
        
        if (!empty($context)) {
            $contextStr = implode(' | ', array_map(fn($k, $v) => "{$k}: {$v}", array_keys($context), $context));
            $this->line("     Context: {$contextStr}");
        }
        
        if ($this->option('verbose')) {
            $this->line("     Reason: {$decision['reason']}");
            $this->line("     Confidence: {$decision['confidence']} | Priority: {$decision['priority']}");
            if ($decision['estimated_soc_change'] != 0) {
                $socChange = ($decision['estimated_soc_change'] > 0 ? '+' : '') . number_format($decision['estimated_soc_change'], 1);
                $this->line("     SOC Change: {$socChange}%");
            }
        }
        
        $this->newLine();
    }

    private function displayScheduleSummary(array $schedule): void
    {
        $summary = $schedule['summary'];
        
        $this->table(['Metric', 'Value'], [
            ['Starting SOC', number_format($schedule['starting_soc'], 1) . '%'],
            ['Ending SOC', number_format($schedule['ending_soc'], 1) . '%'],
            ['Total Intervals', $summary['total_intervals']],
            ['Charge Intervals', $summary['charge_intervals'] . ' (' . round($summary['charge_intervals']/$summary['total_intervals']*100, 1) . '%)'],
            ['Discharge Intervals', $summary['discharge_intervals'] . ' (' . round($summary['discharge_intervals']/$summary['total_intervals']*100, 1) . '%)'],
            ['Idle Intervals', $summary['idle_intervals'] . ' (' . round($summary['idle_intervals']/$summary['total_intervals']*100, 1) . '%)'],
            ['Total Charge Energy', $summary['total_charge_energy_kwh'] . ' kWh'],
            ['Total Discharge Energy', $summary['total_discharge_energy_kwh'] . ' kWh'],
            ['Efficiency Loss', $summary['efficiency_loss_kwh'] . ' kWh'],
            ['Net Energy', ($summary['net_energy_kwh'] > 0 ? '+' : '') . $summary['net_energy_kwh'] . ' kWh']
        ]);
    }

    private function displayDetailedSchedule(array $schedule): void
    {
        $this->newLine();
        $this->info('📋 Detailed Schedule (showing major actions):');
        $this->newLine();

        $lastAction = 'idle';
        $sessionStart = null;
        $sessionPower = 0;

        foreach ($schedule['schedule'] as $interval) {
            $decision = $interval['decision'];
            $currentAction = $decision['action'];

            // Detect action changes to show sessions
            if ($currentAction !== $lastAction) {
                // End previous session
                if ($lastAction !== 'idle' && $sessionStart) {
                    $sessionDuration = $interval['timestamp']->diffInMinutes($sessionStart);
                    $this->line("    └─ Session ended after {$sessionDuration} minutes");
                    $this->newLine();
                }

                // Start new session
                if ($currentAction !== 'idle') {
                    $actionEmoji = match($currentAction) {
                        'charge' => '🔋',
                        'discharge' => '⚡',
                        default => '⏸️'
                    };

                    $this->line("{$interval['time']} {$actionEmoji} " . strtoupper($currentAction) . " SESSION START");
                    $this->line("    ├─ Power: " . number_format($decision['power_kw'], 1) . " kW");
                    $this->line("    ├─ Price: " . number_format($interval['price'], 3) . " SEK/kWh");
                    $this->line("    ├─ SOC: " . $interval['soc_before'] . "%");
                    $this->line("    ├─ Solar: " . $interval['solar_kw'] . " kW | Load: " . $interval['load_kw'] . " kW");
                    $this->line("    ├─ Reason: " . $decision['reason']);

                    $sessionStart = $interval['timestamp'];
                    $sessionPower = $decision['power_kw'];
                }
            }

            $lastAction = $currentAction;
        }

        // End final session if needed
        if ($lastAction !== 'idle' && $sessionStart) {
            $sessionDuration = end($schedule['schedule'])['timestamp']->diffInMinutes($sessionStart) + 15;
            $this->line("    └─ Final session duration: {$sessionDuration} minutes");
        }
    }

    private function generateRealisticPricePattern(): array
    {
        // Typical Stockholm price pattern
        return [
            0.30, 0.28, 0.25, 0.23, 0.22, 0.25, // Night: low
            0.35, 0.45, 0.55, 0.60, 0.65, 0.70, // Morning: rising
            0.65, 0.60, 0.55, 0.50, 0.55, 0.75, // Afternoon: variable
            0.85, 0.90, 0.80, 0.65, 0.45, 0.35  // Evening: peak then drop
        ];
    }

    private function generateSolarForecast(): array
    {
        // 96 quarter-hour intervals with realistic solar pattern
        $solar = [];
        for ($i = 0; $i < 96; $i++) {
            $hour = $i / 4;
            
            if ($hour < 6 || $hour > 18) {
                $solar[] = 0; // No solar at night
            } else {
                // Bell curve for solar production
                $peakHour = 12;
                $maxPower = 6.0; // kW peak
                $width = 6; // Hours of significant production
                
                $solarFactor = exp(-0.5 * pow(($hour - $peakHour) / ($width / 2), 2));
                $solar[] = round($maxPower * $solarFactor, 1);
            }
        }
        return $solar;
    }

    private function generateLoadForecast(): array
    {
        // 96 quarter-hour intervals with realistic load pattern  
        $load = [];
        for ($i = 0; $i < 96; $i++) {
            $hour = $i / 4;
            
            // Base load with peaks
            if ($hour < 6) {
                $load[] = 1.0; // Night: minimal
            } elseif ($hour < 9) {
                $load[] = 2.5; // Morning: medium
            } elseif ($hour < 17) {
                $load[] = 1.8; // Day: low-medium
            } elseif ($hour < 21) {
                $load[] = 3.5; // Evening: high
            } else {
                $load[] = 1.5; // Late evening: low
            }
        }
        return $load;
    }
}