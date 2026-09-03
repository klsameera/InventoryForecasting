<?php

declare(strict_types=1);

namespace Domain\Services\WarehouseService;

use App\Models\Warehouse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class WarehouseService
{
    public function __construct(private Warehouse $model) {}

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

    public function get(int $id): ?Warehouse
    {
        return $this->model->find($id);
    }

    /**
     * Lightweight options list for other modules' dropdowns.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function options(): array
    {
        return $this->model
            ->newQuery()
            ->where('status', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Warehouse $warehouse) => ['id' => $warehouse->id, 'name' => $warehouse->name])
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
            $warehouse = $this->model->create($data);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Warehouse created successfully',
                'data' => $warehouse,
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed creating warehouse', [
                'exception' => $exception->getMessage(),
                'data' => $data,
            ]);

            return ['success' => false, 'message' => 'Error creating warehouse'];
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
            $warehouse = $this->model->findOrFail($id);

            $warehouse->update($data);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Warehouse updated successfully',
                'data' => $warehouse->fresh(),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed updating warehouse', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error updating warehouse'];
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

            return ['success' => true, 'message' => 'Warehouse deleted successfully'];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed deleting warehouse', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error deleting warehouse'];
        }
    }
}
