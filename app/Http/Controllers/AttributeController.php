<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Attribute\CreateAttributeRequest;
use App\Http\Requests\Attribute\UpdateAttributeRequest;
use App\Http\Resources\Attribute\AttributeResource;
use Domain\Facades\AttributeFacade\AttributeFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AttributeController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'sort', 'direction']);

        $attributes = AttributeFacade::all($filters)
            ->through(fn ($attribute) => (new AttributeResource($attribute))->resolve());

        return Inertia::render('Attribute/index', [
            'attributes' => $attributes,
            'filters' => (object) $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Attribute/create');
    }

    public function edit(int $id): Response
    {
        $attribute = AttributeFacade::get($id);

        abort_if($attribute === null, 404);

        return Inertia::render('Attribute/edit', [
            'id' => $id,
            'attribute' => (new AttributeResource($attribute))->resolve(),
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $attributes = AttributeFacade::all($request->only(['search', 'sort', 'direction', 'per_page']))
            ->through(fn ($attribute) => (new AttributeResource($attribute))->resolve());

        return response()->json($attributes);
    }

    public function get(int $id): JsonResponse
    {
        $attribute = AttributeFacade::get($id);

        return $attribute
            ? response()->json(['success' => true, 'data' => (new AttributeResource($attribute))->resolve()])
            : response()->json(['success' => false, 'message' => 'Attribute not found'], 404);
    }

    public function store(CreateAttributeRequest $request): RedirectResponse
    {
        $result = AttributeFacade::store($request->validated());

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('attribute.index') : back()->withInput();
    }

    public function update(UpdateAttributeRequest $request, int $id): RedirectResponse
    {
        $result = AttributeFacade::update($request->validated(), $id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('attribute.index') : back()->withInput();
    }

    public function delete(int $id): RedirectResponse
    {
        $result = AttributeFacade::delete($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }
}
