<?php

declare(strict_types=1);

namespace Domain\Services\SkuService;

use App\Models\Sku;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SkuService
{
    public function __construct(private Sku $model) {}

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
            ->with(['product:id,name', 'variant:id,name'])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where(fn ($q) => $q
                    ->where('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")))
            ->when($filters['product_id'] ?? null, fn ($query, $productId) => $query
                ->where('product_id', $productId))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($query) => $query
                ->where('status', (bool) $filters['status']))
            ->when(
                in_array($filters['sort'] ?? null, ['sku', 'cost_price', 'selling_price', 'status', 'created_at'], true),
                fn ($query) => $query->orderBy($filters['sort'], $filters['direction'] ?? 'asc'),
                fn ($query) => $query->latest(),
            )
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?Sku
    {
        return $this->model->with(['product:id,name', 'variant:id,name'])->find($id);
    }

    /**
     * @return array<int, array{id: int, sku: string}>
     */
    public function options(): array
    {
        return $this->model
            ->newQuery()
            ->where('status', true)
            ->orderBy('sku')
            ->get(['id', 'sku'])
            ->map(fn (Sku $sku) => ['id' => $sku->id, 'sku' => $sku->sku])
            ->all();
    }

    /**
     * Sku options carrying pricing, for modules (Purchase Orders, Sales
     * Orders, Supplier SKUs) that auto-fill a line's unit cost/price from the
     * chosen SKU.
     *
     * @return array<int, array{id: int, sku: string, product_name: string, cost_price: float, selling_price: float}>
     */
    public function optionsWithPricing(): array
    {
        return $this->model
            ->newQuery()
            ->with('product:id,name')
            ->where('status', true)
            ->orderBy('sku')
            ->get(['id', 'sku', 'product_id', 'cost_price', 'selling_price'])
            ->map(fn (Sku $sku) => [
                'id' => $sku->id,
                'sku' => $sku->sku,
                'product_name' => $sku->product?->name ?? '',
                'cost_price' => (float) $sku->cost_price,
                'selling_price' => (float) $sku->selling_price,
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
            $sku = $this->model->create($data);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Sku created successfully',
                'data' => $sku,
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed creating sku', [
                'exception' => $exception->getMessage(),
                'data' => $data,
            ]);

            return ['success' => false, 'message' => 'Error creating SKU'];
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
            $sku = $this->model->findOrFail($id);

            $sku->update($data);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Sku updated successfully',
                'data' => $sku->fresh(),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed updating sku', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error updating SKU'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        DB::beginTransaction();

        try {
            $sku = $this->model->findOrFail($id);

            if ($sku->inventories()->where('on_hand_qty', '>', 0)->exists()) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Cannot delete a SKU that still has stock on hand'];
            }

            $sku->delete();

            DB::commit();

            return ['success' => true, 'message' => 'Sku deleted successfully'];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed deleting sku', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error deleting SKU'];
        }
    }
}
