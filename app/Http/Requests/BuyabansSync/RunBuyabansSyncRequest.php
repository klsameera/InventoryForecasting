<?php

declare(strict_types=1);

namespace App\Http\Requests\BuyabansSync;

use App\Models\BuyabansDailyDemand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RunBuyabansSyncRequest extends FormRequest
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
            'stage' => ['nullable', Rule::in(['all', 'locations', 'categories', 'brands', 'attributes', 'products', 'stock', 'demand'])],

            // Capped well below the configured full history: this runs inside
            // a web request, and a three-year pull belongs on the console
            // command, which has no request timeout to hit.
            'days' => ['nullable', 'integer', 'min:1', 'max:400'],
            'grain' => ['nullable', Rule::in(BuyabansDailyDemand::GRAINS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stage.in' => 'Choose a valid sync stage.',
            'days.max' => 'Sync at most 400 days from this page — use the app:sync-buyabans console command for a full history pull.',
            'grain.in' => 'Grain must be warehouse, channel or national.',
        ];
    }
}
