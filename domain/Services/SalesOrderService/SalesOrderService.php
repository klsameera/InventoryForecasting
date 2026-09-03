<?php

declare(strict_types=1);

namespace Domain\Services\SalesOrderService;

use App\Enums\MovementType;
use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use Domain\Facades\StockMovementFacade\StockMovementFacade;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Status workflow: Draft → Confirmed, or Cancelled from Draft only — a
 * confirmed order has already deducted stock (app_plan.md §16, §88), so
 * reversing one is a Sales Return, not a cancellation. Editing and deleting
 * are only allowed while Draft. {@see confirm()} is the only path that
 * touches stock: it posts a SALE movement per line, which also captures the
 * FIFO-consumed cost onto the line for margin reporting.
 */
final class SalesOrderService
{
    public function __construct(private SalesOrder $model) {}

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
            ->with('warehouse:id,name')
            ->withCount('items')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where(fn ($q) => $q
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query
                ->where('status', $status))
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query
                ->where('warehouse_id', $warehouseId))
            ->when(
                in_array($filters['sort'] ?? null, ['order_number', 'order_date', 'total', 'status', 'created_at'], true),
                fn ($query) => $query->orderBy($filters['sort'], $filters['direction'] ?? 'asc'),
                fn ($query) => $query->latest(),
            )
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?SalesOrder
    {
        return $this->model
            ->with(['warehouse:id,name', 'items.sku:id,sku,product_id', 'items.sku.product:id,name'])
            ->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function store(array $data): array
    {
        DB::beginTransaction();

        try {
            $items = $data['items'] ?? [];
            unset($data['items']);

            $data['status'] = SalesOrderStatus::Draft;

            [$subtotal, $total] = $this->totals($items, (float) ($data['discount'] ?? 0), (float) ($data['tax'] ?? 0));
            $data['subtotal'] = $subtotal;
            $data['discount'] ??= 0;
            $data['tax'] ??= 0;
            $data['total'] = $total;

            $data['order_number'] = 'SO-'.Str::upper(Str::random(10));
            $order = $this->model->create($data);
            $order->update(['order_number' => 'SO-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($items as $item) {
                $order->items()->create([
                    'sku_id' => $item['sku_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount' => $item['discount'] ?? 0,
                    'net_amount' => ($item['quantity'] * $item['unit_price']) - ($item['discount'] ?? 0),
                ]);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Sales order created successfully',
                'data' => $order->fresh('items'),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed creating sales order', [
                'exception' => $exception->getMessage(),
                'data' => $data,
            ]);

            return ['success' => false, 'message' => 'Error creating sales order'];
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
            $order = $this->model->findOrFail($id);

            if ($order->status !== SalesOrderStatus::Draft) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Only draft sales orders can be edited'];
            }

            $items = $data['items'] ?? [];
            unset($data['items'], $data['status']);

            [$subtotal, $total] = $this->totals($items, (float) ($data['discount'] ?? $order->discount), (float) ($data['tax'] ?? $order->tax));
            $data['subtotal'] = $subtotal;
            $data['total'] = $total;

            $order->update($data);
            $order->items()->delete();

            foreach ($items as $item) {
                $order->items()->create([
                    'sku_id' => $item['sku_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount' => $item['discount'] ?? 0,
                    'net_amount' => ($item['quantity'] * $item['unit_price']) - ($item['discount'] ?? 0),
                ]);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Sales order updated successfully',
                'data' => $order->fresh('items'),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed updating sales order', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error updating sales order'];
        }
    }

    /**
     * @param  array<int, array{quantity: int, unit_price: float, discount?: float}>  $items
     * @return array{0: float, 1: float}
     */
    private function totals(array $items, float $discount, float $tax): array
    {
        $subtotal = array_reduce(
            $items,
            fn (float $carry, array $item) => $carry + ($item['quantity'] * $item['unit_price']) - ($item['discount'] ?? 0),
            0.0,
        );

        return [round($subtotal, 2), round($subtotal - $discount + $tax, 2)];
    }

    /**
     * @return array<string, mixed>
     */
    public function confirm(int $id): array
    {
        DB::beginTransaction();

        try {
            $order = $this->model->with('items')->findOrFail($id);

            if ($order->status !== SalesOrderStatus::Draft) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Only draft sales orders can be confirmed'];
            }

            foreach ($order->items as $item) {
                $result = StockMovementFacade::post([
                    'warehouse_id' => $order->warehouse_id,
                    'sku_id' => $item->sku_id,
                    'movement_type' => MovementType::Sale,
                    'quantity' => $item->quantity,
                    'reference_type' => 'sales_order',
                    'reference_id' => $order->id,
                    'occurred_at' => $order->order_date,
                ]);

                if (! $result['success']) {
                    DB::rollBack();

                    return $result;
                }

                if ($result['batch_cost'] !== null) {
                    $item->update(['cost' => $result['batch_cost']]);
                }
            }

            $order->update(['status' => SalesOrderStatus::Confirmed]);

            DB::commit();

            return ['success' => true, 'message' => 'Sales order confirmed', 'data' => $order->fresh('items')];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed confirming sales order', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error confirming sales order'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(int $id): array
    {
        DB::beginTransaction();

        try {
            $order = $this->model->findOrFail($id);

            if ($order->status !== SalesOrderStatus::Draft) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Only draft sales orders can be cancelled — a confirmed order needs a sales return instead'];
            }

            $order->update(['status' => SalesOrderStatus::Cancelled]);

            DB::commit();

            return ['success' => true, 'message' => 'Sales order cancelled', 'data' => $order->fresh()];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed cancelling sales order', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error cancelling sales order'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        DB::beginTransaction();

        try {
            $order = $this->model->findOrFail($id);

            if ($order->status !== SalesOrderStatus::Draft) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Only draft sales orders can be deleted'];
            }

            $order->items()->delete();
            $order->delete();

            DB::commit();

            return ['success' => true, 'message' => 'Sales order deleted successfully'];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed deleting sales order', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error deleting sales order'];
        }
    }
}
