<?php

namespace App\Enums;

enum AbcClass: string
{
    case A = 'a';
    case B = 'b';
    case C = 'c';
    case Unclassified = 'unclassified';

    public function label(): string
    {
        return match ($this) {
            self::A => 'A — top revenue',
            self::B => 'B — mid revenue',
            self::C => 'C — long tail',
            self::Unclassified => 'Unclassified',
        };
    }
}
