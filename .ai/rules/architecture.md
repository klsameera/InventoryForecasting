# Backend architecture — Facade + Service + thin Controller

**Globs:** `app/**`, `domain/**`, `routes/**`, `database/migrations/**`

This project uses a strict custom Facade → Service architecture. It **overrides**
the generic Laravel guidance in `laravel-best-practices/rules/architecture.md`:
no Action classes, no repository pattern, and controllers do **not** receive
services through constructor injection.

## The layers

```
Request → Route → FormRequest → Controller → Facade → Service → Model
                                     ↓
                             Inertia page / API Resource
```

| Layer | Path | Responsibility |
| --- | --- | --- |
| Controller | `app/Http/Controllers/{Module}Controller.php` | Render Inertia pages, accept validated requests, call the Facade, return Resources/JSON |
| Facade | `domain/Facades/{Module}Facade/{Module}Facade.php` | Static entry point; resolves the Service |
| Service | `domain/Services/{Module}Service/{Module}Service.php` | **All** business logic, transactions, logging |
| Request | `app/Http/Requests/{Module}/{Create,Update}{Module}Request.php` | Validation rules and messages |
| Resource | `app/Http/Resources/{Module}/{Module}Resource.php` | Output shaping |
| Model | `app/Models/{Module}.php` | Schema, casts, relationships |

`Domain\` is PSR-4 mapped to `domain/` in `composer.json`. Run
`composer dump-autoload` after adding the first class in a new namespace.

## Controllers

Allowed: render Inertia, call a Facade, wrap in a Resource, return a redirect.

Forbidden in a controller: business logic, raw queries, `DB::` calls,
`Storage::` calls, slug generation, status toggling, transactions, inline
validation, and constructor-injected services.

```php
// app/Http/Controllers/ProductController.php
final class ProductController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Product/index');
    }

    public function all(Request $request): JsonResponse
    {
        return response()->json(
            ProductResource::collection(ProductFacade::all($request->all()))
                ->response()
                ->getData(true)
        );
    }

    public function store(CreateProductRequest $request): JsonResponse
    {
        return response()->json(ProductFacade::store($request->validated()));
    }
}
```

## Services

Initialise the model in the constructor. Wrap every write in a transaction, log
every failure, and return a consistent envelope.

```php
final class ProductService
{
    public function __construct(private Product $model) {}

    public function store(array $data): array
    {
        DB::beginTransaction();

        try {
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
}
```

Baseline methods: `count()`, `all()`, `get($id)`, `store(array $data)`,
`update(array $data, $id)`, `delete(int $id)`. Add `getBySlug`, `updateStatus`,
`deleteSelected` and module-specific methods only when the module needs them.

Never let a write fail silently. Never duplicate logic between services —
extract a shared private method or call the other Facade.

## Facades

```php
// domain/Facades/ProductFacade/ProductFacade.php
final class ProductFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ProductService::class;
    }
}
```

Nothing else goes in a Facade. Laravel resolves `ProductService::class` out of
the container, so no manual binding is needed unless the service takes
non-resolvable constructor arguments.

## Listing endpoints

Listings paginate at **20 per page** by default, matching the frontend
`DEFAULT_PER_PAGE`. Always apply an explicit sort — `latest()` at minimum — and
eager-load relationships the Resource touches so listings do not N+1.

## Routes

Add module routes to `routes/web.php` following the shape in
`module-checklist.md`. Route names are `{module}.index`, `.create`, `.edit`,
`.all`, `.get`, `.store`, `.update`, `.delete`.

**Permission middleware:** the strict spec calls for
`middleware('permission:{module}_view')`. No permission package is installed in
this project yet, so **omit the permission middleware until one is** — a
middleware alias that does not exist throws at boot. Keep `auth` (and `verified`
where appropriate). When a permission package is added, apply the
`{module}_{view,create,update,delete}` naming.

## Models & migrations

Models: explicit `$fillable` (never empty, never `$guarded = []`), explicit
`casts()`, explicit relationships, `SoftDeletes` where records are recoverable.
Cast `status` to `boolean`, timestamps to `datetime`, money to `decimal:2`,
JSON columns to `array`.

Migrations: correct column types, `timestamps()`, `softDeletes()` when the model
uses the trait, indexes on foreign keys and anything you filter or sort by,
unique constraints on slugs and codes. `nullable()` only when the column is
genuinely optional.
