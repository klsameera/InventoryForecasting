<?php

namespace App\Models;

use App\Enums\MovementType;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only stock movement ledger. Never updated or deleted after creation
 * — see app_plan.md §14 and §88.
 *
 * @property int $id
 * @property int $warehouse_id
 * @property int $sku_id
 * @property MovementType $movement_type
 * @property int $quantity
 * @property float|null $unit_cost
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property Carbon $occurred_at
 * @property int|null $user_id
 * @property string|null $notes
 * @property Carbon|null $created_at
 */
#[Fillable([
    'warehouse_id', 'sku_id', 'movement_type', 'quantity', 'unit_cost',
    'reference_type', 'reference_id', 'occurred_at', 'user_id', 'notes',
])]
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_type' => MovementType::class,
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'occurred_at' => 'datetime',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
