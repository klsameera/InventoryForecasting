<?php

declare(strict_types=1);

namespace App\Http\Requests\StockMovement;

use App\Enums\MovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateStockMovementRequest extends FormRequest
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
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'sku_id' => ['required', 'integer', 'exists:skus,id'],
            'movement_type' => ['required', Rule::enum(MovementType::class)->only(MovementType::manualEntryCases())],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'occurred_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'warehouse_id.required' => 'A warehouse is required.',
            'sku_id.required' => 'A SKU is required.',
            'movement_type.required' => 'A movement type is required.',
            'quantity.min' => 'Quantity must be at least 1.',
        ];
    }
}
