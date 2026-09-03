<?php

namespace App\Models;

use Database\Factories\SalesOrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sales_order_id
 * @property int $sku_id
 * @property int $quantity
 * @property float $unit_price
 * @property float $discount
 * @property float $net_amount
 * @property float|null $cost
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['sales_order_id', 'sku_id', 'quantity', 'unit_price', 'discount', 'net_amount', 'cost'])]
class SalesOrderItem extends Model
{
    /** @use HasFactory<SalesOrderItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'cost' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
