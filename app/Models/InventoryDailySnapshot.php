<?php

namespace App\Models;

use Database\Factories\InventoryDailySnapshotFactory;
use Domain\Services\InventoryDailySnapshotService\InventoryDailySnapshotService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per (snapshot_date, warehouse, SKU) — the daily demand/availability
 * history app_plan.md §15 says ML needs to tell "no demand" apart from "no
 * stock to sell." Written only by
 * {@see InventoryDailySnapshotService::captureDay()},
 * never edited afterward — re-running capture for an already-captured date
 * upserts in place rather than duplicating.
 *
 * @property int $id
 * @property Carbon $snapshot_date
 * @property int $warehouse_id
 * @property int $sku_id
 * @property int $opening_qty
 * @property int $received_qty
 * @property int $sold_qty
 * @property int $returned_qty
 * @property int $transfer_in_qty
 * @property int $transfer_out_qty
 * @property int $adjustment_qty
 * @property int $closing_qty
 * @property int $available_qty
 * @property int|null $stockout_minutes
 * @property bool $stockout_flag
 * @property float $inventory_value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'snapshot_date', 'warehouse_id', 'sku_id', 'opening_qty', 'received_qty',
    'sold_qty', 'returned_qty', 'transfer_in_qty', 'transfer_out_qty',
    'adjustment_qty', 'closing_qty', 'available_qty', 'stockout_minutes',
    'stockout_flag', 'inventory_value',
])]
class InventoryDailySnapshot extends Model
{
    /** @use HasFactory<InventoryDailySnapshotFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'stockout_flag' => 'boolean',
            'inventory_value' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
