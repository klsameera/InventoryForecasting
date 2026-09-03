<?php

declare(strict_types=1);

namespace Domain\Facades\DemandProfileFacade;

use Domain\Services\DemandProfileService\DemandProfileService;
use Illuminate\Support\Facades\Facade;

/**
 * @see DemandProfileService
 */
final class DemandProfileFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DemandProfileService::class;
    }
}
