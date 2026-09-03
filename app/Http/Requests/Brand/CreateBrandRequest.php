<?php

declare(strict_types=1);

namespace App\Http\Requests\Brand;

use Illuminate\Foundation\Http\FormRequest;

final class CreateBrandRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:50', 'unique:brands,code'],
            'status' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'A brand name is required.',
            'code.required' => 'A brand code is required.',
            'code.unique' => 'This brand code is already in use.',
        ];
    }
}
