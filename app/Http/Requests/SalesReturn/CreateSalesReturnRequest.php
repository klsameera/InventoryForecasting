<?php

declare(strict_types=1);

namespace App\Http\Requests\SalesReturn;

use App\Enums\ReturnCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateSalesReturnRequest extends FormRequest
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
            'sales_order_id' => ['required', 'integer', 'exists:sales_orders,id'],
            'return_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sales_order_item_id' => ['required', 'integer', 'exists:sales_order_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.condition' => ['required', Rule::enum(ReturnCondition::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sales_order_id.required' => 'A sales order is required.',
            'items.required' => 'At least one returned line item is required.',
            'items.min' => 'At least one returned line item is required.',
        ];
    }
}
