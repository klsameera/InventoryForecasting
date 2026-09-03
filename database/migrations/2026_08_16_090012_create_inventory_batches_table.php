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
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('sku_id')->constrained()->restrictOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->date('received_date');
            $table->unsignedInteger('received_qty');
            $table->unsignedInteger('remaining_qty');
            $table->decimal('unit_cost', 12, 2);
            $table->date('expiry_date')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'sku_id', 'received_date']);
            $table->index(['source_type', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_batches');
    }
};
