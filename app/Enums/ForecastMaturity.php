<?php

namespace App\Enums;

use Domain\Services\ForecastMaturityService\ForecastMaturityService;

/**
 * app_plan.md §28's six forecast maturity states. Computed fresh on every
 * forecast run by {@see ForecastMaturityService}
 * — never persisted, so it always reflects the SKU's current sales history,
 * not a stale snapshot from whenever it was last classified.
 */
enum ForecastMaturity: string
{
    case ColdStart = 'COLD_START';
    case Early = 'EARLY';
    case Established = 'ESTABLISHED';
    case Mature = 'MATURE';
    case Declining = 'DECLINING';
    case EndOfLife = 'END_OF_LIFE';

    public function label(): string
    {
        return match ($this) {
            self::ColdStart => 'Cold start',
            self::Early => 'Early',
            self::Established => 'Established',
            self::Mature => 'Mature',
            self::Declining => 'Declining',
            self::EndOfLife => 'End of life',
        };
    }
}
