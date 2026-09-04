<?php

declare(strict_types=1);

namespace Domain\Services\ForecastMaturityService;

use App\Enums\ForecastMaturity;
use App\Enums\MovementType;
use Domain\Services\InventoryAnalyticsService\InventoryAnalyticsService;
use Domain\Services\MlTrainingDataService\MlTrainingDataService;
use Illuminate\Support\Facades\DB;

/**
 * Classifies a SKU's forecast maturity (app_plan.md §28) from its actual
 * sales history — never persisted, always recomputed from current data, the
 * same "computed, no model" shape as {@see InventoryAnalyticsService}.
 *
 * Classification is deliberately simple and documented as such (same
 * category as app_architecture.md §1g's basic reorder point): a real
 * maturity model would weigh many more signals. This is the "basic version"
 * app_plan.md §82 asks Phase 6 to build first.
 */
final class ForecastMaturityService
{
    /** Below this many days since first sale, a SKU is still "early." */
    private const EARLY_THRESHOLD_DAYS = 30;

    /** At or above this many days since first sale, a SKU is "mature." */
    private const MATURE_THRESHOLD_DAYS = 365;

    /** Width of the trailing/prior windows compared for the decline check. */
    private const TREND_WINDOW_DAYS = 30;

    /** A >=50% drop between the prior and trailing window counts as declining. */
    private const DECLINE_RATIO_THRESHOLD = 0.5;

    /**
     * @return array{maturity: ForecastMaturity, days_of_history: int|null, first_sale_at: string|null}
     */
    public function classify(int $skuId): array
    {
        $endOfLifeDate = DB::table('skus')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->where('skus.id', $skuId)
            ->value('products.end_of_life_date');

        if ($endOfLifeDate !== null && now()->toDateString() > $endOfLifeDate) {
            return ['maturity' => ForecastMaturity::EndOfLife, 'days_of_history' => null, 'first_sale_at' => null];
        }

        $firstSaleAt = $this->firstSaleAt($skuId);

        if ($firstSaleAt === null) {
            return ['maturity' => ForecastMaturity::ColdStart, 'days_of_history' => null, 'first_sale_at' => null];
        }

        $daysOfHistory = (int) abs(now()->diffInDays($firstSaleAt));

        if ($daysOfHistory < self::EARLY_THRESHOLD_DAYS) {
            return ['maturity' => ForecastMaturity::Early, 'days_of_history' => $daysOfHistory, 'first_sale_at' => $firstSaleAt];
        }

        if ($this->isDeclining($skuId)) {
            return ['maturity' => ForecastMaturity::Declining, 'days_of_history' => $daysOfHistory, 'first_sale_at' => $firstSaleAt];
        }

        $maturity = $daysOfHistory >= self::MATURE_THRESHOLD_DAYS
            ? ForecastMaturity::Mature
            : ForecastMaturity::Established;

        return ['maturity' => $maturity, 'days_of_history' => $daysOfHistory, 'first_sale_at' => $firstSaleAt];
    }

    /**
     * The earliest day this SKU sold anything, read from whichever source
     * demand currently comes from.
     *
     * This has to follow the demand source or the whole classification
     * collapses: on the BuyAbans source there are no local `stock_movements`
     * at all, so every SKU would look like it had never sold — and every pair
     * would be forecast as a cold start regardless of how many years of synced
     * history it actually has.
     */
    private function firstSaleAt(int $skuId): ?string
    {
        if (MlTrainingDataService::demandSource() === MlTrainingDataService::SOURCE_BUYABANS) {
            return DB::table('buyabans_daily_demands')
                ->where('grain', config('services.buyabans.grain', 'warehouse'))
                ->where('sku_id', $skuId)
                ->where('sold_qty', '>', 0)
                ->min('demand_date');
        }

        return DB::table('stock_movements')
            ->where('sku_id', $skuId)
            ->where('movement_type', MovementType::Sale->value)
            ->min('occurred_at');
    }

    /**
     * Units sold for a SKU between two dates, from the configured source.
     */
    private function soldBetween(int $skuId, string $from, ?string $until = null): float
    {
        if (MlTrainingDataService::demandSource() === MlTrainingDataService::SOURCE_BUYABANS) {
            return (float) DB::table('buyabans_daily_demands')
                ->where('grain', config('services.buyabans.grain', 'warehouse'))
                ->where('sku_id', $skuId)
                ->whereDate('demand_date', '>=', $from)
                ->when($until !== null, fn ($query) => $query->whereDate('demand_date', '<', $until))
                ->sum('sold_qty');
        }

        return (float) DB::table('inventory_daily_snapshots')
            ->where('sku_id', $skuId)
            ->whereDate('snapshot_date', '>=', $from)
            ->when($until !== null, fn ($query) => $query->whereDate('snapshot_date', '<', $until))
            ->sum('sold_qty');
    }

    /**
     * Trailing {@see TREND_WINDOW_DAYS} vs. the window immediately before it,
     * summed across every warehouse — a meaningful (not marginal) drop is
     * what earns the Declining label, not any decrease at all.
     */
    private function isDeclining(int $skuId): bool
    {
        $trailingStart = now()->subDays(self::TREND_WINDOW_DAYS)->toDateString();
        $priorStart = now()->subDays(self::TREND_WINDOW_DAYS * 2)->toDateString();

        $trailingQty = $this->soldBetween($skuId, $trailingStart);
        $priorQty = $this->soldBetween($skuId, $priorStart, $trailingStart);

        if ($priorQty <= 0.0) {
            return false;
        }

        return ($trailingQty / $priorQty) < self::DECLINE_RATIO_THRESHOLD;
    }
}
