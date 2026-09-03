<?php

declare(strict_types=1);

namespace Domain\Facades\SalesReturnFacade;

use Domain\Services\SalesReturnService\SalesReturnService;
use Illuminate\Support\Facades\Facade;

/**
 * @see SalesReturnService
 */
final class SalesReturnFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SalesReturnService::class;
    }
}
