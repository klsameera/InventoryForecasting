<?php

declare(strict_types=1);

namespace Domain\Facades\InventoryFacade;

use Domain\Services\InventoryService\InventoryService;
use Illuminate\Support\Facades\Facade;

/**
 * @see InventoryService
 */
final class InventoryFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return InventoryService::class;
    }
}
