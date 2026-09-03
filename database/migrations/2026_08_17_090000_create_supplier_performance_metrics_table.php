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
        Schema::create('supplier_performance_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('ordered_qty')->default(0);
            $table->unsignedInteger('received_qty')->default(0);
            $table->decimal('average_lead_time_days', 8, 2)->nullable();
            $table->decimal('lead_time_std_dev', 8, 2)->nullable();
            $table->decimal('on_time_percentage', 5, 2)->nullable();
            $table->decimal('fill_rate', 5, 2)->nullable();
            $table->decimal('quality_issue_rate', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'period_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_performance_metrics');
    }
};
