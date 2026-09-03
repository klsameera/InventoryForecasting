<?php

declare(strict_types=1);

namespace Domain\Facades\AttributeFacade;

use Domain\Services\AttributeService\AttributeService;
use Illuminate\Support\Facades\Facade;

/**
 * @see AttributeService
 */
final class AttributeFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AttributeService::class;
    }
}
