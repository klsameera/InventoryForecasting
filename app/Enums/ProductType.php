<?php

namespace App\Enums;

enum ProductType: string
{
    case Simple = 'simple';
    case Configurable = 'configurable';

    public function label(): string
    {
        return match ($this) {
            self::Simple => 'Simple',
            self::Configurable => 'Configurable',
        };
    }
}
