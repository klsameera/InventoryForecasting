# Module checklist

**Globs:** any new or changed CRUD module

A module is complete only when every file below exists. Generate them in this
order — it is also the order to present them in when reporting the work.

| # | File |
| --- | --- |
| 1 | `database/migrations/YYYY_MM_DD_HHMMSS_create_{modules}_table.php` |
| 2 | `app/Models/{Module}.php` |
| 3 | `domain/Services/{Module}Service/{Module}Service.php` |
| 4 | `domain/Facades/{Module}Facade/{Module}Facade.php` |
| 5 | `app/Http/Controllers/{Module}Controller.php` |
| 6 | `app/Http/Resources/{Module}/{Module}Resource.php` |
| 7 | `app/Http/Requests/{Module}/Create{Module}Request.php` |
| 8 | `app/Http/Requests/{Module}/Update{Module}Request.php` |
| 9 | `routes/web.php` (append the module group) |
| 10 | `resources/js/pages/{Module}/index.tsx` |
| 11 | `resources/js/pages/{Module}/create.tsx` |
| 12 | `resources/js/pages/{Module}/edit.tsx` |

## Naming

| Thing | Convention | Example |
| --- | --- | --- |
| Model | PascalCase singular | `LoyaltyRule` |
| Table | snake_case plural | `loyalty_rules` |
| Route prefix | kebab-case singular | `/loyalty-rule` |
| Route names | `{module}.{action}` | `loyalty-rule.index` |
| Permissions | `{module}_{action}` | `loyalty_rule_view` |
| Page folder | PascalCase singular | `resources/js/pages/LoyaltyRule/` |

## Route group

```php
Route::prefix('/product')->middleware('auth')->group(function () {
    Route::get('/', [ProductController::class, 'index'])->name('product.index');
    Route::get('/create', [ProductController::class, 'create'])->name('product.create');
    Route::get('/all', [ProductController::class, 'all'])->name('product.all');
    Route::get('/{id}/edit', [ProductController::class, 'edit'])->name('product.edit');
    Route::get('/{id}/get', [ProductController::class, 'get'])->name('product.get');

    Route::post('/store', [ProductController::class, 'store'])->name('product.store');
    Route::post('/{id}/update', [ProductController::class, 'update'])->name('product.update');
    Route::delete('/{id}/delete', [ProductController::class, 'delete'])->name('product.delete');
});
```

Declare `/create` and `/all` **before** `/{id}/...` so the literal segments are
not swallowed by the wildcard. See `architecture.md` on permission middleware.

## Before you call it done

- [ ] No business logic in the controller; every call goes through the Facade
- [ ] Service wraps writes in a transaction and logs failures
- [ ] Both FormRequests exist with `authorize()`, `rules()`, and useful `messages()`
- [ ] Resource exposes only the fields the UI needs and handles null relations
- [ ] Migration has indexes, constraints, `timestamps()`, `softDeletes()` if used
- [ ] `index.tsx` has filters, 20-per-page pagination, sorting, empty + loading states
- [ ] `create.tsx` / `edit.tsx` use `FormField` and show server errors
- [ ] Delete goes through `ConfirmDialog` — never `window.confirm`
- [ ] Zero Tailwind classes anywhere
- [ ] Sidebar entry added in `resources/js/components/app-sidebar.tsx` if user-facing
- [ ] `vendor/bin/pint --dirty` clean; `npm run types:check` and `lint:check` pass
- [ ] A feature test covers the new routes, and it passes
