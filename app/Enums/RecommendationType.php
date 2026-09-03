<?php

namespace App\Enums;

/**
 * app_plan.md §51's nine action types. Phase 7 (inventory optimization,
 * app_plan.md §83) produced only {@see self::Purchase}; Phase 8 (ageing
 * prevention, app_plan.md §84) adds {@see self::ReducePurchase},
 * {@see self::DoNotReorder} and {@see self::Clearance}.
 * {@see self::TransferStock}, {@see self::Promote}, {@see self::Discount},
 * {@see self::ReturnToSupplier} and {@see self::ReviewProduct} remain
 * reserved for Phase 9/10 — the same reserved-value pattern
 * {@see ForecastSource} used in Phase 5/6.
 */
enum RecommendationType: string
{
    case Purchase = 'PURCHASE';
    case ReducePurchase = 'REDUCE_PURCHASE';
    case DoNotReorder = 'DO_NOT_REORDER';
    case TransferStock = 'TRANSFER_STOCK';
    case Promote = 'PROMOTE';
    case Discount = 'DISCOUNT';
    case Clearance = 'CLEARANCE';
    case ReturnToSupplier = 'RETURN_TO_SUPPLIER';
    case ReviewProduct = 'REVIEW_PRODUCT';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Purchase',
            self::ReducePurchase => 'Reduce purchase',
            self::DoNotReorder => 'Do not reorder',
            self::TransferStock => 'Transfer stock',
            self::Promote => 'Promote',
            self::Discount => 'Discount',
            self::Clearance => 'Clearance',
            self::ReturnToSupplier => 'Return to supplier',
            self::ReviewProduct => 'Review product',
        };
    }
}
