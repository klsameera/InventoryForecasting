<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\Supplier\SupplierResource;
use Domain\Facades\SupplierFacade\SupplierFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'sort', 'direction']);

        $suppliers = SupplierFacade::all($filters)
            ->through(fn ($supplier) => (new SupplierResource($supplier))->resolve());

        return Inertia::render('Supplier/index', [
            'suppliers' => $suppliers,
            'filters' => (object) $filters,
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $suppliers = SupplierFacade::all($request->only(['search', 'status', 'sort', 'direction', 'per_page']))
            ->through(fn ($supplier) => (new SupplierResource($supplier))->resolve());

        return response()->json($suppliers);
    }

    public function get(int $id): JsonResponse
    {
        $supplier = SupplierFacade::get($id);

        return $supplier
            ? response()->json(['success' => true, 'data' => (new SupplierResource($supplier))->resolve()])
            : response()->json(['success' => false, 'message' => 'Supplier not found'], 404);
    }
}
