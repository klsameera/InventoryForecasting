<?php

declare(strict_types=1);

namespace Domain\Facades\SystemHealthFacade;

use Domain\Services\SystemHealthService\SystemHealthService;
use Illuminate\Support\Facades\Facade;

/**
 * @see SystemHealthService
 */
final class SystemHealthFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SystemHealthService::class;
    }
}
