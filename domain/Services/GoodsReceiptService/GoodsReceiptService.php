<?php

declare(strict_types=1);

namespace Domain\Services\GoodsReceiptService;

use App\Enums\MovementType;
use App\Models\GoodsReceipt;
use Domain\Facades\PurchaseOrderFacade\PurchaseOrderFacade;
use Domain\Facades\StockMovementFacade\StockMovementFacade;
use Domain\Services\PurchaseOrderService\PurchaseOrderService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Immutable once posted — no update()/delete(). The only write path: post
 * one stock movement per line (see StockMovementService::post()), then roll
 * the receipt into the parent purchase order via
 * {@see PurchaseOrderService::registerReceipt()}.
 * See app_plan.md §20, §88.
 */
final class GoodsReceiptService
{
    public function __construct(private GoodsReceipt $model) {}

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
            ->with(['purchaseOrder:id,po_number,supplier_id', 'purchaseOrder.supplier:id,name', 'warehouse:id,name'])
            ->withCount('items')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where('receipt_number', 'like', "%{$search}%"))
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query
                ->where('warehouse_id', $warehouseId))
            ->when($filters['purchase_order_id'] ?? null, fn ($query, $poId) => $query
                ->where('purchase_order_id', $poId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?GoodsReceipt
    {
        return $this->model
            ->with(['purchaseOrder:id,po_number,supplier_id', 'purchaseOrder.supplier:id,name', 'warehouse:id,name', 'items.sku:id,sku,product_id', 'items.sku.product:id,name'])
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
            $lines = $data['items'] ?? [];
            unset($data['items']);

            $purchaseOrder = PurchaseOrderFacade::get((int) $data['purchase_order_id']);

            if ($purchaseOrder === null) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Purchase order not found'];
            }

            if ((int) $purchaseOrder->warehouse_id !== (int) $data['warehouse_id']) {
                DB::rollBack();

                return ['success' => false, 'message' => 'The receiving warehouse must match the purchase order\'s warehouse'];
            }

            $data['receipt_number'] = 'GR-'.Str::upper(Str::random(10));
            $receipt = $this->model->create($data);
            $receipt->update(['receipt_number' => 'GR-'.str_pad((string) $receipt->id, 6, '0', STR_PAD_LEFT)]);

            $receivedItems = [];

            foreach ($lines as $line) {
                $receipt->items()->create([
                    'purchase_order_item_id' => $line['purchase_order_item_id'],
                    'sku_id' => $line['sku_id'],
                    'received_qty' => $line['received_qty'],
                    'unit_cost' => $line['unit_cost'],
                ]);

                $movementResult = StockMovementFacade::post([
                    'warehouse_id' => $receipt->warehouse_id,
                    'sku_id' => $line['sku_id'],
                    'movement_type' => MovementType::PurchaseReceipt,
                    'quantity' => $line['received_qty'],
                    'unit_cost' => $line['unit_cost'],
                    'reference_type' => 'goods_receipt',
                    'reference_id' => $receipt->id,
                    'occurred_at' => $receipt->received_date,
                    'user_id' => $data['received_by'] ?? null,
                ]);

                if (! $movementResult['success']) {
                    DB::rollBack();

                    return $movementResult;
                }

                $receivedItems[] = [
                    'purchase_order_item_id' => $line['purchase_order_item_id'],
                    'received_qty' => $line['received_qty'],
                ];
            }

            $registerResult = PurchaseOrderFacade::registerReceipt($receipt->purchase_order_id, $receivedItems);

            if (! $registerResult['success']) {
                DB::rollBack();

                return $registerResult;
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Goods receipt recorded successfully',
                'data' => $receipt->fresh('items'),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed recording goods receipt', [
                'exception' => $exception->getMessage(),
                'data' => $data,
            ]);

            return ['success' => false, 'message' => 'Error recording goods receipt'];
        }
    }
}
