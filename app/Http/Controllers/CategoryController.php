<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Category\CreateCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\Category\CategoryResource;
use Domain\Facades\CategoryFacade\CategoryFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

    public function create(): Response
    {
        return Inertia::render('Category/create', [
            'parentOptions' => CategoryFacade::options(),
        ]);
    }

    public function edit(int $id): Response
    {
        $category = CategoryFacade::get($id);

        abort_if($category === null, 404);

        return Inertia::render('Category/edit', [
            'id' => $id,
            'category' => (new CategoryResource($category))->resolve(),
            'parentOptions' => CategoryFacade::options($id),
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

    public function store(CreateCategoryRequest $request): RedirectResponse
    {
        $result = CategoryFacade::store($request->validated());

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('category.index') : back()->withInput();
    }

    public function update(UpdateCategoryRequest $request, int $id): RedirectResponse
    {
        $result = CategoryFacade::update($request->validated(), $id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('category.index') : back()->withInput();
    }

    public function delete(int $id): RedirectResponse
    {
        $result = CategoryFacade::delete($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }
}
