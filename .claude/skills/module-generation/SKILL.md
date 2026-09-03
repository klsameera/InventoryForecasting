---
name: module-generation
description: "Use when building or changing any CRUD module in this application — creating a controller, service, facade, form request, API resource, model, migration, module route group, or the index/create/edit Inertia pages for a module. Also use when asked to 'generate a module', 'add a {X} module', 'scaffold {X}', or when reviewing whether existing backend code follows this project's Facade + Service + thin Controller architecture. Overrides generic Laravel architecture guidance: this project uses no Action classes, no repository pattern, and no constructor-injected services in controllers."
---

# Module generation

This project uses a strict **Facade → Service → thin Controller** architecture.
It overrides `laravel-best-practices/rules/architecture.md`, which teaches
Action classes and constructor injection into controllers. Where they conflict,
**this file wins**.

Read [`.ai/rules/architecture.md`](../../../.ai/rules/architecture.md) and
[`.ai/rules/module-checklist.md`](../../../.ai/rules/module-checklist.md) before
writing code. This skill is the how-to; those are the law.

## Non-negotiables

1. Controllers render Inertia, call a **Facade**, and return Resources/JSON.
   Nothing else. No queries, no transactions, no uploads, no slugs, no `if`
   ladders, no injected services.
2. All business logic lives in `domain/Services/{Module}Service/`.
3. Every controller → service hop goes through
   `domain/Facades/{Module}Facade/`.
4. No Action classes. No repositories. No new architectural layers.
5. No placeholder code and no invented sample data. If the module needs seed
   data, ask.
6. No Tailwind. See [`.ai/rules/no-tailwind.md`](../../../.ai/rules/no-tailwind.md).

## Generation order

Produce files in this exact order, each under its full path:

1. Migration → 2. Model → 3. Service → 4. Facade → 5. Controller →
6. Resource → 7. Create request → 8. Update request → 9. Routes →
10. `index.tsx` → 11. `create.tsx` → 12. `edit.tsx`

Never abbreviate with "same as above" and never omit imports.

## Templates

### Service

```php
<?php

declare(strict_types=1);

namespace Domain\Services\ProductService;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class ProductService
{
    public function __construct(private Product $model) {}

    public function count(): int
    {
        return $this->model->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function all(array $filters = []): LengthAwarePaginator
    {
        return $this->model
            ->newQuery()
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where('name', 'like', "%{$search}%"))
            ->when(isset($filters['status']), fn ($query) => $query
                ->where('status', (bool) $filters['status']))
            ->latest()
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?Product
    {
        return $this->model->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function store(array $data): array
    {
        DB::beginTransaction();

        try {
            $data['slug'] = Str::slug($data['name']);

            $product = $this->model->create($data);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Product created successfully',
                'data' => $product,
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed creating product', [
                'exception' => $exception->getMessage(),
                'data' => $data,
            ]);

            return ['success' => false, 'message' => 'Error creating product'];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(array $data, int $id): array
    {
        DB::beginTransaction();

        try {
            $product = $this->model->findOrFail($id);

            $product->update([...$product->only(array_keys($data)), ...$data]);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => $product->fresh(),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed updating product', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error updating product'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        DB::beginTransaction();

        try {
            $this->model->findOrFail($id)->delete();

            DB::commit();

            return ['success' => true, 'message' => 'Product deleted successfully'];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed deleting product', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error deleting product'];
        }
    }
}
```

### Facade

```php
<?php

declare(strict_types=1);

namespace Domain\Facades\ProductFacade;

use Domain\Services\ProductService\ProductService;
use Illuminate\Support\Facades\Facade;

/**
 * @see \Domain\Services\ProductService\ProductService
 */
final class ProductFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ProductService::class;
    }
}
```

### Controller

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Product\CreateProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\Product\ProductResource;
use Domain\Facades\ProductFacade\ProductFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProductController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Product/index');
    }

    public function create(): Response
    {
        return Inertia::render('Product/create');
    }

    public function edit(int $id): Response
    {
        return Inertia::render('Product/edit', ['id' => $id]);
    }

    public function all(Request $request): JsonResponse
    {
        $products = ProductFacade::all($request->only(['search', 'status', 'per_page']));

        return response()->json(
            ProductResource::collection($products)->response()->getData(true)
        );
    }

    public function get(int $id): JsonResponse
    {
        $product = ProductFacade::get($id);

        return $product
            ? response()->json(['success' => true, 'data' => new ProductResource($product)])
            : response()->json(['success' => false, 'message' => 'Product not found'], 404);
    }

    public function store(CreateProductRequest $request): JsonResponse
    {
        return response()->json(ProductFacade::store($request->validated()));
    }

    public function update(UpdateProductRequest $request, int $id): JsonResponse
    {
        return response()->json(ProductFacade::update($request->validated(), $id));
    }

    public function delete(int $id): JsonResponse
    {
        return response()->json(ProductFacade::delete($id));
    }
}
```

### Form request

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable', 'string', 'max:255',
                Rule::unique('products', 'slug')->ignore($this->route('id')),
            ],
            'price' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'A product name is required.',
            'price.min' => 'Price cannot be negative.',
        ];
    }
}
```

### Resource

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Product;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'price' => (float) $this->price,
            'status' => (bool) $this->status,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
```

## Frontend pages

Build `index.tsx` / `create.tsx` / `edit.tsx` with the existing UI kit — see the
`premium-ui-design` skill. Listing pages need filters, 20-per-page pagination,
sorting, empty and loading states, and `ConfirmDialog` for deletes.

## Verify

```
vendor/bin/pint --dirty --format agent
npm run types:check && npm run lint:check
php artisan test --compact --filter=Product
```

Note: this machine's default `php` is 8.2 but the app requires 8.3+. Use
Laragon's 8.3 build if artisan fails a platform check.
