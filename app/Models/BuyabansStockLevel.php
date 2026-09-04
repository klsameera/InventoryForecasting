<?php

namespace App\Models;

use Domain\Services\BuyabansSyncService\BuyabansSyncService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Stock on hand as the BuyAbans back office currently reports it, per SKU per
 * inventory source. Written only by {@see BuyabansSyncService}.
 *
 * Kept out of {@see Inventory} on purpose: that balance is derived from this
 * application's own append-only ledger, and writing a figure into it that no
 * {@see StockMovement} produced would break the invariant every balance there
 * depends on.
 *
 * A position, not a history — each sync replaces the previous figure.
 *
 * @property int $id
 * @property string $sku_code
 * @property int|null $sku_id
 * @property string $inventory_source_code
 * @property int $qty
 * @property Carbon|null $synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['sku_code', 'sku_id', 'inventory_source_code', 'qty', 'synced_at'])]
class BuyabansStockLevel extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
