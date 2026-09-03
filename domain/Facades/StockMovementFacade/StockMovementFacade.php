<?php

declare(strict_types=1);

namespace Domain\Facades\StockMovementFacade;

use Domain\Services\StockMovementService\StockMovementService;
use Illuminate\Support\Facades\Facade;

/**
 * @see StockMovementService
 */
final class StockMovementFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StockMovementService::class;
    }
}
