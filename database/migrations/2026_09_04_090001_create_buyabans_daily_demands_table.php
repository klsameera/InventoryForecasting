<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Synced daily demand, exactly as the BuyAbans back office reports it.
     *
     * This is deliberately a *separate* table from `inventory_daily_snapshots`
     * rather than a write into it. Snapshots are produced by this application's
     * own stock ledger and are a statement about stock it holds; these rows are
     * a statement about sales another system recorded. Mixing the two would
     * make it impossible to tell which numbers came from where, and the nightly
     * snapshot job would overwrite synced rows on its next pass.
     *
     * `sku_id` and `warehouse_id` are resolved where the synced SKU code and
     * location code match a local record, and left null where they do not — an
     * unmatched row is still real demand and is kept, not discarded.
     */
    public function up(): void
    {
        Schema::create('buyabans_daily_demands', function (Blueprint $table) {
            $table->id();
            $table->date('demand_date');

            // Which location grain this row is aggregated at. The same day's
            // sales appear once per grain synced, so a row is only ever
            // comparable with rows of the same grain.
            $table->string('grain');
            $table->string('location_code')->nullable();

            $table->string('sku_code');
            $table->foreignId('sku_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('sold_qty', 14, 4)->default(0);
            $table->decimal('revenue', 16, 4)->default(0);
            $table->decimal('avg_price', 14, 4)->default(0);
            $table->decimal('discount_amount', 14, 4)->default(0);
            $table->unsignedInteger('order_count')->default(0);

            $table->timestamps();

            // A grain + location + SKU + day is unique, so a re-sync of an
            // overlapping window upserts in place instead of double-counting.
            $table->unique(
                ['grain', 'location_code', 'sku_code', 'demand_date'],
                'buyabans_demand_grain_loc_sku_date_unique'
            );
            $table->index(['sku_id', 'warehouse_id', 'demand_date'], 'buyabans_demand_sku_warehouse_date_index');
            $table->index(['grain', 'demand_date'], 'buyabans_demand_grain_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyabans_daily_demands');
    }
};
