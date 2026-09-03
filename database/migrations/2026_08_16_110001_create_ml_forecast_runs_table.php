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
        Schema::create('ml_forecast_runs', function (Blueprint $table) {
            $table->id();
            $table->json('warehouse_ids')->nullable();
            $table->unsignedSmallInteger('horizon_days');
            $table->string('status')->default('queued');
            $table->foreignId('model_version_id')->nullable()->constrained('ml_model_versions')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ml_forecast_runs');
    }
};
