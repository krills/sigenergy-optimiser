<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class BatteryHistory extends Model
{
    use HasFactory;

    protected $table = 'battery_history';

    protected $fillable = [
        'system_id',
        'interval_start',
        'interval_end',
        'date',
        'hour',
        'soc_start',
        'soc_end',
        'soc_change',
        'action',
        'power_kw',
        'energy_kwh',
        'price_sek_kwh',
        'price_tier',
        'daily_avg_price',
        'decision_source',
        'decision_factors',
        'battery_session_id',
        'interval_cost_sek',
        'cumulative_charge_cost_sek',
        'opportunity_cost_sek',
        'cost_of_current_charge_sek',
        'avg_charge_price_sek_kwh',
        'energy_in_battery_kwh',
        'solar_production_kw',
        'home_consumption_kw',
        'grid_import_kw',
        'grid_export_kw',
    ];

    protected $casts = [
        'interval_start' => 'datetime',
        'interval_end' => 'datetime',
        'date' => 'date',
        'soc_start' => 'decimal:2',
        'soc_end' => 'decimal:2',
        'soc_change' => 'decimal:2',
        'power_kw' => 'decimal:3',
        'energy_kwh' => 'decimal:4',
        'price_sek_kwh' => 'decimal:5',
        'daily_avg_price' => 'decimal:5',
        'interval_cost_sek' => 'decimal:4',
        'cumulative_charge_cost_sek' => 'decimal:4',
        'opportunity_cost_sek' => 'decimal:4',
        'cost_of_current_charge_sek' => 'decimal:4',
        'avg_charge_price_sek_kwh' => 'decimal:5',
        'energy_in_battery_kwh' => 'decimal:4',
        'solar_production_kw' => 'decimal:3',
        'home_consumption_kw' => 'decimal:3',
        'grid_import_kw' => 'decimal:3',
        'grid_export_kw' => 'decimal:3',
        'decision_factors' => 'array',
    ];

    /**
     * Get the battery session this interval belongs to
     */
    public function batterySession(): BelongsTo
    {
        return $this->belongsTo(BatterySession::class, 'battery_session_id');
    }

    /**
     * Scope to get history for a specific system
     */
    public function scopeForSystem($query, string $systemId)
    {
        return $query->where('system_id', $systemId);
    }

    /**
     * Scope to get history for a specific date
     */
    public function scopeForDate($query, Carbon $date)
    {
        return $query->where('date', $date->format('Y-m-d'));
    }

    /**
     * Scope to get history within date range
     */
    public function scopeDateRange($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
    }

    /**
     * Scope to filter by action
     */
    public function scopeAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope to filter by price tier
     */
    public function scopePriceTier($query, string $tier)
    {
        return $query->where('price_tier', $tier);
    }

    /**
     * Scope to get intervals during specific hours
     */
    public function scopeHours($query, int $startHour, int $endHour)
    {
        return $query->whereBetween('hour', [$startHour, $endHour]);
    }

    /**
     * Calculate the energy cost/value for this interval
     */
    public function calculateIntervalCost(): float
    {
        if (!$this->energy_kwh || !$this->price_sek_kwh) {
            return 0;
        }

        if ($this->action === 'charge') {
            // Cost to charge (negative because it costs money)
            return -($this->energy_kwh * $this->price_sek_kwh);
        } elseif ($this->action === 'discharge') {
            // Value from discharging (positive because it saves/earns money)
            return $this->energy_kwh * $this->price_sek_kwh;
        }

        return 0;
    }

    /**
     * Calculate opportunity cost (what we could have saved with optimal action)
     */
    public function calculateOpportunityCost(float $dailyMinPrice, float $dailyMaxPrice): float
    {
        if ($this->action === 'idle') {
            return 0;
        }

        if ($this->action === 'charge' && $this->price_sek_kwh > $dailyMinPrice) {
            // We charged at higher price than minimum available
            return ($this->price_sek_kwh - $dailyMinPrice) * $this->energy_kwh;
        }

        if ($this->action === 'discharge' && $this->price_sek_kwh < $dailyMaxPrice) {
            // We discharged at lower price than maximum available
            return ($dailyMaxPrice - $this->price_sek_kwh) * $this->energy_kwh;
        }

        return 0;
    }

    /**
     * Get daily statistics for a system
     */
    public static function getDailyStats(string $systemId, Carbon $date): array
    {
        $intervals = self::forSystem($systemId)->forDate($date)->get();

        $chargeIntervals = $intervals->where('action', 'charge');
        $dischargeIntervals = $intervals->where('action', 'discharge');

        $totalChargeEnergy = $chargeIntervals->sum('energy_kwh');
        $totalDischargeEnergy = $dischargeIntervals->sum('energy_kwh');
        
        $chargeCost = $chargeIntervals->sum('interval_cost_sek');
        $dischargeValue = $dischargeIntervals->sum('interval_cost_sek');
        
        $netBenefit = abs($dischargeValue) - abs($chargeCost);

        return [
            'total_intervals' => $intervals->count(),
            'charge_intervals' => $chargeIntervals->count(),
            'discharge_intervals' => $dischargeIntervals->count(),
            'idle_intervals' => $intervals->where('action', 'idle')->count(),
            'total_charge_energy_kwh' => $totalChargeEnergy,
            'total_discharge_energy_kwh' => $totalDischargeEnergy,
            'total_charge_cost_sek' => abs($chargeCost),
            'total_discharge_value_sek' => $dischargeValue,
            'net_benefit_sek' => $netBenefit,
            'avg_charge_price' => $chargeIntervals->avg('price_sek_kwh'),
            'avg_discharge_price' => $dischargeIntervals->avg('price_sek_kwh'),
            'soc_start' => $intervals->first()?->soc_start,
            'soc_end' => $intervals->last()?->soc_end,
            'net_soc_change' => ($intervals->last()?->soc_end ?? 0) - ($intervals->first()?->soc_start ?? 0),
        ];
    }

    /**
     * Get cumulative energy cost in the battery (FIFO basis)
     */
    public function updateCumulativeChargeCost(): void
    {
        if ($this->action !== 'charge') {
            return;
        }

        // Get previous cumulative cost
        $previousInterval = self::forSystem($this->system_id)
            ->where('interval_start', '<', $this->interval_start)
            ->orderBy('interval_start', 'desc')
            ->first();

        $previousCost = $previousInterval?->cumulative_charge_cost_sek ?? 0;
        $intervalCost = abs($this->calculateIntervalCost());

        $this->update([
            'cumulative_charge_cost_sek' => $previousCost + $intervalCost
        ]);
    }

    /**
     * Create a new battery history interval
     */
    public static function createInterval(array $data): self
    {
        // Ensure required calculated fields are included in initial create
        if (isset($data['interval_start'])) {
            $intervalStart = is_string($data['interval_start']) ? 
                Carbon::parse($data['interval_start']) : $data['interval_start'];
            
            $data['date'] = $intervalStart->format('Y-m-d');
            $data['hour'] = $intervalStart->hour;
        }

        $interval = self::create($data);
        
        // Update calculated fields
        $intervalCost = $interval->calculateIntervalCost();
        $interval->update(['interval_cost_sek' => $intervalCost]);

        // Update cumulative cost if charging
        if ($interval->action === 'charge') {
            $interval->updateCumulativeChargeCost();
        }

        return $interval;
    }
}