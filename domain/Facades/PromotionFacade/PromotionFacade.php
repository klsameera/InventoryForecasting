<?php

declare(strict_types=1);

namespace Domain\Facades\PromotionFacade;

use Domain\Services\PromotionService\PromotionService;
use Illuminate\Support\Facades\Facade;

/**
 * @see PromotionService
 */
final class PromotionFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PromotionService::class;
    }
}
