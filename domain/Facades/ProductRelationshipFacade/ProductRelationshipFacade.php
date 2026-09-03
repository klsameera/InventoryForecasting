<?php

declare(strict_types=1);

namespace Domain\Facades\ProductRelationshipFacade;

use Domain\Services\ProductRelationshipService\ProductRelationshipService;
use Illuminate\Support\Facades\Facade;

/**
 * @see ProductRelationshipService
 */
final class ProductRelationshipFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ProductRelationshipService::class;
    }
}
