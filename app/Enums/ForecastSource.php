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
}
