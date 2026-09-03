<?php

namespace App\Enums;

enum ReturnCondition: string
{
    case Sellable = 'sellable';
    case Damaged = 'damaged';

    public function label(): string
    {
        return match ($this) {
            self::Sellable => 'Sellable',
            self::Damaged => 'Damaged',
        };
    }
}
