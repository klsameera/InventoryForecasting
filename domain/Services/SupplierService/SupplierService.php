<?php

declare(strict_types=1);

namespace Domain\Services\SupplierService;

use App\Models\Supplier;
use Illuminate\Pagination\LengthAwarePaginator;

final class SupplierService
{
    public function __construct(private Supplier $model) {}

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
            ->withCount('supplierSkus')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where('name', 'like', "%{$search}%"))
            ->when(isset($filters['status']), fn ($query) => $query
                ->where('status', (bool) $filters['status']))
            ->when(
                in_array($filters['sort'] ?? null, ['name', 'default_lead_time_days', 'created_at'], true),
                fn ($query) => $query->orderBy($filters['sort'], $filters['direction'] ?? 'asc'),
                fn ($query) => $query->latest(),
            )
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?Supplier
    {
        return $this->model->with('supplierSkus.sku:id,sku,product_id', 'supplierSkus.sku.product:id,name')->find($id);
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
            ->map(fn (Supplier $supplier) => ['id' => $supplier->id, 'name' => $supplier->name])
            ->all();
    }
}
