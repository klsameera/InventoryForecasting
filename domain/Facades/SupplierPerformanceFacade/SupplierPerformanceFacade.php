<?php

declare(strict_types=1);

namespace Domain\Facades\SupplierPerformanceFacade;

use Domain\Services\SupplierPerformanceService\SupplierPerformanceService;
use Illuminate\Support\Facades\Facade;

/**
 * @see SupplierPerformanceService
 */
final class SupplierPerformanceFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SupplierPerformanceService::class;
    }
}
