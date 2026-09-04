<?php

declare(strict_types=1);

namespace Domain\Facades\DashboardFacade;

use Domain\Services\DashboardService\DashboardService;
use Illuminate\Support\Facades\Facade;

/**
 * @see DashboardService
 */
final class DashboardFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DashboardService::class;
    }
}
