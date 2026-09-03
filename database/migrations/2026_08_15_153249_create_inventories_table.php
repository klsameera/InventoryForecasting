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
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('sku_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('on_hand_qty')->default(0);
            $table->unsignedInteger('reserved_qty')->default(0);
            $table->integer('available_qty')->default(0);
            $table->unsignedInteger('incoming_qty')->default(0);
            $table->decimal('average_cost', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['warehouse_id', 'sku_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
