<?php

declare(strict_types=1);

namespace Domain\Services\PurchaseOrderService;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Domain\Facades\InventoryFacade\InventoryFacade;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Status workflow: Draft → Approved → Ordered → PartiallyReceived/Received,
 * or Cancelled from Draft/Approved/Ordered. Editing and deleting are only
 * allowed while Draft. Receiving is driven by GoodsReceiptService, which
 * calls {@see registerReceipt()} — this Service never touches stock directly,
 * see app_plan.md §88.
 */
final class PurchaseOrderService
{
    public function __construct(private PurchaseOrder $model) {}

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
            ->with(['supplier:id,name', 'warehouse:id,name'])
            ->withCount('items')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where('po_number', 'like', "%{$search}%"))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query
                ->where('status', $status))
            ->when($filters['supplier_id'] ?? null, fn ($query, $supplierId) => $query
                ->where('supplier_id', $supplierId))
            ->when(
                in_array($filters['sort'] ?? null, ['po_number', 'order_date', 'total', 'status', 'created_at'], true),
                fn ($query) => $query->orderBy($filters['sort'], $filters['direction'] ?? 'asc'),
                fn ($query) => $query->latest(),
            )
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?PurchaseOrder
    {
        return $this->model
            ->with(['supplier:id,name', 'warehouse:id,name', 'items.sku:id,sku,product_id', 'items.sku.product:id,name'])
            ->find($id);
    }

    /**
     * Purchase orders currently able to receive goods against them
     * ({@see PurchaseOrderStatus::receivableCases()}) — used by the Goods
     * Receipt module's create form.
     *
     * @return array<int, array{id: int, po_number: string, supplier_name: string}>
     */
    public function receivableOptions(): array
    {
        return $this->model
            ->newQuery()
            ->with('supplier:id,name')
            ->whereIn('status', array_map(fn ($case) => $case->value, PurchaseOrderStatus::receivableCases()))
            ->orderBy('po_number')
            ->get(['id', 'po_number', 'supplier_id'])
            ->map(fn (PurchaseOrder $purchaseOrder) => [
                'id' => $purchaseOrder->id,
                'po_number' => $purchaseOrder->po_number,
                'supplier_name' => $purchaseOrder->supplier?->name ?? '',
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
            $items = $data['items'] ?? [];
            unset($data['items']);

            $data['status'] = PurchaseOrderStatus::Draft;
            $data['created_by'] = $data['created_by'] ?? null;

            [$subtotal, $total] = $this->totals($items, (float) ($data['tax'] ?? 0));
            $data['subtotal'] = $subtotal;
            $data['tax'] ??= 0;
            $data['total'] = $total;

            $data['po_number'] = 'PO-'.Str::upper(Str::random(10));
            $purchaseOrder = $this->model->create($data);
            $purchaseOrder->update(['po_number' => 'PO-'.str_pad((string) $purchaseOrder->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($items as $item) {
                $purchaseOrder->items()->create([
                    'sku_id' => $item['sku_id'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'line_total' => $item['quantity'] * $item['unit_cost'],
                ]);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Purchase order created successfully',
                'data' => $purchaseOrder->fresh('items'),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed creating purchase order', [
                'exception' => $exception->getMessage(),
                'data' => $data,
            ]);

            return ['success' => false, 'message' => 'Error creating purchase order'];
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
            $purchaseOrder = $this->model->findOrFail($id);

            if ($purchaseOrder->status !== PurchaseOrderStatus::Draft) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Only draft purchase orders can be edited'];
            }

            $items = $data['items'] ?? [];
            unset($data['items'], $data['status']);

            [$subtotal, $total] = $this->totals($items, (float) ($data['tax'] ?? $purchaseOrder->tax));
            $data['subtotal'] = $subtotal;
            $data['total'] = $total;

            $purchaseOrder->update($data);

            $purchaseOrder->items()->delete();

            foreach ($items as $item) {
                $purchaseOrder->items()->create([
                    'sku_id' => $item['sku_id'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'line_total' => $item['quantity'] * $item['unit_cost'],
                ]);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Purchase order updated successfully',
                'data' => $purchaseOrder->fresh('items'),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed updating purchase order', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error updating purchase order'];
        }
    }

    /**
     * @param  array<int, array{quantity: int, unit_cost: float}>  $items
     * @return array{0: float, 1: float}
     */
    private function totals(array $items, float $tax): array
    {
        $subtotal = array_reduce(
            $items,
            fn (float $carry, array $item) => $carry + ($item['quantity'] * $item['unit_cost']),
            0.0,
        );

        return [round($subtotal, 2), round($subtotal + $tax, 2)];
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(int $id): array
    {
        return $this->transition($id, PurchaseOrderStatus::Draft, PurchaseOrderStatus::Approved, 'approved');
    }

    /**
     * @return array<string, mixed>
     */
    public function markOrdered(int $id): array
    {
        DB::beginTransaction();

        try {
            $purchaseOrder = $this->model->with('items')->findOrFail($id);

            if ($purchaseOrder->status !== PurchaseOrderStatus::Approved) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Only approved purchase orders can be marked as ordered'];
            }

            $purchaseOrder->update(['status' => PurchaseOrderStatus::Ordered]);

            foreach ($purchaseOrder->items as $item) {
                $this->recalculateIncoming($purchaseOrder->warehouse_id, $item->sku_id);
            }

            DB::commit();

            return ['success' => true, 'message' => 'Purchase order marked as ordered', 'data' => $purchaseOrder->fresh()];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed marking purchase order as ordered', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error marking purchase order as ordered'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(int $id): array
    {
        DB::beginTransaction();

        try {
            $purchaseOrder = $this->model->with('items')->findOrFail($id);

            if (! in_array($purchaseOrder->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Approved, PurchaseOrderStatus::Ordered], true)) {
                DB::rollBack();

                return ['success' => false, 'message' => 'This purchase order can no longer be cancelled'];
            }

            $wasOrdered = $purchaseOrder->status === PurchaseOrderStatus::Ordered;

            $purchaseOrder->update(['status' => PurchaseOrderStatus::Cancelled]);

            if ($wasOrdered) {
                foreach ($purchaseOrder->items as $item) {
                    $this->recalculateIncoming($purchaseOrder->warehouse_id, $item->sku_id);
                }
            }

            DB::commit();

            return ['success' => true, 'message' => 'Purchase order cancelled', 'data' => $purchaseOrder->fresh()];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed cancelling purchase order', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error cancelling purchase order'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function transition(int $id, PurchaseOrderStatus $from, PurchaseOrderStatus $to, string $verb): array
    {
        DB::beginTransaction();

        try {
            $purchaseOrder = $this->model->findOrFail($id);

            if ($purchaseOrder->status !== $from) {
                DB::rollBack();

                return ['success' => false, 'message' => "Only {$from->label()} purchase orders can be {$verb}"];
            }

            $purchaseOrder->update(['status' => $to]);

            DB::commit();

            return ['success' => true, 'message' => "Purchase order {$verb}", 'data' => $purchaseOrder->fresh()];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error("Failed marking purchase order as {$verb}", [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => "Error marking purchase order as {$verb}"];
        }
    }

    /**
     * Called by GoodsReceiptService inside its own transaction (this method
     * does not open one) once the ledger entries for a receipt have been
     * posted: bump each line's received_qty, roll the header status forward
     * to PartiallyReceived/Received, and refresh incoming_qty for every SKU
     * touched.
     *
     * @param  array<int, array{purchase_order_item_id: int, received_qty: int}>  $receivedItems
     * @return array<string, mixed>
     */
    public function registerReceipt(int $id, array $receivedItems): array
    {
        $purchaseOrder = $this->model->with('items')->lockForUpdate()->findOrFail($id);

        if (! in_array($purchaseOrder->status, PurchaseOrderStatus::receivableCases(), true)) {
            return ['success' => false, 'message' => 'This purchase order cannot receive goods in its current status'];
        }

        foreach ($receivedItems as $received) {
            $item = $purchaseOrder->items->firstWhere('id', $received['purchase_order_item_id']);

            if ($item === null) {
                return ['success' => false, 'message' => 'That line does not belong to this purchase order'];
            }

            $newReceivedQty = $item->received_qty + $received['received_qty'];

            if ($newReceivedQty > $item->quantity) {
                return ['success' => false, 'message' => "Cannot receive more than the {$item->quantity} units ordered"];
            }

            $item->update(['received_qty' => $newReceivedQty]);
        }

        $purchaseOrder->refresh();
        $purchaseOrder->load('items');

        $fullyReceived = $purchaseOrder->items->every(fn ($item) => $item->received_qty >= $item->quantity);

        $purchaseOrder->update([
            'status' => $fullyReceived ? PurchaseOrderStatus::Received : PurchaseOrderStatus::PartiallyReceived,
        ]);

        foreach ($purchaseOrder->items as $item) {
            $this->recalculateIncoming($purchaseOrder->warehouse_id, $item->sku_id);
        }

        return ['success' => true, 'message' => 'Receipt registered against purchase order', 'data' => $purchaseOrder];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        DB::beginTransaction();

        try {
            $purchaseOrder = $this->model->findOrFail($id);

            if ($purchaseOrder->status !== PurchaseOrderStatus::Draft) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Only draft purchase orders can be deleted'];
            }

            $purchaseOrder->items()->delete();
            $purchaseOrder->delete();

            DB::commit();

            return ['success' => true, 'message' => 'Purchase order deleted successfully'];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed deleting purchase order', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error deleting purchase order'];
        }
    }

    /**
     * Recompute inventories.incoming_qty for one warehouse/SKU pair from
     * scratch — the sum of every outstanding (ordered minus received)
     * quantity across this SKU's open purchase orders in that warehouse.
     * Recomputing rather than incrementing avoids drift.
     */
    private function recalculateIncoming(int $warehouseId, int $skuId): void
    {
        $incoming = (int) DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.warehouse_id', $warehouseId)
            ->where('purchase_order_items.sku_id', $skuId)
            ->whereIn('purchase_orders.status', array_map(fn ($case) => $case->value, PurchaseOrderStatus::receivableCases()))
            ->selectRaw('SUM(purchase_order_items.quantity - purchase_order_items.received_qty) as outstanding')
            ->value('outstanding') ?? 0;

        InventoryFacade::setIncoming($warehouseId, $skuId, max(0, $incoming));
    }
}
