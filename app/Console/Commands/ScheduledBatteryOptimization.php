<?php

namespace App\Console\Commands;

use App\Services\BatteryOptimizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScheduledBatteryOptimization extends Command
{
    protected $signature = 'battery:auto-optimize 
                          {--system-id= : Specific system ID to optimize}
                          {--force : Force optimization even if recently run}';

    protected $description = 'Automated battery optimization for continuous operation';

    private BatteryOptimizationService $optimizer;

    public function __construct()
    {
        parent::__construct();
        $this->optimizer = new BatteryOptimizationService();
    }

    public function handle()
    {
        try {
            // Log optimization attempt
            Log::info('Scheduled battery optimization started');
            
            // Get systems to optimize
            $systems = $this->getSystemsToOptimize();
            
            if (empty($systems)) {
                $this->warn('No systems found for optimization');
                return Command::SUCCESS;
            }
            
            $results = [];
            
            foreach ($systems as $systemId) {
                try {
                    $this->line("Optimizing system: {$systemId}");
                    
                    $result = $this->optimizer->optimizeSystem($systemId);
                    
                    $results[$systemId] = $result;
                    
                    if ($result['success']) {
                        $this->info("✅ System {$systemId} optimized: {$result['decision']['mode']} mode");
                        $this->line("   Reason: {$result['decision']['reason']}");
                        
                        Log::info('System optimized successfully', [
                            'system_id' => $systemId,
                            'mode' => $result['decision']['mode'],
                            'reason' => $result['decision']['reason'],
                            'current_price' => $result['decision']['currentPrice']
                        ]);
                    } else {
                        $this->error("❌ System {$systemId} optimization failed");
                        
                        Log::error('System optimization failed', [
                            'system_id' => $systemId,
                            'result' => $result
                        ]);
                    }
                    
                    // Small delay between systems
                    if (count($systems) > 1) {
                        sleep(5);
                    }
                    
                } catch (\Exception $e) {
                    $this->error("System {$systemId} error: {$e->getMessage()}");
                    
                    Log::error('System optimization exception', [
                        'system_id' => $systemId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
            
            // Summary
            $successful = array_filter($results, fn($r) => $r['success'] ?? false);
            $this->newLine();
            $this->info("Optimization completed: " . count($successful) . "/" . count($results) . " systems optimized");
            
            Log::info('Scheduled optimization completed', [
                'total_systems' => count($results),
                'successful' => count($successful),
                'results' => $results
            ]);
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Scheduled optimization failed: {$e->getMessage()}");
            
            Log::error('Scheduled optimization exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return Command::FAILURE;
        }
    }

    /**
     * Get list of systems that need optimization
     */
    private function getSystemsToOptimize(): array
    {
        try {
            // If specific system ID provided
            if ($this->option('system-id')) {
                return [$this->option('system-id')];
            }
            
            // Auto-discover systems from Sigenergy account
            $sigenergy = new \App\Services\SigenEnergyApiService();
            $systems = $sigenergy->getSystemList();
            
            if (empty($systems)) {
                return [];
            }
            
            // Return all system IDs
            return array_column($systems, 'id');
            
        } catch (\Exception $e) {
            Log::error('Failed to get systems for optimization', [
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
}