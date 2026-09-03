<?php

namespace App\Models;

use App\Enums\PromotionDiscountType;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Phase 10 (app_plan.md §86, "promotion impact") — a real record of a
 * discount that ran on a set of SKUs over a date range, so its actual
 * effect on demand can be measured afterward from real sales, instead of
 * having no promotions concept in this schema at all. Deliberately has no
 * status workflow: "upcoming/active/ended" is derived purely from
 * `start_date`/`end_date` against today, not a separate field someone has
 * to remember to update.
 *
 * @property int $id
 * @property string $name
 * @property PromotionDiscountType $discount_type
 * @property float $discount_value
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'discount_type', 'discount_value', 'start_date', 'end_date', 'notes', 'created_by'])]
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_type' => PromotionDiscountType::class,
            'discount_value' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /**
     * @return BelongsToMany<Sku, $this>
     */
    public function skus(): BelongsToMany
    {
        return $this->belongsToMany(Sku::class, 'promotion_skus')->withTimestamps();
    }
}
