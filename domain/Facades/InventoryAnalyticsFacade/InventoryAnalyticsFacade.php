<?php

declare(strict_types=1);

namespace Domain\Facades\InventoryAnalyticsFacade;

use Domain\Services\InventoryAnalyticsService\InventoryAnalyticsService;
use Illuminate\Support\Facades\Facade;

/**
 * @see InventoryAnalyticsService
 */
final class InventoryAnalyticsFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return InventoryAnalyticsService::class;
    }
}
