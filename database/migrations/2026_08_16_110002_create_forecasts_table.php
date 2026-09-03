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
        Schema::create('forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_run_id')->constrained('ml_forecast_runs')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('sku_id')->constrained()->restrictOnDelete();
            $table->date('forecast_date');
            $table->unsignedSmallInteger('horizon_days');
            $table->decimal('predicted_qty', 12, 2);
            $table->decimal('lower_qty', 12, 2);
            $table->decimal('upper_qty', 12, 2);
            $table->unsignedTinyInteger('confidence_score');
            $table->foreignId('model_version_id')->nullable()->constrained('ml_model_versions')->nullOnDelete();
            $table->string('forecast_source');
            $table->timestamps();

            $table->index(['warehouse_id', 'sku_id', 'forecast_date']);
            $table->index('forecast_run_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forecasts');
    }
};
