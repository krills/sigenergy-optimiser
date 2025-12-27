<?php

namespace App\Console\Commands;

use App\Services\BatteryPlanner;
use App\Contracts\PriceProviderInterface;
use App\Enum\SigEnergy\BatteryInstruction;
use Illuminate\Console\Command;
use Carbon\Carbon;

class ShowBatterySchedule extends Command
{
    protected $signature = 'app:show-plan
                          {--soc=50 : Current State of Charge (0-100)}
                          {--compact : Show only charge/discharge actions (skip idle)}
                          {--hours= : Show specific hours only (e.g., "06-18" for 6 AM to 6 PM)}';

    protected $description = 'Display battery optimization schedule for today in a simple table format';

    private BatteryPlanner $planner;
    private PriceProviderInterface $priceApi;

    public function __construct(BatteryPlanner $planner, PriceProviderInterface $priceApi)
    {
        parent::__construct();
        $this->planner = $planner;
        $this->priceApi = $priceApi;
    }

    public function handle(): int
    {
        $this->info('🔋 Battery Schedule for Today - ' . now()->format('Y-m-d'));
        $this->info('══════════════════════════════════════════════════');

        try {
            // Get current Stockholm electricity prices
            $prices = $this->priceApi->getDayAheadPrices();

            if (empty($prices)) {
                $this->error('❌ No price data available from elprisetjustnu.se');
                return 1;
            }

            $soc = (float) $this->option('soc');
            $compact = $this->option('compact');
            $hoursFilter = $this->option('hours');

            // Parse hours filter if provided
            $startHour = 0;
            $endHour = 23;
            if ($hoursFilter && preg_match('/(\d+)-(\d+)/', $hoursFilter, $matches)) {
                $startHour = (int) $matches[1];
                $endHour = (int) $matches[2];
                $this->line("📅 Showing hours {$startHour}:00 - {$endHour}:59");
            }

            $this->newLine();
            $this->displayPriceStats($prices);
            $this->newLine();

            // Generate battery schedule
            $result = $this->planner->generateSchedule($prices, $soc);

            $this->newLine();
            $this->info("⚡ Actual Schedule (SOC: {$soc}%)");
            $this->displayScheduleTable($result['schedule'], $compact, $startHour, $endHour);

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }
    }

    private function displayPriceStats(array $prices): void
    {
        $values = array_column($prices, 'value');
        $stats = [
            'min' => min($values),
            'max' => max($values),
            'avg' => array_sum($values) / count($values),
            'count' => count($values)
        ];

        $this->info('📊 Stockholm Electricity Prices (SE3)');
        $this->line("   Min: <fg=green>{$this->formatPrice($stats['min'])}</> | " .
                   "Avg: <fg=yellow>{$this->formatPrice($stats['avg'])}</> | " .
                   "Max: <fg=red>{$this->formatPrice($stats['max'])}</>");
        $this->line("   Total intervals: {$stats['count']} (15-minute blocks)");
    }

    private function displayScheduleTable(array $schedule, bool $compact, int $startHour, int $endHour): void
    {
        $headers = ['Time', 'Price (SEK/kWh)', 'Action', 'SOC (%)', 'Reason'];
        $rows = [];

        foreach ($schedule as $entry) {
            $startTime = $entry['start_time'];
            $hour = (int) $startTime->format('H');

            // Apply hour filter
            if ($hour < $startHour || $hour > $endHour) {
                continue;
            }

            // Skip idle actions if compact mode
            if ($compact && $entry['action'] === BatteryInstruction::IDLE) {
                continue;
            }

            $timeRange = $startTime->format('H:i') . '-' . $entry['end_time']->format('H:i');
            $price = $this->formatPrice($entry['price']);
            $action = $this->formatAction($entry['action']);
            $reason = $this->truncateReason($entry['reason']);

            $rows[] = [$timeRange, $price, $action, $reason];
        }

        if (empty($rows)) {
            $this->warn('No schedule entries to display for the specified criteria.');
            return;
        }

        $this->table($headers, $rows);

        if ($compact) {
            $this->line('<fg=gray>💡 Use without --compact to see all intervals including idle periods</>');
        }
    }

    private function displayPotentialWindows(array $analysis, int $startHour, int $endHour, bool $compact): void
    {
        $headers = ['Time', 'Price (SEK/kWh)', 'Action', 'Tier', 'Savings/Earnings', 'Priority'];
        $rows = [];

        // Add charge windows
        foreach ($analysis['charge_windows'] as $window) {
            $startTime = $window['start_time'];
            $hour = (int) $startTime->format('H');

            // Apply hour filter
            if ($hour < $startHour || $hour > $endHour) {
                continue;
            }

            $timeRange = $startTime->format('H:i') . '-' . $window['end_time']->format('H:i');
            $price = $this->formatPrice($window['price']);
            $action = '<fg=blue;options=bold>🔋 CHARGE</>';
            $tier = ucfirst($window['tier']);
            $savings = number_format($window['savings'], 3) . ' SEK';
            $priority = $window['priority'];

            $rows[] = [$timeRange, $price, $action, $tier, $savings, $priority];
        }

        // Add discharge windows
        foreach ($analysis['discharge_windows'] as $window) {
            $startTime = $window['start_time'];
            $hour = (int) $startTime->format('H');

            // Apply hour filter
            if ($hour < $startHour || $hour > $endHour) {
                continue;
            }

            $timeRange = $startTime->format('H:i') . '-' . $window['end_time']->format('H:i');
            $price = $this->formatPrice($window['price']);
            $action = '<fg=magenta;options=bold>⚡ DISCHARGE</>';
            $tier = 'Expensive';
            $earnings = number_format($window['earnings'], 3) . ' SEK';
            $priority = $window['priority'];

            $rows[] = [$timeRange, $price, $action, $tier, $earnings, $priority];
        }

        // Sort by time
        usort($rows, function($a, $b) {
            return strcmp($a[0], $b[0]);
        });

        if (empty($rows)) {
            $this->warn('No potential windows to display for the specified criteria.');
            return;
        }

        $this->table($headers, $rows);

        // Display summary stats
        $chargeCount = count($analysis['charge_windows']);
        $dischargeCount = count($analysis['discharge_windows']);
        $this->line("<fg=gray>💡 Found {$chargeCount} potential charge windows and {$dischargeCount} potential discharge windows</>");
    }

    private function formatPrice(float $price): string
    {
        $formatted = number_format($price, 3);

        // Color coding based on price levels
        if ($price <= 0.15) {
            return "<fg=green>{$formatted}</>";  // Cheap - green
        } elseif ($price >= 1.5) {
            return "<fg=red>{$formatted}</>";    // Expensive - red
        } else {
            return "<fg=yellow>{$formatted}</>";  // Medium - yellow
        }
    }

    private function formatAction(BatteryInstruction $action): string
    {
        return match ($action) {
            BatteryInstruction::CHARGE => '<fg=blue;options=bold>🔋 CHARGE</>',
            BatteryInstruction::DISCHARGE => '<fg=magenta;options=bold>⚡ DISCHARGE</>',
            BatteryInstruction::IDLE => '<fg=gray>😴 IDLE</>',
            default => $action->value,
        };
    }

    private function truncateReason(string $reason): string
    {
        return strlen($reason) > 35 ? substr($reason, 0, 32) . '...' : $reason;
    }
}
