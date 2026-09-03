<?php

namespace App\Enums;

enum PromotionDiscountType: string
{
    case Percentage = 'PERCENTAGE';
    case Fixed = 'FIXED';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage',
            self::Fixed => 'Fixed amount',
        };
    }
}
