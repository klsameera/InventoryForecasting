<?php

namespace App\Enums;

/**
 * app_plan.md §42's stock-out prediction, reduced to three risk tiers.
 * `Critical` matches §42's own worked example exactly: expected days until
 * stockout is less than or equal to the supplier's lead time, meaning an
 * order placed today still won't arrive before stock runs out.
 */
enum StockoutRisk: string
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
