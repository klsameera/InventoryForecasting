<?php

declare(strict_types=1);

namespace App\Http\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateSupplierRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'boolean'],
            'default_lead_time_days' => ['required', 'integer', 'min:0'],
            'minimum_order_value' => ['nullable', 'numeric', 'min:0'],
            'supplier_skus' => ['nullable', 'array'],
            'supplier_skus.*.id' => ['nullable', 'integer', 'exists:supplier_skus,id'],
            'supplier_skus.*.sku_id' => ['required', 'integer', 'exists:skus,id'],
            'supplier_skus.*.supplier_sku' => ['nullable', 'string', 'max:255'],
            'supplier_skus.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'supplier_skus.*.minimum_order_qty' => ['nullable', 'integer', 'min:1'],
            'supplier_skus.*.order_multiple' => ['nullable', 'integer', 'min:1'],
            'supplier_skus.*.expected_lead_time_days' => ['nullable', 'integer', 'min:0'],
            'supplier_skus.*.is_primary' => ['nullable', 'boolean'],
            'supplier_skus.*.status' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'A supplier name is required.',
            'default_lead_time_days.required' => 'A default lead time is required.',
        ];
    }
}
