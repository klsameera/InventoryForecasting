<?php

declare(strict_types=1);

namespace Domain\Facades\ModelSelectionFacade;

use Domain\Services\ModelSelectionService\ModelSelectionService;
use Illuminate\Support\Facades\Facade;

/**
 * @see ModelSelectionService
 */
final class ModelSelectionFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ModelSelectionService::class;
    }
}
