<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For SQLite, we need to recreate the table without the CHECK constraint
        DB::statement('
            CREATE TABLE battery_history_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                system_id VARCHAR NOT NULL,
                interval_start DATETIME NOT NULL,
                date DATE NOT NULL,
                hour INTEGER NOT NULL,
                soc_start NUMERIC NOT NULL,
                action VARCHAR NOT NULL,
                power_kw NUMERIC,
                price_sek_kwh NUMERIC NOT NULL,
                price_tier VARCHAR,
                daily_avg_price NUMERIC,
                decision_source VARCHAR NOT NULL DEFAULT \'controller\',
                decision_factors TEXT,
                interval_cost_sek NUMERIC,
                cumulative_charge_cost_sek NUMERIC,
                solar_production_kw NUMERIC,
                home_consumption_kw NUMERIC,
                grid_import_kw NUMERIC,
                grid_export_kw NUMERIC,
                created_at DATETIME,
                updated_at DATETIME,
                cost_of_current_charge_sek NUMERIC,
                avg_charge_price_sek_kwh NUMERIC,
                energy_in_battery_kwh NUMERIC
            )
        ');

        // Copy existing data
        DB::statement('INSERT INTO battery_history_new SELECT * FROM battery_history');

        // Drop the old table and rename
        DB::statement('DROP TABLE battery_history');
        DB::statement('ALTER TABLE battery_history_new RENAME TO battery_history');

        // Recreate indexes
        DB::statement('CREATE INDEX battery_history_action_date_index ON battery_history (action, date)');
        DB::statement('CREATE INDEX battery_history_date_index ON battery_history (date)');
        DB::statement('CREATE INDEX battery_history_action_index ON battery_history (action)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {}
};
