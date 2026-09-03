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
        Schema::create('forecast_accuracy', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_id')->constrained('forecasts')->cascadeOnDelete();
            $table->decimal('actual_qty', 12, 2);
            $table->decimal('absolute_error', 12, 2);
            $table->decimal('percentage_error', 8, 2)->nullable();
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique('forecast_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forecast_accuracy');
    }
};
