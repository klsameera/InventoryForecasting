<?php

declare(strict_types=1);

namespace Domain\Services\AttributeService;

use App\Models\Attribute;
use Illuminate\Pagination\LengthAwarePaginator;

final class AttributeService
{
    public function __construct(private Attribute $model) {}

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
            ->withCount('values')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")))
            ->when(
                in_array($filters['sort'] ?? null, ['name', 'code', 'created_at'], true),
                fn ($query) => $query->orderBy($filters['sort'], $filters['direction'] ?? 'asc'),
                fn ($query) => $query->latest(),
            )
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?Attribute
    {
        return $this->model->with('values')->find($id);
    }

    /**
     * @return array<int, array{id: int, name: string, values: array<int, array{id: int, value: string}>}>
     */
    public function optionsWithValues(): array
    {
        return $this->model
            ->newQuery()
            ->with('values:id,attribute_id,value')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Attribute $attribute) => [
                'id' => $attribute->id,
                'name' => $attribute->name,
                'values' => $attribute->values
                    ->map(fn ($value) => ['id' => $value->id, 'value' => $value->value])
                    ->all(),
            ])
            ->all();
    }
}
