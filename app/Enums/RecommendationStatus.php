<?php

namespace App\Enums;

use Domain\Services\InventoryRecommendationService\InventoryRecommendationService;

/**
 * app_plan.md §52's status pipeline. `New`/`Reviewed` are "open" — still
 * subject to being replaced or removed the next time the engine runs.
 * `Accepted`/`Modified`/`Rejected`/`Completed` are a human decision
 * (app_plan.md §53) and are never touched again by
 * {@see InventoryRecommendationService::generate()}.
 */
enum RecommendationStatus: string
{
    case New = 'NEW';
    case Reviewed = 'REVIEWED';
    case Accepted = 'ACCEPTED';
    case Modified = 'MODIFIED';
    case Rejected = 'REJECTED';
    case Completed = 'COMPLETED';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Reviewed => 'Reviewed',
            self::Accepted => 'Accepted',
            self::Modified => 'Modified',
            self::Rejected => 'Rejected',
            self::Completed => 'Completed',
        };
    }

    /**
     * "Open" recommendations are still owned by the engine — a future
     * {@see InventoryRecommendationService::generate()}
     * run may update or remove them. Anything else is a recorded human
     * decision and is left alone.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::New, self::Reviewed => true,
            self::Accepted, self::Modified, self::Rejected, self::Completed => false,
        };
    }
}
