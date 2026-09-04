<?php

namespace App\Models;

use Domain\Services\BuyabansSyncService\BuyabansSyncService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One attempt to pull a resource from the BuyAbans back office.
 *
 * Written only by {@see BuyabansSyncService}. Failures are rows, not just log
 * lines, because a sync that quietly stopped succeeding is the failure mode
 * that rots every forecast downstream without anything visibly breaking — it
 * has to be answerable from the UI, not from a log file.
 *
 * @property int $id
 * @property string $stage
 * @property string $status
 * @property int $records_fetched
 * @property int $records_written
 * @property int $pages
 * @property string|null $grain
 * @property Carbon|null $from_date
 * @property Carbon|null $to_date
 * @property string|null $message
 * @property array<string, mixed>|null $summary
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'stage', 'status', 'records_fetched', 'records_written', 'pages',
    'grain', 'from_date', 'to_date', 'message', 'summary', 'started_at',
    'finished_at',
])]
class BuyabansSyncRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
