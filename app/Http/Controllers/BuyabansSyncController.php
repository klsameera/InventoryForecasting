<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\BuyabansSync\RunBuyabansSyncRequest;
use App\Http\Resources\BuyabansSync\BuyabansSyncRunResource;
use Domain\Facades\BuyabansSyncFacade\BuyabansSyncFacade;
use Domain\Services\BuyabansSyncService\BuyabansSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The integration's control panel: what has been pulled from the BuyAbans back
 * office, when, and whether it worked.
 *
 * Read-only plus two triggers — a connection probe and a manual sync — because
 * rows here are only ever written by
 * {@see BuyabansSyncService}, either from
 * this page or from the nightly schedule.
 */
final class BuyabansSyncController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'stage', 'status']);

        $runs = BuyabansSyncFacade::runs($filters)
            ->through(fn ($run) => (new BuyabansSyncRunResource($run))->resolve());

        return Inertia::render('BuyabansSync/index', [
            'runs' => $runs,
            'filters' => (object) $filters,
            'summary' => BuyabansSyncFacade::demandSummary(),
        ]);
    }

    /**
     * Asks the back office what it holds, without pulling anything. Useful
     * before committing to a full history sync — and the fastest way to tell a
     * credentials problem from an empty dataset.
     */
    public function probe(): RedirectResponse
    {
        $result = BuyabansSyncFacade::probe();

        $message = $result['success']
            ? 'Connected. '.$this->describe($result['data'] ?? [])
            : $result['message'];

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $message]);

        return back();
    }

    /**
     * Runs a sync stage now. Synchronous on purpose for a narrow window: an
     * operator triggering this wants to see whether it worked. A full-history
     * pull should go through the console command instead, which is why the
     * request caps `days`.
     */
    public function sync(RunBuyabansSyncRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $stage = $data['stage'] ?? 'all';

        $method = $stage === 'all' ? 'syncAll' : 'sync'.Str::studly($stage);

        $result = BuyabansSyncFacade::{$method}(array_filter([
            'days' => $data['days'] ?? null,
            'grain' => $data['grain'] ?? null,
        ], fn ($value) => $value !== null));

        Inertia::flash('toast', [
            'type' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'],
        ]);

        return back();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function describe(array $meta): string
    {
        $counts = $meta['counts'] ?? [];

        return sprintf(
            '%s products, %s orders, %s of them usable demand (%s to %s).',
            number_format((float) ($counts['sellable_products'] ?? 0)),
            number_format((float) ($counts['orders'] ?? 0)),
            number_format((float) ($counts['demand_orders'] ?? 0)),
            $meta['demand']['first_order_at'] ?? '—',
            $meta['demand']['last_order_at'] ?? '—',
        );
    }
}
