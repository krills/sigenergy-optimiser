<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Enum\SigEnergy\BatteryInstruction;
use Carbon\Carbon;

class BatteryHistory extends Model
{
    use HasFactory;

    protected $table = 'battery_history';

    protected $fillable = [
        'system_id',
        'interval_start',
        'date',
        'hour',
        'soc_start',
        'action',
        'power_kw',
        'price_sek_kwh',
        'price_tier',
        'daily_avg_price',
        'decision_source',
        'decision_factors',
        'interval_cost_sek',
        'cumulative_charge_cost_sek',
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
        'date' => 'date',
        'action' => BatteryInstruction::class,
        'soc_start' => 'decimal:2',
        'power_kw' => 'decimal:3',
        'price_sek_kwh' => 'decimal:5',
        'daily_avg_price' => 'decimal:5',
        'interval_cost_sek' => 'decimal:4',
        'cumulative_charge_cost_sek' => 'decimal:4',
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
     * Mutator to validate action enum values when setting
     */
    public function setActionAttribute($value): void
    {
        // If it's already a BatteryInstruction enum, use it directly
        if ($value instanceof BatteryInstruction) {
            $this->attributes['action'] = $value->value;
            return;
        }

        // If it's a string, validate it's a valid enum value
        if (is_string($value)) {
            $enum = BatteryInstruction::tryFrom($value);
            if ($enum === null) {
                throw new \InvalidArgumentException("Invalid action value: {$value}. Must be one of: " . implode(', ', array_column(BatteryInstruction::cases(), 'value')));
            }
            $this->attributes['action'] = $value;
            return;
        }

        throw new \InvalidArgumentException("Action must be a string or BatteryInstruction enum, got: " . gettype($value));
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

        /** @var BatteryHistory $interval */
        $interval = self::create($data);

        // Update cumulative cost if charging
        if ($interval->action === BatteryInstruction::CHARGE) {
            //$interval->updateCumulativeChargeCost();
        }

        return $interval;
    }
}
