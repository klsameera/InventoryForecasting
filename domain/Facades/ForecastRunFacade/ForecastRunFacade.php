<?php

declare(strict_types=1);

namespace Domain\Facades\ForecastRunFacade;

use Domain\Services\ForecastRunService\ForecastRunService;
use Illuminate\Support\Facades\Facade;

/**
 * @see ForecastRunService
 */
final class ForecastRunFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ForecastRunService::class;
    }
}
