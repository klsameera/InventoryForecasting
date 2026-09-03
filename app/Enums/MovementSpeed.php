<?php

namespace App\Enums;

enum MovementSpeed: string
{
    case Fast = 'fast';
    case Slow = 'slow';
    case Dead = 'dead';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Fast => 'Fast mover',
            self::Slow => 'Slow mover',
            self::Dead => 'Dead stock',
            self::NotApplicable => 'No stock',
        };
    }
}
