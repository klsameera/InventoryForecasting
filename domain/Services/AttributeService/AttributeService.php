<?php

declare(strict_types=1);

namespace Domain\Services\AttributeService;

use App\Models\Attribute;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function store(array $data): array
    {
        DB::beginTransaction();

        try {
            $values = $data['values'] ?? [];
            unset($data['values']);

            $attribute = $this->model->create($data);

            $this->syncValues($attribute, $values);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Attribute created successfully',
                'data' => $attribute->fresh('values'),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed creating attribute', [
                'exception' => $exception->getMessage(),
                'data' => $data,
            ]);

            return ['success' => false, 'message' => 'Error creating attribute'];
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
            $attribute = $this->model->findOrFail($id);

            $values = $data['values'] ?? null;
            unset($data['values']);

            $attribute->update($data);

            if ($values !== null) {
                $syncResult = $this->syncValues($attribute, $values);

                if ($syncResult !== null) {
                    DB::rollBack();

                    return ['success' => false, 'message' => $syncResult];
                }
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Attribute updated successfully',
                'data' => $attribute->fresh('values'),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed updating attribute', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error updating attribute'];
        }
    }

    /**
     * Create/update/remove child attribute values to match the incoming list.
     * Returns a failure message if a value in use by a variant would be
     * removed, otherwise null.
     *
     * @param  array<int, array{id?: int|null, value: string, sort_order?: int}>  $values
     */
    private function syncValues(Attribute $attribute, array $values): ?string
    {
        $keptIds = [];

        foreach ($values as $index => $value) {
            if (! empty($value['id'])) {
                $attribute->values()->where('id', $value['id'])->update([
                    'value' => $value['value'],
                    'sort_order' => $value['sort_order'] ?? $index,
                ]);
                $keptIds[] = (int) $value['id'];
            } else {
                $created = $attribute->values()->create([
                    'value' => $value['value'],
                    'sort_order' => $value['sort_order'] ?? $index,
                ]);
                $keptIds[] = $created->id;
            }
        }

        $removedIds = $attribute->values()->whereNotIn('id', $keptIds)->pluck('id');

        if ($removedIds->isEmpty()) {
            return null;
        }

        $inUse = DB::table('variant_attribute_values')
            ->whereIn('attribute_value_id', $removedIds)
            ->exists();

        if ($inUse) {
            return 'Cannot remove an attribute value that is still assigned to a variant';
        }

        $attribute->values()->whereIn('id', $removedIds)->delete();

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        DB::beginTransaction();

        try {
            $attribute = $this->model->findOrFail($id);

            $inUse = DB::table('variant_attribute_values')
                ->where('attribute_id', $id)
                ->exists();

            if ($inUse) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Cannot delete an attribute that is still assigned to variants'];
            }

            $attribute->delete();

            DB::commit();

            return ['success' => true, 'message' => 'Attribute deleted successfully'];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed deleting attribute', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error deleting attribute'];
        }
    }
}
