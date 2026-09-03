<?php

namespace App\Enums;

/**
 * app_plan.md §50's "months of stock" overstock prediction, reduced to
 * three risk tiers — the same shape as {@see StockoutRisk}, kept as its own
 * enum rather than reused because the two describe different columns
 * (`stockout_risk` vs `overstock_risk`) with independent meanings.
 */
enum OverstockRisk: string
{
    case None = 'NONE';
    case Moderate = 'MODERATE';
    case Critical = 'CRITICAL';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Moderate => 'Moderate',
            self::Critical => 'Critical',
        };
    }
}
