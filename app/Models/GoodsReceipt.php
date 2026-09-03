<?php

namespace App\Models;

use Database\Factories\GoodsReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Immutable once posted — no update()/delete(). See PurchaseOrderService
 * §receiving and GoodsReceiptService's docblock.
 *
 * @property int $id
 * @property string $receipt_number
 * @property int $purchase_order_id
 * @property int $warehouse_id
 * @property Carbon $received_date
 * @property string|null $notes
 * @property int|null $received_by
 * @property Carbon|null $created_at
 */
#[Fillable(['receipt_number', 'purchase_order_id', 'warehouse_id', 'received_date', 'notes', 'received_by'])]
class GoodsReceipt extends Model
{
    /** @use HasFactory<GoodsReceiptFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<GoodsReceiptItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }
}
