<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Promotion\CreatePromotionRequest;
use App\Http\Requests\Promotion\UpdatePromotionRequest;
use App\Http\Resources\Promotion\PromotionResource;
use Domain\Facades\PromotionFacade\PromotionFacade;
use Domain\Facades\SkuFacade\SkuFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 10 (app_plan.md §86, "promotion impact") — the one full CRUD module
 * this phase adds. {@see impact()} is a fifth, read-only action beyond the
 * standard eight: a real before/during sales comparison, only meaningful
 * once the promotion's `end_date` has passed — see PromotionService's
 * docblock.
 */
final class PromotionController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'sku_id']);

        $promotions = PromotionFacade::all($filters)
            ->through(fn ($promotion) => (new PromotionResource($promotion))->resolve());

        return Inertia::render('Promotion/index', [
            'promotions' => $promotions,
            'filters' => (object) $filters,
            'skuOptions' => SkuFacade::options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Promotion/create', [
            'skuOptions' => SkuFacade::options(),
        ]);
    }

    public function edit(int $id): Response
    {
        $promotion = PromotionFacade::get($id);

        abort_if($promotion === null, 404);

        return Inertia::render('Promotion/edit', [
            'id' => $id,
            'promotion' => (new PromotionResource($promotion))->resolve(),
            'skuOptions' => SkuFacade::options(),
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $promotions = PromotionFacade::all($request->only(['search', 'sku_id', 'per_page']))
            ->through(fn ($promotion) => (new PromotionResource($promotion))->resolve());

        return response()->json($promotions);
    }

    public function get(int $id): JsonResponse
    {
        $promotion = PromotionFacade::get($id);

        return $promotion
            ? response()->json(['success' => true, 'data' => (new PromotionResource($promotion))->resolve()])
            : response()->json(['success' => false, 'message' => 'Promotion not found'], 404);
    }

    public function store(CreatePromotionRequest $request): RedirectResponse
    {
        $data = [...$request->validated(), 'created_by' => $request->user()?->id];

        $result = PromotionFacade::store($data);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('promotion.edit', $result['data']->id) : back()->withInput();
    }

    public function update(UpdatePromotionRequest $request, int $id): RedirectResponse
    {
        $result = PromotionFacade::update($request->validated(), $id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('promotion.edit', $id) : back()->withInput();
    }

    public function delete(int $id): RedirectResponse
    {
        $result = PromotionFacade::delete($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }

    public function impact(int $id): Response
    {
        $promotion = PromotionFacade::get($id);

        abort_if($promotion === null, 404);

        $result = PromotionFacade::impact($id);

        return Inertia::render('Promotion/impact', [
            'promotion' => (new PromotionResource($promotion))->resolve(),
            'impact' => $result['data'],
        ]);
    }
}
