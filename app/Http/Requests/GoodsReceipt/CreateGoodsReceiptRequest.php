<?php

declare(strict_types=1);

namespace App\Http\Requests\GoodsReceipt;

use Illuminate\Foundation\Http\FormRequest;

final class CreateGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'purchase_order_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'received_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'exists:purchase_order_items,id'],
            'items.*.sku_id' => ['required', 'integer', 'exists:skus,id'],
            'items.*.received_qty' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'purchase_order_id.required' => 'A purchase order is required.',
            'items.required' => 'At least one received line item is required.',
            'items.min' => 'At least one received line item is required.',
        ];
    }
}
