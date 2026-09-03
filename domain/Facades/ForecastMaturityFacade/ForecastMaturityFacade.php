<?php

declare(strict_types=1);

namespace Domain\Facades\ForecastMaturityFacade;

use Domain\Services\ForecastMaturityService\ForecastMaturityService;
use Illuminate\Support\Facades\Facade;

/**
 * @see ForecastMaturityService
 */
final class ForecastMaturityFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ForecastMaturityService::class;
    }
}
