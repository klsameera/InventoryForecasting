<?php

declare(strict_types=1);

namespace Domain\Services\ProductService;

use App\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProductService
{
    public function __construct(private Product $model) {}

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
            ->with(['category:id,name', 'brand:id,name'])
            ->withCount(['variants', 'skus'])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('model_number', 'like', "%{$search}%")))
            ->when($filters['category_id'] ?? null, fn ($query, $categoryId) => $query
                ->where('category_id', $categoryId))
            ->when($filters['brand_id'] ?? null, fn ($query, $brandId) => $query
                ->where('brand_id', $brandId))
            ->when($filters['product_type'] ?? null, fn ($query, $type) => $query
                ->where('product_type', $type))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($query) => $query
                ->where('status', (bool) $filters['status']))
            ->when(
                in_array($filters['sort'] ?? null, ['name', 'model_number', 'model_year', 'status', 'created_at'], true),
                fn ($query) => $query->orderBy($filters['sort'], $filters['direction'] ?? 'asc'),
                fn ($query) => $query->latest(),
            )
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?Product
    {
        return $this->model->with(['category:id,name', 'brand:id,name'])->find($id);
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
            ->map(fn (Product $product) => ['id' => $product->id, 'name' => $product->name])
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
            $product = $this->model->create($data);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Product created successfully',
                'data' => $product,
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed creating product', [
                'exception' => $exception->getMessage(),
                'data' => $data,
            ]);

            return ['success' => false, 'message' => 'Error creating product'];
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
            $product = $this->model->findOrFail($id);

            $product->update($data);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => $product->fresh(),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed updating product', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error updating product'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        DB::beginTransaction();

        try {
            $product = $this->model->findOrFail($id);

            if ($product->skus()->exists()) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Cannot delete a product that still has SKUs'];
            }

            $product->delete();

            DB::commit();

            return ['success' => true, 'message' => 'Product deleted successfully'];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed deleting product', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error deleting product'];
        }
    }
}
