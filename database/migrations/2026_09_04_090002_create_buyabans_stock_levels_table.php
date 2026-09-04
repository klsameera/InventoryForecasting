<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock on hand as the BuyAbans back office currently reports it.
     *
     * Kept out of `inventories` deliberately. That table's balance is derived
     * from this application's own append-only stock ledger, and writing a
     * figure into it that no movement produced would break the invariant every
     * balance there depends on. Cover and reorder urgency want the back
     * office's number, so it lives here, plainly labelled as synced.
     *
     * This is a position, not a history — each sync replaces the previous
     * figure for a (SKU, source) pair.
     */
    public function up(): void
    {
        Schema::create('buyabans_stock_levels', function (Blueprint $table) {
            $table->id();
            $table->string('sku_code');
            $table->foreignId('sku_id')->nullable()->constrained()->nullOnDelete();
            $table->string('inventory_source_code');
            $table->integer('qty')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['sku_code', 'inventory_source_code'], 'buyabans_stock_sku_source_unique');
            $table->index('sku_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyabans_stock_levels');
    }
};
