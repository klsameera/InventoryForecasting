<?php

declare(strict_types=1);

namespace Domain\Facades\ProductFacade;

use Domain\Services\ProductService\ProductService;
use Illuminate\Support\Facades\Facade;

/**
 * @see ProductService
 */
final class ProductFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ProductService::class;
    }
}
