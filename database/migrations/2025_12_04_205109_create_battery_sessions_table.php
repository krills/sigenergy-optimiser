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
        Schema::create('battery_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('system_id')->index(); // Sigenergy system ID
            $table->enum('action', ['charge', 'discharge', 'idle'])->index();
            $table->enum('status', ['active', 'completed', 'interrupted'])->default('active')->index();
            
            // Session timing
            $table->timestamp('started_at')->index();
            $table->timestamp('ended_at')->nullable();
            $table->integer('duration_minutes')->nullable(); // Actual duration
            $table->integer('planned_duration_minutes')->nullable(); // Planned duration
            
            // Battery state
            $table->decimal('start_soc', 5, 2); // SOC when session started
            $table->decimal('end_soc', 5, 2)->nullable(); // SOC when session ended
            $table->decimal('soc_change', 5, 2)->nullable(); // Net SOC change
            
            // Power and energy
            $table->decimal('power_kw', 6, 3); // Charging/discharging power
            $table->decimal('energy_kwh', 8, 4)->nullable(); // Total energy transferred
            
            // Pricing context
            $table->decimal('avg_price_sek_kwh', 8, 5); // Average price during session
            $table->decimal('min_price_sek_kwh', 8, 5)->nullable(); // Min price during session
            $table->decimal('max_price_sek_kwh', 8, 5)->nullable(); // Max price during session
            $table->string('price_tier')->nullable(); // cheapest|middle|expensive
            
            // Decision context (why this action was taken)
            $table->json('decision_context'); // Store planner/controller reasoning
            $table->decimal('expected_benefit_sek', 10, 4)->nullable(); // Expected financial benefit
            $table->decimal('actual_benefit_sek', 10, 4)->nullable(); // Calculated after session
            
            // Cost tracking
            $table->decimal('energy_cost_sek', 10, 4)->nullable(); // Cost to charge or value of discharge
            $table->decimal('efficiency_loss_kwh', 8, 4)->nullable(); // Energy lost to round-trip efficiency
            $table->decimal('efficiency_cost_sek', 10, 4)->nullable(); // Cost of efficiency losses
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['system_id', 'started_at']);
            $table->index(['action', 'status']);
            $table->index(['started_at', 'ended_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('battery_sessions');
    }
};
