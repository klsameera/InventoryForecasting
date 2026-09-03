<?php

declare(strict_types=1);

namespace Domain\Facades\ProductVariantFacade;

use Domain\Services\ProductVariantService\ProductVariantService;
use Illuminate\Support\Facades\Facade;

/**
 * @see ProductVariantService
 */
final class ProductVariantFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ProductVariantService::class;
    }
}
