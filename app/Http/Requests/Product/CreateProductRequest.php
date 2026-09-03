<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use App\Enums\ProductType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateProductRequest extends FormRequest
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
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'name' => ['required', 'string', 'max:255'],
            'product_type' => ['required', Rule::enum(ProductType::class)],
            'model_number' => ['nullable', 'string', 'max:100'],
            'model_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'launch_date' => ['nullable', 'date'],
            'end_of_life_date' => ['nullable', 'date', 'after_or_equal:launch_date'],
            'status' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.required' => 'A category is required.',
            'category_id.exists' => 'The selected category does not exist.',
            'brand_id.exists' => 'The selected brand does not exist.',
            'name.required' => 'A product name is required.',
            'end_of_life_date.after_or_equal' => 'End-of-life date cannot be before the launch date.',
        ];
    }
}
