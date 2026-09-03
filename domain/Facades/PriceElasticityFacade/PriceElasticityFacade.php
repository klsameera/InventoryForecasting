<?php

declare(strict_types=1);

namespace Domain\Facades\PriceElasticityFacade;

use Domain\Services\PriceElasticityService\PriceElasticityService;
use Illuminate\Support\Facades\Facade;

/**
 * @see PriceElasticityService
 */
final class PriceElasticityFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PriceElasticityService::class;
    }
}
