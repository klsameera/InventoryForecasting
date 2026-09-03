<?php

declare(strict_types=1);

namespace Domain\Services\ForecastService;

use App\Models\Forecast;
use Domain\Services\ForecastRunService\ForecastRunService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-only — forecasts are only ever written by
 * {@see ForecastRunService::processRun()}.
 * {@see scoreAccuracy()} is this module's one write path: once a forecast's
 * window has fully elapsed, it looks up what actually sold (from Phase 4's
 * `inventory_daily_snapshots`) and records the error (app_plan.md §39).
 */
final class ForecastService
{
    public function __construct(private Forecast $model) {}

    public function count(): int
    {
        return $this->model->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function all(array $filters = []): LengthAwarePaginator
    {
        return $this->model
            ->newQuery()
            ->with(['warehouse:id,name', 'sku:id,sku,product_id', 'sku.product:id,name', 'accuracy', 'modelVersion:id,name'])
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query
                ->where('warehouse_id', $warehouseId))
            ->when($filters['sku_id'] ?? null, fn ($query, $skuId) => $query
                ->where('sku_id', $skuId))
            ->when($filters['forecast_run_id'] ?? null, fn ($query, $runId) => $query
                ->where('forecast_run_id', $runId))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->whereHas('sku', fn ($q) => $q->where('sku', 'like', "%{$search}%")))
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    /**
     * Score every forecast whose window has fully elapsed and hasn't been
     * scored yet: sum the actual `sold_qty` over the same
     * (forecast_date − horizon_days) → forecast_date window from
     * `inventory_daily_snapshots`, and record the error.
     *
     * @return array<string, mixed>
     */
    public function scoreAccuracy(): array
    {
        try {
            $due = $this->model->newQuery()
                ->whereDoesntHave('accuracy')
                ->whereDate('forecast_date', '<=', now()->toDateString())
                ->get();

            $scored = 0;

            foreach ($due as $forecast) {
                $windowStart = $forecast->forecast_date->copy()->subDays($forecast->horizon_days);

                $actualQty = (float) DB::table('inventory_daily_snapshots')
                    ->where('warehouse_id', $forecast->warehouse_id)
                    ->where('sku_id', $forecast->sku_id)
                    ->whereDate('snapshot_date', '>=', $windowStart->toDateString())
                    ->whereDate('snapshot_date', '<=', $forecast->forecast_date->toDateString())
                    ->sum('sold_qty');

                $predicted = (float) $forecast->predicted_qty;
                $absoluteError = abs($predicted - $actualQty);

                $forecast->accuracy()->create([
                    'actual_qty' => $actualQty,
                    'absolute_error' => $absoluteError,
                    'percentage_error' => $actualQty > 0 ? round($absoluteError / $actualQty * 100, 2) : null,
                    'calculated_at' => now(),
                ]);

                $scored++;
            }

            return ['success' => true, 'message' => "Scored {$scored} forecast(s)", 'data' => ['scored' => $scored]];
        } catch (Throwable $exception) {
            Log::error('Failed scoring forecast accuracy', ['exception' => $exception->getMessage()]);

            return ['success' => false, 'message' => 'Error scoring forecast accuracy'];
        }
    }
}
