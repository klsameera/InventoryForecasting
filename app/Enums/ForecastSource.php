<?php

namespace App\Enums;

/**
 * app_plan.md §38 lists all six; this scaffold's baseline algorithm only
 * ever produces SkuHistory (see MlServiceClient's docblock) — the rest are
 * reserved for when category/category-size/cold-start strategies exist.
 */
enum ForecastSource: string
{
    case SkuHistory = 'SKU_HISTORY';
    case CategorySize = 'CATEGORY_SIZE';
    case Category = 'CATEGORY';
    case BrandCategory = 'BRAND_CATEGORY';
    case Hybrid = 'HYBRID';
    case ColdStart = 'COLD_START';

    public function label(): string
    {
        return match ($this) {
            self::SkuHistory => 'SKU history',
            self::CategorySize => 'Category + size',
            self::Category => 'Category',
            self::BrandCategory => 'Brand + category',
            self::Hybrid => 'Hybrid',
            self::ColdStart => 'Cold start',
        };
    }

    /**
     * The same thing said to someone who has never seen this system.
     *
     * {@see label()} is a name for people who already know the pipeline;
     * this is the sentence that goes next to it on a page anyone might read.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::SkuHistory => "Based on this product's own sales history.",
            self::CategorySize => 'Too new to judge alone, so similar sizes in the same category were used.',
            self::Category => 'Too new to judge alone, so other products in the same category were used.',
            self::BrandCategory => 'Too new to judge alone, so similar products from the same brand were used.',
            self::Hybrid => 'Part its own short history, part how similar products sell.',
            self::ColdStart => 'Brand new — there is no sales history yet, so this is a cautious estimate.',
        };
    }
}
