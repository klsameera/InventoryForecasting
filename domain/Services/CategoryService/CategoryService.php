<?php

declare(strict_types=1);

namespace Domain\Services\CategoryService;

use App\Models\Category;
use Illuminate\Pagination\LengthAwarePaginator;

final class CategoryService
{
    public function __construct(private Category $model) {}

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
            ->with('parent:id,name')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($query) => $query
                ->where('status', (bool) $filters['status']))
            ->when($filters['parent_id'] ?? null, fn ($query, $parentId) => $query
                ->where('parent_id', $parentId))
            ->when(
                in_array($filters['sort'] ?? null, ['name', 'code', 'status', 'created_at'], true),
                fn ($query) => $query->orderBy($filters['sort'], $filters['direction'] ?? 'asc'),
                fn ($query) => $query->latest(),
            )
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?Category
    {
        return $this->model->with('parent:id,name')->find($id);
    }

    /**
     * Lightweight options list for dropdowns. Excludes a category and all of
     * its descendants so a category can never become its own ancestor.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function options(?int $excludeId = null): array
    {
        $excluded = $excludeId !== null ? $this->descendantIds($excludeId) : [];

        return $this->model
            ->newQuery()
            ->where('status', true)
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('id', $excluded))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->name])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function descendantIds(int $categoryId): array
    {
        $ids = [$categoryId];
        $queue = [$categoryId];

        while ($queue !== []) {
            $childIds = $this->model->newQuery()
                ->whereIn('parent_id', $queue)
                ->pluck('id')
                ->all();

            $queue = array_diff($childIds, $ids);
            $ids = array_merge($ids, $queue);
        }

        return $ids;
    }
}
