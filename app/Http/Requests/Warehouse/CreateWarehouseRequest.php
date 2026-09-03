<?php

declare(strict_types=1);

namespace App\Http\Requests\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

final class CreateWarehouseRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:50', 'unique:warehouses,code'],
            'address' => ['nullable', 'string', 'max:500'],
            'status' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'A warehouse name is required.',
            'code.required' => 'A warehouse code is required.',
            'code.unique' => 'This warehouse code is already in use.',
        ];
    }
}
