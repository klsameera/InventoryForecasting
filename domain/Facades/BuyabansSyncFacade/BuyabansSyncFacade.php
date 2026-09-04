<?php

declare(strict_types=1);

namespace Domain\Facades\BuyabansSyncFacade;

use Domain\Services\BuyabansSyncService\BuyabansSyncService;
use Illuminate\Support\Facades\Facade;

/**
 * @see BuyabansSyncService
 */
final class BuyabansSyncFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BuyabansSyncService::class;
    }
}
