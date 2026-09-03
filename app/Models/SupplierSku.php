<?php

namespace App\Models;

use Database\Factories\SupplierSkuFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $supplier_id
 * @property int $sku_id
 * @property string|null $supplier_sku
 * @property float $unit_cost
 * @property int $minimum_order_qty
 * @property int $order_multiple
 * @property int|null $expected_lead_time_days
 * @property bool $is_primary
 * @property bool $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'supplier_id', 'sku_id', 'supplier_sku', 'unit_cost', 'minimum_order_qty',
    'order_multiple', 'expected_lead_time_days', 'is_primary', 'status',
])]
class SupplierSku extends Model
{
    /** @use HasFactory<SupplierSkuFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'is_primary' => 'boolean',
            'status' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
