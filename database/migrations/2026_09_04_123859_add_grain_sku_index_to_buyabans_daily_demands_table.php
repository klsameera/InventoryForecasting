<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Covers "how many SKUs do we track at this grain?" with an index.
 *
 * `DashboardService::skusTracked()` asks
 * `COUNT(DISTINCT sku_id) WHERE grain = ?` with no date bound — it is a
 * statement about the whole history, not a window, so it cannot be narrowed.
 * The existing indexes all lead with `grain` followed by `location_code` or
 * `demand_date`, so `sku_id` was never reachable without reading rows: MySQL
 * matched the `grain` prefix and then de-duplicated 752,724 of them.
 *
 * Measured on this table at 942,873 rows, that one query was **11.6 seconds of
 * the dashboard's 13.5 seconds** of query time. Leading an index with
 * `(grain, sku_id)` makes it an index-only scan over the distinct pairs.
 *
 * It stayed invisible for a long time because the table held 163 SKUs and
 * 480,000 rows of largely repeated ids; the cost is in the de-duplication, and
 * that scales with rows read rather than with the answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyabans_daily_demands', function (Blueprint $table) {
            $table->index(['grain', 'sku_id'], 'buyabans_demand_grain_sku_index');
        });
    }

    public function down(): void
    {
        Schema::table('buyabans_daily_demands', function (Blueprint $table) {
            $table->dropIndex('buyabans_demand_grain_sku_index');
        });
    }
};
