<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\Brand\BrandResource;
use Domain\Facades\BrandFacade\BrandFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BrandController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'sort', 'direction']);

        $brands = BrandFacade::all($filters)
            ->through(fn ($brand) => (new BrandResource($brand))->resolve());

        return Inertia::render('Brand/index', [
            'brands' => $brands,
            'filters' => (object) $filters,
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $brands = BrandFacade::all($request->only(['search', 'status', 'sort', 'direction', 'per_page']))
            ->through(fn ($brand) => (new BrandResource($brand))->resolve());

        return response()->json($brands);
    }

    public function get(int $id): JsonResponse
    {
        $brand = BrandFacade::get($id);

        return $brand
            ? response()->json(['success' => true, 'data' => (new BrandResource($brand))->resolve()])
            : response()->json(['success' => false, 'message' => 'Brand not found'], 404);
    }
}
