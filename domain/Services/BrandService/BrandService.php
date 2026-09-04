<?php

declare(strict_types=1);

namespace Domain\Services\BrandService;

use App\Models\Brand;
use Illuminate\Pagination\LengthAwarePaginator;

final class BrandService
{
    public function __construct(private Brand $model) {}

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
                ->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($query) => $query
                ->where('status', (bool) $filters['status']))
            ->when(
                in_array($filters['sort'] ?? null, ['name', 'code', 'status', 'created_at'], true),
                fn ($query) => $query->orderBy($filters['sort'], $filters['direction'] ?? 'asc'),
                fn ($query) => $query->latest(),
            )
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?Brand
    {
        return $this->model->find($id);
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
            ->map(fn (Brand $brand) => ['id' => $brand->id, 'name' => $brand->name])
            ->all();
    }
}
