<?php

declare(strict_types=1);

namespace App\Http\Requests\StockTransfer;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateStockTransferRequest extends FormRequest
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
            'source_warehouse_id' => ['required', 'integer', 'exists:warehouses,id', 'different:destination_warehouse_id'],
            'destination_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'transfer_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku_id' => ['required', 'integer', 'exists:skus,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source_warehouse_id.different' => 'Source and destination warehouses must be different.',
            'items.required' => 'At least one line item is required.',
            'items.min' => 'At least one line item is required.',
        ];
    }
}
