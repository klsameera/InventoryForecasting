<?php

namespace App\Models;

use App\Enums\StockTransferStatus;
use Database\Factories\StockTransferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $transfer_number
 * @property int $source_warehouse_id
 * @property int $destination_warehouse_id
 * @property StockTransferStatus $status
 * @property Carbon $transfer_date
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'transfer_number', 'source_warehouse_id', 'destination_warehouse_id',
    'status', 'transfer_date', 'notes', 'created_by',
])]
class StockTransfer extends Model
{
    /** @use HasFactory<StockTransferFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StockTransferStatus::class,
            'transfer_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    /**
     * @return HasMany<StockTransferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }
}
