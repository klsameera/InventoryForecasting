<?php

namespace App\Models;

use App\Enums\ReturnCondition;
use Database\Factories\SalesReturnItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sales_return_id
 * @property int $sales_order_item_id
 * @property int $sku_id
 * @property int $quantity
 * @property ReturnCondition $condition
 * @property Carbon|null $created_at
 */
#[Fillable(['sales_return_id', 'sales_order_item_id', 'sku_id', 'quantity', 'condition'])]
class SalesReturnItem extends Model
{
    /** @use HasFactory<SalesReturnItemFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'condition' => ReturnCondition::class,
        ];
    }

    /**
     * @return BelongsTo<SalesReturn, $this>
     */
    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    /**
     * @return BelongsTo<SalesOrderItem, $this>
     */
    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
