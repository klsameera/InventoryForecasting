<?php

declare(strict_types=1);

namespace Domain\Facades\GoodsReceiptFacade;

use Domain\Services\GoodsReceiptService\GoodsReceiptService;
use Illuminate\Support\Facades\Facade;

/**
 * @see GoodsReceiptService
 */
final class GoodsReceiptFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GoodsReceiptService::class;
    }
}
