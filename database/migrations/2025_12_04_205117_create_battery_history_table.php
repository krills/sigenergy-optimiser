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
        Schema::create('battery_history', function (Blueprint $table) {
            $table->id();
            $table->string('system_id')->index(); // Sigenergy system ID

            // Time interval (15-minute blocks)
            $table->timestamp('interval_start')->index(); // Start of 15-min interval
            $table->date('date')->index(); // Date for quick filtering
            $table->integer('hour')->index(); // Hour (0-23) for quick filtering

            // Battery state at this interval
            $table->decimal('soc_start', 5, 2); // SOC at start of interval

            // Action taken during this interval
            $table->enum('action', ['charge', 'discharge', 'idle'])->index();
            $table->decimal('power_kw', 6, 3)->nullable(); // Actual power used (0 for idle)

            // Price context
            $table->decimal('price_sek_kwh', 8, 5)->index(); // Electricity price for this interval
            $table->string('price_tier')->nullable(); // cheapest|middle|expensive
            $table->decimal('daily_avg_price', 8, 5)->nullable(); // Daily average for comparison

            // Decision context
            $table->enum('decision_source', ['planner', 'controller', 'manual', 'emergency'])->default('controller')->index();
            $table->json('decision_factors')->nullable(); // Why this decision was made

            // Cost calculations
            $table->decimal('interval_cost_sek', 10, 4)->nullable(); // Cost/value for this specific interval
            $table->decimal('cumulative_charge_cost_sek', 10, 4)->nullable(); // Running total cost of energy in battery

            // System state
            $table->decimal('solar_production_kw', 6, 3)->nullable(); // Solar generation during interval
            $table->decimal('home_consumption_kw', 6, 3)->nullable(); // Home load during interval
            $table->decimal('grid_import_kw', 6, 3)->nullable(); // Grid import during interval
            $table->decimal('grid_export_kw', 6, 3)->nullable(); // Grid export during interval

            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['system_id', 'date']);
            $table->index(['system_id', 'interval_start']);
            $table->index(['action', 'date']);
            $table->index(['price_tier', 'date']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('battery_history');
    }
};
