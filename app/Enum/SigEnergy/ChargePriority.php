<?php

namespace App\Enum\SigEnergy;

enum ChargePriority: string
{
    /**
     * Charge from grid first
     */
    case GRID = 'GRID';
    /**
     * Charge from cells first
     */
    case PV = 'PV';
}
