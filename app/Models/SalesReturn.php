<?php

namespace App\Models;

use Database\Factories\SalesReturnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Immutable, posted-on-create — no update()/delete(). See SalesReturnService.
 *
 * @property int $id
 * @property string $return_number
 * @property int $sales_order_id
 * @property int $warehouse_id
 * @property Carbon $return_date
 * @property string|null $reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
#[Fillable(['return_number', 'sales_order_id', 'warehouse_id', 'return_date', 'reason', 'created_by'])]
class SalesReturn extends Model
{
    /** @use HasFactory<SalesReturnFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'return_date' => 'date',
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
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<SalesReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }
}
