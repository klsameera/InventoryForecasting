<?php

declare(strict_types=1);

namespace Domain\Facades\InventoryBatchFacade;

use Domain\Services\InventoryBatchService\InventoryBatchService;
use Illuminate\Support\Facades\Facade;

/**
 * @see InventoryBatchService
 */
final class InventoryBatchFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return InventoryBatchService::class;
    }
}
