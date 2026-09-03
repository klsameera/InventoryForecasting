<?php

use App\Enums\PromotionDiscountType;
use App\Models\InventoryDailySnapshot;
use App\Models\Promotion;
use App\Models\Sku;
use App\Models\Warehouse;
use Domain\Facades\MlTrainingDataFacade\MlTrainingDataFacade;
use Domain\Services\MlTrainingDataService\MlTrainingDataService;

/**
 * @return list<array<string, string>>
 */
function readExportedRows(string $relativePath): array
{
    $handle = fopen(storage_path('app/'.$relativePath), 'r');
    $header = fgetcsv($handle);

    $rows = [];

    while (($line = fgetcsv($handle)) !== false) {
        $rows[] = array_combine($header, $line);
    }

    fclose($handle);

    return $rows;
}

beforeEach(function () {
    $this->exportPath = 'ml/testing/training_data_'.uniqid().'.csv';
});

afterEach(function () {
    $absolute = storage_path('app/'.$this->exportPath);

    if (file_exists($absolute)) {
        unlink($absolute);
    }
});

test('exports one row per snapshot with the columns the training pipeline expects', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    InventoryDailySnapshot::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'snapshot_date' => '2026-03-01',
        'sold_qty' => 7,
    ]);

    $result = MlTrainingDataFacade::export($this->exportPath);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['rows'])->toBe(1)
        ->and($result['data']['series'])->toBe(1);

    $rows = readExportedRows($this->exportPath);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['date'])->toBe('2026-03-01')
        ->and($rows[0]['sold_qty'])->toBe('7')
        ->and($rows[0]['warehouse_id'])->toBe((string) $warehouse->id)
        ->and($rows[0]['sku_id'])->toBe((string) $sku->id);
});

test('normalises the date column rather than emitting the stored time component', function () {
    // app_architecture.md §1h: 'date'-cast columns keep a 'Y-m-d 00:00:00'
    // time component. The export must hand pandas a bare 'Y-m-d' or every
    // date-keyed operation downstream shifts.
    InventoryDailySnapshot::factory()->create(['snapshot_date' => '2026-03-05']);

    MlTrainingDataFacade::export($this->exportPath);

    expect(readExportedRows($this->exportPath)[0]['date'])->toBe('2026-03-05');
});

test('flags snapshots that fall inside a promotion window for that sku', function () {
    $sku = Sku::factory()->create();

    $promotion = Promotion::factory()->create([
        'discount_type' => PromotionDiscountType::Percentage,
        'discount_value' => 20,
        'start_date' => '2026-03-10',
        'end_date' => '2026-03-20',
    ]);
    $promotion->skus()->sync([$sku->id]);

    foreach (['2026-03-09', '2026-03-15', '2026-03-21'] as $date) {
        InventoryDailySnapshot::factory()->create([
            'sku_id' => $sku->id,
            'snapshot_date' => $date,
        ]);
    }

    MlTrainingDataFacade::export($this->exportPath);

    $byDate = collect(readExportedRows($this->exportPath))->keyBy('date');

    expect($byDate['2026-03-09']['on_promotion'])->toBe('0')
        ->and($byDate['2026-03-15']['on_promotion'])->toBe('1')
        ->and($byDate['2026-03-21']['on_promotion'])->toBe('0');
});

test('exports the real percentage discount, not a zero', function () {
    // Regression: the discount_type comparison was originally written against
    // the lowercase string 'percentage' while the enum backs to 'PERCENTAGE',
    // so every promotion silently exported a 0.0 discount — an on/off flag
    // with no magnitude, and no error to notice.
    $sku = Sku::factory()->create();

    $promotion = Promotion::factory()->create([
        'discount_type' => PromotionDiscountType::Percentage,
        'discount_value' => 22,
        'start_date' => '2026-03-10',
        'end_date' => '2026-03-20',
    ]);
    $promotion->skus()->sync([$sku->id]);

    InventoryDailySnapshot::factory()->create([
        'sku_id' => $sku->id,
        'snapshot_date' => '2026-03-15',
    ]);

    MlTrainingDataFacade::export($this->exportPath);

    expect((float) readExportedRows($this->exportPath)[0]['promotion_discount'])->toBe(22.0);
});

test('a fixed-amount promotion contributes the flag but no percentage', function () {
    $sku = Sku::factory()->create();

    $promotion = Promotion::factory()->create([
        'discount_type' => PromotionDiscountType::Fixed,
        'discount_value' => 500,
        'start_date' => '2026-03-10',
        'end_date' => '2026-03-20',
    ]);
    $promotion->skus()->sync([$sku->id]);

    InventoryDailySnapshot::factory()->create([
        'sku_id' => $sku->id,
        'snapshot_date' => '2026-03-15',
    ]);

    MlTrainingDataFacade::export($this->exportPath);

    $row = readExportedRows($this->exportPath)[0];

    expect($row['on_promotion'])->toBe('1')
        ->and((float) $row['promotion_discount'])->toBe(0.0);
});

test('does not duplicate a snapshot row when two promotions overlap on the same sku', function () {
    // A date-range join against promotion_skus fans out on overlap, which
    // would duplicate the target series and corrupt training. The Service
    // resolves promotions in memory specifically to avoid that.
    $sku = Sku::factory()->create();

    foreach ([['2026-03-01', '2026-03-20'], ['2026-03-10', '2026-03-25']] as [$start, $end]) {
        $promotion = Promotion::factory()->create([
            'discount_type' => PromotionDiscountType::Percentage,
            'discount_value' => 15,
            'start_date' => $start,
            'end_date' => $end,
        ]);
        $promotion->skus()->sync([$sku->id]);
    }

    InventoryDailySnapshot::factory()->create([
        'sku_id' => $sku->id,
        'snapshot_date' => '2026-03-15',
    ]);

    $result = MlTrainingDataFacade::export($this->exportPath);

    expect($result['data']['rows'])->toBe(1)
        ->and(readExportedRows($this->exportPath))->toHaveCount(1);
});

test('counts distinct warehouse and sku pairs as separate series', function () {
    $skus = Sku::factory()->count(2)->create();
    $warehouses = Warehouse::factory()->count(2)->create();

    foreach ($warehouses as $warehouse) {
        foreach ($skus as $sku) {
            InventoryDailySnapshot::factory()->create([
                'warehouse_id' => $warehouse->id,
                'sku_id' => $sku->id,
                'snapshot_date' => '2026-03-01',
            ]);
        }
    }

    $result = MlTrainingDataFacade::export($this->exportPath);

    expect($result['data']['series'])->toBe(4)
        ->and($result['data']['rows'])->toBe(4);
});

test('succeeds with an empty file when there is no history to export', function () {
    $result = MlTrainingDataFacade::export($this->exportPath);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['rows'])->toBe(0)
        ->and($result['data']['first_date'])->toBeNull()
        ->and(readExportedRows($this->exportPath))->toBeEmpty();
});

test('defaults to the path the training pipeline reads', function () {
    expect(MlTrainingDataService::DEFAULT_RELATIVE_PATH)->toBe('ml/training_data.csv');
});

test('the artisan command reports the exported row count', function () {
    InventoryDailySnapshot::factory()->count(3)->create([
        'sku_id' => Sku::factory()->create()->id,
        'warehouse_id' => Warehouse::factory()->create()->id,
    ]);

    $this->artisan('app:export-ml-training-data', ['--path' => $this->exportPath])
        ->assertSuccessful();
});
