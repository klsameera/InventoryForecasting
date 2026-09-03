<?php

namespace App\Models;

use Database\Factories\InventoryBatchFactory;
use Domain\Services\InventoryBatchService\InventoryBatchService;
use Domain\Services\StockMovementService\StockMovementService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * FIFO lot ledger for ageing analysis. Never written to directly from a
 * controller — created by {@see InventoryBatchService::receive()}
 * and decremented by {@see InventoryBatchService::consumeFifo()},
 * both called from {@see StockMovementService::post()}
 * alongside every inventory-affecting movement. See app_plan.md §23.
 *
 * @property int $id
 * @property int $warehouse_id
 * @property int $sku_id
 * @property string $source_type
 * @property int $source_id
 * @property Carbon $received_date
 * @property int $received_qty
 * @property int $remaining_qty
 * @property float $unit_cost
 * @property Carbon|null $expiry_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'warehouse_id', 'sku_id', 'source_type', 'source_id', 'received_date',
    'received_qty', 'remaining_qty', 'unit_cost', 'expiry_date',
])]
class InventoryBatch extends Model
{
    /** @use HasFactory<InventoryBatchFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'unit_cost' => 'decimal:2',
            'expiry_date' => 'date',
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
