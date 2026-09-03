<?php

declare(strict_types=1);

namespace Domain\Facades\InventoryRecommendationFacade;

use Domain\Services\InventoryRecommendationService\InventoryRecommendationService;
use Illuminate\Support\Facades\Facade;

/**
 * @see InventoryRecommendationService
 */
final class InventoryRecommendationFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return InventoryRecommendationService::class;
    }
}
