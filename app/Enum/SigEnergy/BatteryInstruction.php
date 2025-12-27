<?php

namespace App\Enum\SigEnergy;

/**
 * Sigenergy Battery Operating Modes
 * 
 * These modes control how the battery system operates in different scenarios.
 * Each mode has specific logic for handling PV generation, battery storage,
 * home consumption, and grid interaction.
 */
enum BatteryInstruction: string
{
    /**
     * CHARGE Mode - Forced battery charging
     * 
     * Logic:
     * 1. Prioritize PV energy for battery charging
     * 2. If PV insufficient, supplement with grid power
     * 3. Excess PV energy is fed to grid
     * 
     * Use case: During cheap electricity periods to force charging from grid
     */
    case CHARGE = 'charge';

    /**
     * SELF-CONSUMPTION Mode - Smart home-first battery operation (RECOMMENDED)
     * 
     * Logic:
     * - High solar: PV → Home Load → Battery Storage → Grid Export (priority order)
     * - Low solar: Battery Discharge → Home Load (avoid grid import)
     * 
     * Benefits:
     * - Battery ONLY discharges to home consumption, never to grid
     * - Automatic load-following behavior
     * - Built-in protection against battery-to-grid export
     * - Intelligent PV/battery coordination
     * 
     * Use case: Normal operation when you want battery to supply home needs
     * without wasting energy to grid export
     */
    case SELF_CONSUME = 'selfConsumption';

    /**
     * SELF-CONSUMPTION-GRID Mode - Revenue-focused operation
     * 
     * Logic:
     * - High solar: PV → Home Load → Grid Export (battery charging secondary)
     * - Low solar: Battery Discharge → Home Load
     * 
     * Use case: High electricity price periods where grid export revenue
     * is prioritized over battery storage
     */
    case SELF_CONSUME_GRID = 'selfConsumption - grid';

    /**
     * IDLE Mode - Battery maintenance and pure solar export
     * 
     * Logic:
     * - Battery: No charging or discharging (maintain current SOC)
     * - Solar: All excess PV power exported directly to grid
     * 
     * Use case: Battery protection periods, grid maintenance, or SOC preservation
     */
    case IDLE = 'idle';

    /**
     * DISCHARGE Mode - Direct battery discharge (⚠️ DEPRECATED - Use SELF_CONSUME)
     * 
     * Logic:
     * - Prioritize PV energy for discharge
     * - Follow with energy from storage system
     * - May allow battery-to-grid export (undesired for home optimization)
     * 
     * ⚠️ WARNING: This mode can discharge battery to grid, wasting stored energy.
     * Use SELF_CONSUME instead for home-only battery discharge.
     */
    case DISCHARGE = 'discharge';
}
