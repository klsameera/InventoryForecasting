<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AbcClass;
use App\Enums\MovementSpeed;
use Domain\Facades\CategoryFacade\CategoryFacade;
use Domain\Facades\InventoryAnalyticsFacade\InventoryAnalyticsFacade;
use Domain\Facades\WarehouseFacade\WarehouseFacade;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only computed report — index only, no create/store/update/delete, no
 * migration, no model. See InventoryAnalyticsService's docblock.
 */
final class InventoryAnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only([
            'search', 'warehouse_id', 'category_id', 'movement_speed', 'abc_class',
            'needs_reorder', 'lookback_days', 'safety_days', 'sort', 'direction', 'page',
        ]);

        $rows = InventoryAnalyticsFacade::report($filters);

        return Inertia::render('InventoryAnalytics/index', [
            'rows' => $rows,
            'filters' => (object) $filters,
            'warehouseOptions' => WarehouseFacade::options(),
            'categoryOptions' => CategoryFacade::options(),
            'movementSpeeds' => collect(MovementSpeed::cases())->map(fn ($speed) => ['value' => $speed->value, 'label' => $speed->label()]),
            'abcClasses' => collect(AbcClass::cases())->map(fn ($class) => ['value' => $class->value, 'label' => $class->label()]),
        ]);
    }
}
