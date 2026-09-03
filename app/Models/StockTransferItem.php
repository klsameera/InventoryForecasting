<?php

namespace App\Models;

use Database\Factories\StockTransferItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $stock_transfer_id
 * @property int $sku_id
 * @property int $quantity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['stock_transfer_id', 'sku_id', 'quantity'])]
class StockTransferItem extends Model
{
    /** @use HasFactory<StockTransferItemFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<StockTransfer, $this>
     */
    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class);
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
