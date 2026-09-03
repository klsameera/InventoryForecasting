<?php

declare(strict_types=1);

namespace Domain\Facades\SkuFacade;

use Domain\Services\SkuService\SkuService;
use Illuminate\Support\Facades\Facade;

/**
 * @see SkuService
 */
final class SkuFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SkuService::class;
    }
}
