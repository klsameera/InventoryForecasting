<?php

declare(strict_types=1);

namespace Domain\Facades\InventoryDailySnapshotFacade;

use Domain\Services\InventoryDailySnapshotService\InventoryDailySnapshotService;
use Illuminate\Support\Facades\Facade;

/**
 * @see InventoryDailySnapshotService
 */
final class InventoryDailySnapshotFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return InventoryDailySnapshotService::class;
    }
}
