<?php

namespace App\Enum\SigEnergy;

enum BatteryInstruction: string
{
    /**
     * Forced battery charging.
     */
    case CHARGE = 'charge';
    /**
     * Forced battery discharge.
     */
    case DISCHARGE = 'discharge';
    /**
     * Not charging and not discharging.
     */
    case IDLE = 'idle';
    /**
     * Surplus PV power gives priority to charging.
     */
    case SELF_CONSUME = 'selfConsumption';
    /**
     * Surplus PV power prioritizes grid injection.
     */
    case SELF_CONSUME_GRID = 'selfConsumption - grid';
}
