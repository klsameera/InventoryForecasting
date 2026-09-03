<?php

declare(strict_types=1);

namespace Domain\Facades\SupplierFacade;

use Domain\Services\SupplierService\SupplierService;
use Illuminate\Support\Facades\Facade;

/**
 * @see SupplierService
 */
final class SupplierFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SupplierService::class;
    }
}
