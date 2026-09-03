<?php

declare(strict_types=1);

namespace Domain\Facades\BrandFacade;

use Domain\Services\BrandService\BrandService;
use Illuminate\Support\Facades\Facade;

/**
 * @see BrandService
 */
final class BrandFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BrandService::class;
    }
}
