<?php

declare(strict_types=1);

namespace Domain\Facades\CategoryFacade;

use Domain\Services\CategoryService\CategoryService;
use Illuminate\Support\Facades\Facade;

/**
 * @see CategoryService
 */
final class CategoryFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CategoryService::class;
    }
}
