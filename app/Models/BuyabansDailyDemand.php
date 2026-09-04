<?php

namespace App\Models;

use Domain\Services\BuyabansSyncService\BuyabansSyncService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per (grain, location, SKU, day) of demand as the BuyAbans back office
 * reports it. Written only by {@see BuyabansSyncService}, and never edited —
 * re-syncing an overlapping window upserts in place rather than double-counting.
 *
 * Deliberately separate from {@see InventoryDailySnapshot}: a snapshot is this
 * application's own statement about stock it holds, while these rows are
 * another system's statement about sales it recorded. Merging them would make
 * it impossible to say which number came from where, and the nightly snapshot
 * job would overwrite synced rows on its next pass.
 *
 * `sku_id` / `warehouse_id` are resolved where the synced codes match a local
 * record and left null where they do not — an unmatched row is still real
 * demand, so it is kept and reported rather than dropped.
 *
 * @property int $id
 * @property Carbon $demand_date
 * @property string $grain
 * @property string|null $location_code
 * @property string $sku_code
 * @property int|null $sku_id
 * @property int|null $warehouse_id
 * @property float $sold_qty
 * @property float $revenue
 * @property float $avg_price
 * @property float $discount_amount
 * @property int $order_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'demand_date', 'grain', 'location_code', 'sku_code', 'sku_id',
    'warehouse_id', 'sold_qty', 'revenue', 'avg_price', 'discount_amount',
    'order_count',
])]
class BuyabansDailyDemand extends Model
{
    public const GRAIN_WAREHOUSE = 'warehouse';

    public const GRAIN_CHANNEL = 'channel';

    public const GRAIN_NATIONAL = 'national';

    /** @var list<string> */
    public const GRAINS = [self::GRAIN_WAREHOUSE, self::GRAIN_CHANNEL, self::GRAIN_NATIONAL];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'demand_date' => 'date',
            'sold_qty' => 'decimal:4',
            'revenue' => 'decimal:4',
            'avg_price' => 'decimal:4',
            'discount_amount' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
