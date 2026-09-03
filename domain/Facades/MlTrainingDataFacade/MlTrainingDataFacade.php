<?php

declare(strict_types=1);

namespace Domain\Facades\MlTrainingDataFacade;

use Domain\Services\MlTrainingDataService\MlTrainingDataService;
use Illuminate\Support\Facades\Facade;

/**
 * @see MlTrainingDataService
 */
final class MlTrainingDataFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MlTrainingDataService::class;
    }
}
