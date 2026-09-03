<?php

declare(strict_types=1);

namespace App\Http\Requests\ForecastRun;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateForecastRunRequest extends FormRequest
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
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer', 'exists:warehouses,id'],
            'horizon_days' => ['required', Rule::in([7, 14, 30, 60, 90, 180])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'horizon_days.required' => 'A forecast horizon is required.',
            'horizon_days.in' => 'Choose one of the supported forecast horizons.',
        ];
    }
}
