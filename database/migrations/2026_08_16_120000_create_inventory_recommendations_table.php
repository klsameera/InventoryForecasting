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
        Schema::create('inventory_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('sku_id')->constrained()->restrictOnDelete();
            $table->foreignId('forecast_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recommendation_type');
            $table->unsignedInteger('current_qty');
            $table->unsignedInteger('incoming_qty');
            $table->decimal('forecast_30d', 12, 2)->nullable();
            $table->decimal('forecast_60d', 12, 2)->nullable();
            $table->decimal('forecast_90d', 12, 2)->nullable();
            $table->unsignedInteger('recommended_qty');
            $table->date('recommended_action_date');
            $table->string('stockout_risk')->nullable();
            $table->string('overstock_risk')->nullable();
            $table->string('ageing_risk')->nullable();
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->text('reason')->nullable();
            $table->string('status')->default('NEW');
            $table->unsignedInteger('decided_qty')->nullable();
            $table->string('decision_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'sku_id', 'status']);
            $table->index('recommendation_type');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_recommendations');
    }
};
