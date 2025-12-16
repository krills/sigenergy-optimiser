<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('battery_history', function (Blueprint $table) {
            // Cost of energy currently in battery at this point in time
            $table->decimal('cost_of_current_charge_sek', 10, 4)->nullable()->after('cumulative_charge_cost_sek');
            
            // Average price of energy currently in battery (for quick reference)
            $table->decimal('avg_charge_price_sek_kwh', 8, 5)->nullable()->after('cost_of_current_charge_sek');
            
            // Energy currently in battery (kWh) at this point
            $table->decimal('energy_in_battery_kwh', 8, 4)->nullable()->after('avg_charge_price_sek_kwh');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('battery_history', function (Blueprint $table) {
            $table->dropColumn(['cost_of_current_charge_sek', 'avg_charge_price_sek_kwh', 'energy_in_battery_kwh']);
        });
    }
};
