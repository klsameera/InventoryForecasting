<?php

declare(strict_types=1);

namespace Domain\Facades\StockTransferFacade;

use Domain\Services\StockTransferService\StockTransferService;
use Illuminate\Support\Facades\Facade;

/**
 * @see StockTransferService
 */
final class StockTransferFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StockTransferService::class;
    }
}
