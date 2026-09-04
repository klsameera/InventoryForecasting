<?php

declare(strict_types=1);

namespace Domain\Services\ProductVariantService;

use App\Models\ProductVariant;
use Illuminate\Pagination\LengthAwarePaginator;

final class ProductVariantService
{
    public function __construct(private ProductVariant $model) {}

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
            ->with([
                'product:id,name',
                'attributeValues:id,attribute_id,value',
                'attributeValues.attribute:id,name',
            ])
            ->withCount('skus')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where('name', 'like', "%{$search}%"))
            ->when($filters['product_id'] ?? null, fn ($query, $productId) => $query
                ->where('product_id', $productId))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($query) => $query
                ->where('status', (bool) $filters['status']))
            ->when(
                in_array($filters['sort'] ?? null, ['name', 'status', 'created_at'], true),
                fn ($query) => $query->orderBy($filters['sort'], $filters['direction'] ?? 'asc'),
                fn ($query) => $query->latest(),
            )
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?ProductVariant
    {
        return $this->model->with(['product:id,name', 'attributeValues.attribute'])->find($id);
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function optionsForProduct(int $productId): array
    {
        return $this->model
            ->newQuery()
            ->where('product_id', $productId)
            ->where('status', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (ProductVariant $variant) => ['id' => $variant->id, 'name' => $variant->name])
            ->all();
    }

    /**
     * All active variants across every product, for client-side filtering in
     * dropdowns that depend on a separately-selected product (e.g. the Sku
     * form). Small enough at foundation scale to send as one list.
     *
     * @return array<int, array{id: int, product_id: int, name: string}>
     */
    public function allOptions(): array
    {
        return $this->model
            ->newQuery()
            ->where('status', true)
            ->orderBy('name')
            ->get(['id', 'product_id', 'name'])
            ->map(fn (ProductVariant $variant) => [
                'id' => $variant->id,
                'product_id' => $variant->product_id,
                'name' => $variant->name,
            ])
            ->all();
    }
}
