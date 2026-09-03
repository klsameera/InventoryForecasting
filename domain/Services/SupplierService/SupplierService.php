<?php

declare(strict_types=1);

namespace Domain\Services\SupplierService;

use App\Models\Supplier;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function store(array $data): array
    {
        DB::beginTransaction();

        try {
            $supplierSkus = $data['supplier_skus'] ?? [];
            unset($data['supplier_skus']);

            $supplier = $this->model->create($data);

            $this->syncSupplierSkus($supplier, $supplierSkus);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Supplier created successfully',
                'data' => $supplier->fresh('supplierSkus'),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed creating supplier', [
                'exception' => $exception->getMessage(),
                'data' => $data,
            ]);

            return ['success' => false, 'message' => 'Error creating supplier'];
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
            $supplier = $this->model->findOrFail($id);

            $supplierSkus = $data['supplier_skus'] ?? null;
            unset($data['supplier_skus']);

            $supplier->update($data);

            if ($supplierSkus !== null) {
                $this->syncSupplierSkus($supplier, $supplierSkus);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Supplier updated successfully',
                'data' => $supplier->fresh('supplierSkus'),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed updating supplier', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error updating supplier'];
        }
    }

    /**
     * Create/update/remove child supplier-SKU rows to match the incoming
     * list. When a row is marked primary, any other supplier's primary flag
     * for that same SKU is cleared, so a SKU has at most one primary
     * supplier at a time.
     *
     * @param  array<int, array{id?: int|null, sku_id: int, supplier_sku?: string|null, unit_cost: float, minimum_order_qty?: int, order_multiple?: int, expected_lead_time_days?: int|null, is_primary?: bool, status?: bool}>  $supplierSkus
     */
    private function syncSupplierSkus(Supplier $supplier, array $supplierSkus): void
    {
        $keptIds = [];

        foreach ($supplierSkus as $row) {
            $attributes = [
                'sku_id' => $row['sku_id'],
                'supplier_sku' => $row['supplier_sku'] ?? null,
                'unit_cost' => $row['unit_cost'],
                'minimum_order_qty' => $row['minimum_order_qty'] ?? 1,
                'order_multiple' => $row['order_multiple'] ?? 1,
                'expected_lead_time_days' => $row['expected_lead_time_days'] ?? null,
                'is_primary' => (bool) ($row['is_primary'] ?? false),
                'status' => (bool) ($row['status'] ?? true),
            ];

            if (! empty($row['id'])) {
                $supplier->supplierSkus()->where('id', $row['id'])->update($attributes);
                $keptIds[] = (int) $row['id'];
            } else {
                $created = $supplier->supplierSkus()->create($attributes);
                $keptIds[] = $created->id;
            }

            if ($attributes['is_primary']) {
                DB::table('supplier_skus')
                    ->where('sku_id', $row['sku_id'])
                    ->where('supplier_id', '!=', $supplier->id)
                    ->update(['is_primary' => false]);
            }
        }

        $supplier->supplierSkus()->whereNotIn('id', $keptIds)->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        DB::beginTransaction();

        try {
            $supplier = $this->model->findOrFail($id);

            $hasOrders = $supplier->purchaseOrders()->exists();

            if ($hasOrders) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Cannot delete a supplier that has purchase orders'];
            }

            $supplier->supplierSkus()->delete();
            $supplier->delete();

            DB::commit();

            return ['success' => true, 'message' => 'Supplier deleted successfully'];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed deleting supplier', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error deleting supplier'];
        }
    }
}
