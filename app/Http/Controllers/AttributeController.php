<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\Attribute\AttributeResource;
use Domain\Facades\AttributeFacade\AttributeFacade;
use Illuminate\Http\JsonResponse;
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
}
