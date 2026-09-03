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
        Schema::create('supplier_skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_id')->constrained()->restrictOnDelete();
            $table->string('supplier_sku')->nullable();
            $table->decimal('unit_cost', 12, 2);
            $table->unsignedInteger('minimum_order_qty')->default(1);
            $table->unsignedInteger('order_multiple')->default(1);
            $table->unsignedInteger('expected_lead_time_days')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->unique(['supplier_id', 'sku_id']);
            $table->index('sku_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_skus');
    }
};
