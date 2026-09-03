<?php

declare(strict_types=1);

namespace App\Http\Requests\Sku;

use Illuminate\Foundation\Http\FormRequest;

final class CreateSkuRequest extends FormRequest
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
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'sku' => ['required', 'string', 'max:100', 'unique:skus,sku'],
            'barcode' => ['nullable', 'string', 'max:100', 'unique:skus,barcode'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'boolean'],
            'first_stock_date' => ['nullable', 'date'],
            'last_stock_date' => ['nullable', 'date', 'after_or_equal:first_stock_date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required' => 'A product is required.',
            'sku.required' => 'A SKU code is required.',
            'sku.unique' => 'This SKU code is already in use.',
            'barcode.unique' => 'This barcode is already in use.',
            'selling_price.min' => 'Selling price cannot be negative.',
            'cost_price.min' => 'Cost price cannot be negative.',
        ];
    }
}
