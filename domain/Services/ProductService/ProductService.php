<?php

declare(strict_types=1);

namespace Domain\Services\ProductService;

use App\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;

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
            ->with(['category:id,name', 'brand:id,name'])
            ->withCount(['variants', 'skus'])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('model_number', 'like', "%{$search}%")))
            ->when($filters['category_id'] ?? null, fn ($query, $categoryId) => $query
                ->where('category_id', $categoryId))
            ->when($filters['brand_id'] ?? null, fn ($query, $brandId) => $query
                ->where('brand_id', $brandId))
            ->when($filters['product_type'] ?? null, fn ($query, $type) => $query
                ->where('product_type', $type))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($query) => $query
                ->where('status', (bool) $filters['status']))
            ->when(
                in_array($filters['sort'] ?? null, ['name', 'model_number', 'model_year', 'status', 'created_at'], true),
                fn ($query) => $query->orderBy($filters['sort'], $filters['direction'] ?? 'asc'),
                fn ($query) => $query->latest(),
            )
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?Product
    {
        return $this->model->with(['category:id,name', 'brand:id,name'])->find($id);
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function options(): array
    {
        return $this->model
            ->newQuery()
            ->where('status', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Product $product) => ['id' => $product->id, 'name' => $product->name])
            ->all();
    }
}
