<?php

declare(strict_types=1);

namespace App\Http\Requests\Promotion;

use App\Enums\PromotionDiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePromotionRequest extends FormRequest
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
        $discountValueRules = ['required', 'numeric', 'min:0'];

        if ($this->input('discount_type') === PromotionDiscountType::Percentage->value) {
            $discountValueRules[] = 'max:100';
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'discount_type' => ['required', Rule::enum(PromotionDiscountType::class)],
            'discount_value' => $discountValueRules,
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'sku_ids' => ['nullable', 'array'],
            'sku_ids.*' => ['integer', 'exists:skus,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'discount_value.max' => 'A percentage discount cannot exceed 100.',
            'end_date.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }
}
