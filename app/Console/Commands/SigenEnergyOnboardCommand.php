<?php

namespace App\Console\Commands;

use App\Services\SigenEnergyApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SigenEnergyOnboardCommand extends Command
{
    protected $signature = 'sigenergy:onboard 
                            {system-ids* : System IDs to onboard}
                            {--dry-run : Show what would be done without executing}';

    protected $description = 'Onboard specified systems to the Sigenergy platform';

    private SigenEnergyApiService $sigenApi;

    public function __construct(SigenEnergyApiService $sigenApi)
    {
        parent::__construct();
        $this->sigenApi = $sigenApi;
    }

    public function handle(): int
    {
        $systemIds = $this->argument('system-ids');
        $isDryRun = $this->option('dry-run');

        if (empty($systemIds)) {
            $this->error('At least one system ID must be provided');
            return self::FAILURE;
        }

        $this->info('Sigenergy System Onboarding');
        $this->info('=========================');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No actual API calls will be made');
        }

        $this->info("Systems to onboard: " . implode(', ', $systemIds));

        if ($isDryRun) {
            $this->info('Would call: POST /openapi/board/onboard');
            $this->info('Payload: ' . json_encode($systemIds, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        try {
            $result = $this->onboardSystems($systemIds);
            
            if ($result) {
                $this->info('✅ Onboarding completed successfully');
                $this->displayResults($result);
                return self::SUCCESS;
            } else {
                $this->error('❌ Onboarding failed');
                return self::FAILURE;
            }

        } catch (\Exception $e) {
            $this->error('❌ Onboarding error: ' . $e->getMessage());
            Log::error('Sigenergy onboarding error', [
                'system_ids' => $systemIds,
                'error' => $e->getMessage()
            ]);
            return self::FAILURE;
        }
    }

    private function onboardSystems(array $systemIds): ?array
    {
        $token = $this->sigenApi->authenticate();
        
        if (!$token) {
            throw new \Exception('Authentication failed');
        }

        $response = $this->sigenApi->makeOnboardRequest($systemIds);
        
        if ($response && $response->successful()) {
            $data = $response->json();
            
            if (isset($data['code']) && $data['code'] !== 0) {
                throw new \Exception($data['msg'] ?? 'Unknown error (code: ' . $data['code'] . ')');
            }
            
            return $data;
        }
        
        throw new \Exception('API request failed: ' . ($response ? $response->status() : 'No response'));
    }

    private function displayResults(array $result): void
    {
        $this->info('');
        $this->info('Onboarding Results:');
        $this->info('------------------');
        
        if (isset($result['data']) && is_array($result['data'])) {
            foreach ($result['data'] as $systemResult) {
                $systemId = $systemResult['systemId'] ?? 'Unknown';
                $success = ($systemResult['code'] ?? 1) === 0;
                $message = $systemResult['msg'] ?? ($success ? 'Success' : 'Failed');
                
                $icon = $success ? '✅' : '❌';
                $this->info("  {$icon} {$systemId}: {$message}");
            }
        } else {
            $this->info('  Response: ' . json_encode($result, JSON_PRETTY_PRINT));
        }
        
        $this->info('');
        $this->info('Timestamp: ' . ($result['timestamp'] ?? 'N/A'));
    }
}