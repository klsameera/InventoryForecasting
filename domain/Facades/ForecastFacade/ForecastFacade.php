<?php

declare(strict_types=1);

namespace Domain\Facades\ForecastFacade;

use Domain\Services\ForecastService\ForecastService;
use Illuminate\Support\Facades\Facade;

/**
 * @see ForecastService
 */
final class ForecastFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ForecastService::class;
    }
}
