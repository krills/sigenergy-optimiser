<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class BatterySession extends Model
{
    use HasFactory;

    protected $fillable = [
        'system_id',
        'action',
        'status',
        'started_at',
        'ended_at',
        'duration_minutes',
        'planned_duration_minutes',
        'start_soc',
        'end_soc',
        'soc_change',
        'power_kw',
        'energy_kwh',
        'avg_price_sek_kwh',
        'min_price_sek_kwh',
        'max_price_sek_kwh',
        'price_tier',
        'decision_context',
        'expected_benefit_sek',
        'actual_benefit_sek',
        'energy_cost_sek',
        'efficiency_loss_kwh',
        'efficiency_cost_sek',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'start_soc' => 'decimal:2',
        'end_soc' => 'decimal:2',
        'soc_change' => 'decimal:2',
        'power_kw' => 'decimal:3',
        'energy_kwh' => 'decimal:4',
        'avg_price_sek_kwh' => 'decimal:5',
        'min_price_sek_kwh' => 'decimal:5',
        'max_price_sek_kwh' => 'decimal:5',
        'expected_benefit_sek' => 'decimal:4',
        'actual_benefit_sek' => 'decimal:4',
        'energy_cost_sek' => 'decimal:4',
        'efficiency_loss_kwh' => 'decimal:4',
        'efficiency_cost_sek' => 'decimal:4',
        'decision_context' => 'array',
    ];

    /**
     * Get the battery history intervals for this session
     */
    public function historyIntervals(): HasMany
    {
        return $this->hasMany(BatteryHistory::class, 'battery_session_id');
    }

    /**
     * Scope to get active sessions
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get sessions for a specific system
     */
    public function scopeForSystem($query, string $systemId)
    {
        return $query->where('system_id', $systemId);
    }

    /**
     * Scope to get sessions within date range
     */
    public function scopeDateRange($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->whereBetween('started_at', [$startDate, $endDate]);
    }

    /**
     * Calculate actual duration based on started_at and ended_at
     */
    public function calculateActualDuration(): ?int
    {
        if (!$this->started_at || !$this->ended_at) {
            return null;
        }

        return $this->started_at->diffInMinutes($this->ended_at);
    }

    /**
     * Calculate SOC change
     */
    public function calculateSocChange(): ?float
    {
        if ($this->start_soc === null || $this->end_soc === null) {
            return null;
        }

        return $this->end_soc - $this->start_soc;
    }

    /**
     * Calculate energy efficiency for this session
     */
    public function calculateEfficiency(): ?float
    {
        if (!$this->energy_kwh || !$this->efficiency_loss_kwh) {
            return null;
        }

        $totalEnergy = $this->energy_kwh + $this->efficiency_loss_kwh;
        return $this->energy_kwh / $totalEnergy;
    }

    /**
     * Mark session as completed and calculate final metrics
     */
    public function markCompleted(float $endSoc, ?float $actualEnergyKwh = null): void
    {
        $this->update([
            'status' => 'completed',
            'ended_at' => now(),
            'end_soc' => $endSoc,
            'soc_change' => $endSoc - $this->start_soc,
            'duration_minutes' => $this->calculateActualDuration(),
            'energy_kwh' => $actualEnergyKwh ?? $this->energy_kwh,
        ]);

        $this->calculateActualBenefit();
    }

    /**
     * Calculate actual benefit based on what was achieved
     */
    public function calculateActualBenefit(): void
    {
        if ($this->action === 'charge') {
            // Charging cost = energy * average price during session
            $this->energy_cost_sek = $this->energy_kwh * $this->avg_price_sek_kwh;
        } elseif ($this->action === 'discharge') {
            // Discharge value = energy * average price during session  
            $this->energy_cost_sek = $this->energy_kwh * $this->avg_price_sek_kwh;
        }

        $this->save();
    }
}
