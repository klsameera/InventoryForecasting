<?php

declare(strict_types=1);

namespace Domain\Facades\DemandInsightsFacade;

use Domain\Services\DemandInsightsService\DemandInsightsService;
use Illuminate\Support\Facades\Facade;

/**
 * @see DemandInsightsService
 */
final class DemandInsightsFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DemandInsightsService::class;
    }
}
