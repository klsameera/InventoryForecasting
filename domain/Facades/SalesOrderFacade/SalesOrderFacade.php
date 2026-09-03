<?php

declare(strict_types=1);

namespace Domain\Facades\SalesOrderFacade;

use Domain\Services\SalesOrderService\SalesOrderService;
use Illuminate\Support\Facades\Facade;

/**
 * @see SalesOrderService
 */
final class SalesOrderFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SalesOrderService::class;
    }
}
