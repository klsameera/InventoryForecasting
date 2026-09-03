<?php

declare(strict_types=1);

namespace Domain\Facades\WarehouseFacade;

use Domain\Services\WarehouseService\WarehouseService;
use Illuminate\Support\Facades\Facade;

/**
 * @see WarehouseService
 */
final class WarehouseFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WarehouseService::class;
    }
}
