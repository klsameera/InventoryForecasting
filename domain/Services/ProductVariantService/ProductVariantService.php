<?php

declare(strict_types=1);

namespace Domain\Services\ProductVariantService;

use App\Models\ProductVariant;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function store(array $data): array
    {
        DB::beginTransaction();

        try {
            $attributeValues = $data['attribute_values'] ?? [];
            unset($data['attribute_values']);

            $variant = $this->model->create($data);

            $variant->attributeValues()->sync($this->pivotData($attributeValues));

            DB::commit();

            return [
                'success' => true,
                'message' => 'Variant created successfully',
                'data' => $variant->fresh('attributeValues'),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed creating product variant', [
                'exception' => $exception->getMessage(),
                'data' => $data,
            ]);

            return ['success' => false, 'message' => 'Error creating variant'];
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
            $variant = $this->model->findOrFail($id);

            $attributeValues = $data['attribute_values'] ?? null;
            unset($data['attribute_values']);

            $variant->update($data);

            if ($attributeValues !== null) {
                $variant->attributeValues()->sync($this->pivotData($attributeValues));
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Variant updated successfully',
                'data' => $variant->fresh('attributeValues'),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed updating product variant', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error updating variant'];
        }
    }

    /**
     * @param  array<int, array{attribute_id: int, attribute_value_id: int}>  $attributeValues
     * @return array<int, array{attribute_id: int}>
     */
    private function pivotData(array $attributeValues): array
    {
        $sync = [];

        foreach ($attributeValues as $attributeValue) {
            $sync[(int) $attributeValue['attribute_value_id']] = [
                'attribute_id' => (int) $attributeValue['attribute_id'],
            ];
        }

        return $sync;
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        DB::beginTransaction();

        try {
            $variant = $this->model->findOrFail($id);

            if ($variant->skus()->exists()) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Cannot delete a variant that still has SKUs'];
            }

            $variant->delete();

            DB::commit();

            return ['success' => true, 'message' => 'Variant deleted successfully'];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed deleting product variant', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error deleting variant'];
        }
    }
}
