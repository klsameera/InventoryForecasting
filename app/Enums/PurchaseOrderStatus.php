<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Ordered = 'ordered';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Ordered => 'Ordered',
            self::PartiallyReceived => 'Partially received',
            self::Received => 'Received',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Statuses a purchase order can still receive goods against.
     *
     * @return list<self>
     */
    public static function receivableCases(): array
    {
        return [self::Ordered, self::PartiallyReceived];
    }
}
