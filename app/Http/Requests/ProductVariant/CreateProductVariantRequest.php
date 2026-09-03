<?php

declare(strict_types=1);

namespace App\Http\Requests\ProductVariant;

use Illuminate\Foundation\Http\FormRequest;

final class CreateProductVariantRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'boolean'],
            'attribute_values' => ['nullable', 'array'],
            'attribute_values.*.attribute_id' => ['required', 'integer', 'exists:attributes,id'],
            'attribute_values.*.attribute_value_id' => ['required', 'integer', 'exists:attribute_values,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required' => 'A product is required.',
            'product_id.exists' => 'The selected product does not exist.',
            'name.required' => 'A variant name is required.',
        ];
    }
}
