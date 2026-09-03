<?php

declare(strict_types=1);

namespace Domain\Facades\PurchaseOrderFacade;

use Domain\Services\PurchaseOrderService\PurchaseOrderService;
use Illuminate\Support\Facades\Facade;

/**
 * @see PurchaseOrderService
 */
final class PurchaseOrderFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PurchaseOrderService::class;
    }
}
