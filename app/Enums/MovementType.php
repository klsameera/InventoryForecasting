<?php

namespace App\Enums;

enum MovementType: string
{
    case PurchaseReceipt = 'PURCHASE_RECEIPT';
    case Sale = 'SALE';
    case SaleReturn = 'SALE_RETURN';
    case PurchaseReturn = 'PURCHASE_RETURN';
    case TransferIn = 'TRANSFER_IN';
    case TransferOut = 'TRANSFER_OUT';
    case AdjustmentIn = 'ADJUSTMENT_IN';
    case AdjustmentOut = 'ADJUSTMENT_OUT';
    case Damage = 'DAMAGE';
    case WriteOff = 'WRITE_OFF';
    case OpeningStock = 'OPENING_STOCK';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseReceipt => 'Purchase receipt',
            self::Sale => 'Sale',
            self::SaleReturn => 'Sale return',
            self::PurchaseReturn => 'Purchase return',
            self::TransferIn => 'Transfer in',
            self::TransferOut => 'Transfer out',
            self::AdjustmentIn => 'Adjustment in',
            self::AdjustmentOut => 'Adjustment out',
            self::Damage => 'Damage',
            self::WriteOff => 'Write-off',
            self::OpeningStock => 'Opening stock',
        };
    }

    /**
     * Whether this movement type increases (true) or decreases (false)
     * on-hand quantity.
     */
    public function isInbound(): bool
    {
        return match ($this) {
            self::PurchaseReceipt, self::SaleReturn, self::TransferIn,
            self::AdjustmentIn, self::OpeningStock => true,
            self::Sale, self::PurchaseReturn, self::TransferOut,
            self::Damage, self::WriteOff, self::AdjustmentOut => false,
        };
    }
}
