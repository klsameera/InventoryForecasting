<?php

declare(strict_types=1);

namespace App\Http\Requests\Attribute;

use Illuminate\Foundation\Http\FormRequest;

final class CreateAttributeRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:50', 'unique:attributes,code'],
            'data_type' => ['required', 'string', 'in:text,number,boolean,select'],
            'forecast_relevant' => ['required', 'boolean'],
            'values' => ['nullable', 'array'],
            'values.*.value' => ['required', 'string', 'max:255'],
            'values.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'An attribute name is required.',
            'code.required' => 'An attribute code is required.',
            'code.unique' => 'This attribute code is already in use.',
            'values.*.value.required' => 'Each attribute value needs text.',
        ];
    }
}
