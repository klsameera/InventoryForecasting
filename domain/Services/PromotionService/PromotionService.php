<?php

declare(strict_types=1);

namespace Domain\Services\PromotionService;

use App\Models\Promotion;
use Domain\Services\ForecastService\ForecastService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 10 (app_plan.md §86, "promotion impact") — the only new full CRUD
 * module this phase adds; every other Phase 10 concept extends an existing
 * engine or is a read-only computed report. `sku_ids` is accepted alongside
 * the promotion's own fields and synced to `promotion_skus` in the same
 * transaction, the same "nested child rows" pattern
 * app_architecture.md §1a documents for `attribute_values` — except this
 * pivot has no extra columns, so a plain `belongsToMany`/`sync()` is enough,
 * no typed pivot class needed.
 *
 * {@see impact()} is this module's one non-baseline method: a real
 * before/during sales comparison per promoted SKU, computed only once the
 * promotion's own `end_date` has actually passed — the same "not due yet"
 * honesty {@see ForecastService::scoreAccuracy()}
 * already has for forecast accuracy.
 */
final class PromotionService
{
    public function __construct(private Promotion $model) {}

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
            ->withCount('skus')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where('name', 'like', "%{$search}%"))
            ->when($filters['sku_id'] ?? null, fn ($query, $skuId) => $query
                ->whereHas('skus', fn ($skuQuery) => $skuQuery->where('skus.id', $skuId)))
            ->latest('start_date')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?Promotion
    {
        return $this->model->with('skus.product')->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function store(array $data): array
    {
        DB::beginTransaction();

        try {
            $skuIds = $data['sku_ids'] ?? [];
            unset($data['sku_ids']);

            $promotion = $this->model->create($data);
            $promotion->skus()->sync($skuIds);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Promotion created successfully',
                'data' => $promotion->fresh('skus'),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed creating promotion', [
                'exception' => $exception->getMessage(),
                'data' => $data,
            ]);

            return ['success' => false, 'message' => 'Error creating promotion'];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(array $data, int $id): array
    {
        DB::beginTransaction();

        try {
            $promotion = $this->model->findOrFail($id);

            $skuIds = $data['sku_ids'] ?? null;
            unset($data['sku_ids']);

            $promotion->update($data);

            if ($skuIds !== null) {
                $promotion->skus()->sync($skuIds);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Promotion updated successfully',
                'data' => $promotion->fresh('skus'),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed updating promotion', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error updating promotion'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        DB::beginTransaction();

        try {
            $this->model->findOrFail($id)->delete();

            DB::commit();

            return ['success' => true, 'message' => 'Promotion deleted successfully'];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed deleting promotion', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error deleting promotion'];
        }
    }

    /**
     * Compares each promoted SKU's real average daily demand during the
     * promotion against an equal-length baseline window immediately
     * *before* `start_date`, both read from Phase 4's
     * `inventory_daily_snapshots.sold_qty`. Returns `elapsed: false` when
     * `end_date` hasn't passed yet — there is no real "during" data to
     * measure, so nothing is estimated ahead of time.
     *
     * @return array<string, mixed>
     */
    public function impact(int $id): array
    {
        $promotion = $this->model->with('skus.product')->findOrFail($id);

        if ($promotion->end_date->isFuture()) {
            return [
                'success' => true,
                'message' => 'This promotion has not ended yet',
                'data' => ['elapsed' => false, 'skus' => []],
            ];
        }

        $windowDays = (int) abs($promotion->start_date->diffInDays($promotion->end_date)) + 1;
        $baselineEnd = $promotion->start_date->copy()->subDay();
        $baselineStart = $baselineEnd->copy()->subDays($windowDays - 1);

        $results = $promotion->skus->map(function ($sku) use ($promotion, $windowDays, $baselineStart, $baselineEnd) {
            $duringQty = (float) DB::table('inventory_daily_snapshots')
                ->where('sku_id', $sku->id)
                ->whereDate('snapshot_date', '>=', $promotion->start_date->toDateString())
                ->whereDate('snapshot_date', '<=', $promotion->end_date->toDateString())
                ->sum('sold_qty');

            $baselineQty = (float) DB::table('inventory_daily_snapshots')
                ->where('sku_id', $sku->id)
                ->whereDate('snapshot_date', '>=', $baselineStart->toDateString())
                ->whereDate('snapshot_date', '<=', $baselineEnd->toDateString())
                ->sum('sold_qty');

            $duringDailyRate = round($duringQty / $windowDays, 2);
            $baselineDailyRate = round($baselineQty / $windowDays, 2);

            $percentChange = $baselineDailyRate > 0.0
                ? round((($duringDailyRate - $baselineDailyRate) / $baselineDailyRate) * 100, 2)
                : null;

            return [
                'sku_id' => $sku->id,
                'sku' => $sku->sku,
                'product_name' => $sku->product?->name,
                'during_qty' => $duringQty,
                'baseline_qty' => $baselineQty,
                'during_daily_rate' => $duringDailyRate,
                'baseline_daily_rate' => $baselineDailyRate,
                'percent_change' => $percentChange,
            ];
        })->all();

        return [
            'success' => true,
            'message' => 'Impact calculated',
            'data' => ['elapsed' => true, 'skus' => $results],
        ];
    }
}
