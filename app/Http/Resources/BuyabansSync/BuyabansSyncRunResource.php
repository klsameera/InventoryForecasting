<?php

declare(strict_types=1);

namespace App\Http\Resources\BuyabansSync;

use App\Models\BuyabansSyncRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BuyabansSyncRun
 */
final class BuyabansSyncRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stage' => $this->stage,
            'status' => $this->status,
            'records_fetched' => (int) $this->records_fetched,
            'records_written' => (int) $this->records_written,
            'pages' => (int) $this->pages,
            'grain' => $this->grain,
            'from_date' => $this->from_date?->toDateString(),
            'to_date' => $this->to_date?->toDateString(),
            'message' => $this->message,
            'summary' => $this->summary,
            'started_at' => $this->started_at?->toDateTimeString(),
            'finished_at' => $this->finished_at?->toDateTimeString(),

            // Computed here rather than in the page, so the listing can sort
            // and read duration without every row doing date maths in JS.
            'duration_seconds' => $this->started_at && $this->finished_at
                ? $this->started_at->diffInSeconds($this->finished_at)
                : null,
        ];
    }
}
