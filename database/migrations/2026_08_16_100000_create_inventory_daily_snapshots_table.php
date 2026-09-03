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
        Schema::create('inventory_daily_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('sku_id')->constrained()->restrictOnDelete();
            $table->integer('opening_qty')->default(0);
            $table->unsignedInteger('received_qty')->default(0);
            $table->unsignedInteger('sold_qty')->default(0);
            $table->unsignedInteger('returned_qty')->default(0);
            $table->unsignedInteger('transfer_in_qty')->default(0);
            $table->unsignedInteger('transfer_out_qty')->default(0);
            $table->integer('adjustment_qty')->default(0);
            $table->integer('closing_qty')->default(0);
            $table->integer('available_qty')->default(0);
            $table->unsignedInteger('stockout_minutes')->nullable();
            $table->boolean('stockout_flag')->default(false);
            $table->decimal('inventory_value', 14, 2)->default(0);
            $table->timestamps();

            // Explicit short names: Laravel's auto-generated names for these
            // (65-66 chars) exceed MySQL's 64-character identifier limit —
            // SQLite has no such limit, which is why this never surfaced
            // against the test suite's in-memory SQLite DB.
            $table->unique(['snapshot_date', 'warehouse_id', 'sku_id'], 'inv_daily_snapshots_date_warehouse_sku_unique');
            $table->index(['warehouse_id', 'sku_id', 'snapshot_date'], 'inv_daily_snapshots_warehouse_sku_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_daily_snapshots');
    }
};
