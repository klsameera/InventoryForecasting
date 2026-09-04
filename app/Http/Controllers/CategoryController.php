<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\Category\CategoryResource;
use Domain\Facades\CategoryFacade\CategoryFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'sort', 'direction']);

        $categories = CategoryFacade::all($filters)
            ->through(fn ($category) => (new CategoryResource($category))->resolve());

        return Inertia::render('Category/index', [
            'categories' => $categories,
            'filters' => (object) $filters,
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $categories = CategoryFacade::all($request->only(['search', 'status', 'sort', 'direction', 'per_page']))
            ->through(fn ($category) => (new CategoryResource($category))->resolve());

        return response()->json($categories);
    }

    public function get(int $id): JsonResponse
    {
        $category = CategoryFacade::get($id);

        return $category
            ? response()->json(['success' => true, 'data' => (new CategoryResource($category))->resolve()])
            : response()->json(['success' => false, 'message' => 'Category not found'], 404);
    }
}
