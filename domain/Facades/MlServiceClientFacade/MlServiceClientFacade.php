<?php

declare(strict_types=1);

namespace Domain\Facades\MlServiceClientFacade;

use Domain\Services\MlServiceClient\MlServiceClient;
use Illuminate\Support\Facades\Facade;

/**
 * @see MlServiceClient
 */
final class MlServiceClientFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MlServiceClient::class;
    }
}
