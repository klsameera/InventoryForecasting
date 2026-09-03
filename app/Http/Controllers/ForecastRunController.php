<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ForecastRun\CreateForecastRunRequest;
use App\Http\Resources\ForecastRun\ForecastRunResource;
use Domain\Facades\ForecastRunFacade\ForecastRunFacade;
use Domain\Facades\WarehouseFacade\WarehouseFacade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Triggers and tracks forecast batches. No edit/update/delete — a run is a
 * point-in-time record of what was asked for and what happened, never
 * corrected in place. See ForecastRunService's docblock.
 */
final class ForecastRunController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['status']);

        $runs = ForecastRunFacade::all($filters)
            ->through(fn ($run) => (new ForecastRunResource($run))->resolve());

        return Inertia::render('ForecastRun/index', [
            'runs' => $runs,
            'filters' => (object) $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ForecastRun/create', [
            'warehouseOptions' => WarehouseFacade::options(),
        ]);
    }

    public function store(CreateForecastRunRequest $request): RedirectResponse
    {
        $data = [...$request->validated(), 'created_by' => $request->user()?->id];

        $result = ForecastRunFacade::store($data);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('forecast-run.index') : back()->withInput();
    }
}
