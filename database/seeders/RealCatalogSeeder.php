<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\SupplierSku;
use App\Models\Warehouse;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pulls a real product catalog (categories, brands, products, SKUs) from the
 * `buyabans_staging3` database — a real Sri Lankan appliance retailer's
 * staging environment, reachable on the same local MySQL server — instead
 * of Faker-generated names. Also creates real regional warehouses (from
 * that same database's `warehouses` table) and a hand-picked set of
 * realistic supplier distributors.
 *
 * Assigns each product a lifecycle (established/new/declining/end-of-life)
 * up front, since `launch_date`/`end_of_life_date` are Product columns —
 * {@see HistoricalTransactionSeeder} reads this lifecycle back out to shape
 * each SKU's demand history so Phase 6/8/10's maturity classification,
 * ageing risk and successor detection all have real, varied signal to work
 * with, not just steady-state data.
 */
class RealCatalogSeeder extends Seeder
{
    /** Real category IDs in buyabans_staging3, capped at this many products each. */
    private const CATEGORY_IDS = [92, 136, 8, 134, 115, 113, 10, 193, 398, 11, 141, 114, 135, 581, 104, 132];

    private const MAX_PRODUCTS_PER_CATEGORY = 10;

    /** Real warehouse ids in buyabans_staging3.warehouses to import. */
    private const WAREHOUSE_IDS = [1, 2, 3, 4, 5, 13];

    /**
     * Brand label => distributor name. Brands not listed here fall back to
     * a generic "{brand} Authorised Distributor" supplier, still realistic,
     * just not hand-curated.
     */
    private const BRAND_SUPPLIERS = [
        'LG' => 'LG Electronics Lanka (Pvt) Ltd',
        'Whirlpool' => 'National Distributors (Whirlpool)',
        'Ignis' => 'National Distributors (Whirlpool)',
        'Abans' => 'Abans Direct Import Division',
        'Panasonic' => 'Softlogic Distribution (Pvt) Ltd',
        'Toshiba' => 'Softlogic Distribution (Pvt) Ltd',
        'Philips' => 'Hayleys Consumer Products',
        'Elba' => 'Hayleys Consumer Products',
        'Haier' => 'Metropolitan Traders (Pvt) Ltd',
        'Sanford' => 'Metropolitan Traders (Pvt) Ltd',
        'Electrolux' => 'Brown & Company Distribution',
        'WestingHouse' => 'Brown & Company Distribution',
        'Premier' => 'Premier Appliances Lanka',
        'JVC' => 'Regnis Lanka (Pvt) Ltd',
        'Konka' => 'Regnis Lanka (Pvt) Ltd',
    ];

    private const DEFAULT_SUPPLIER = 'General Appliance Importers (Pvt) Ltd';

    public function run(): void
    {
        $this->seedCatalog();

        $this->info('Warehouses: '.Warehouse::count());
        $this->info('Suppliers: '.Supplier::count());
        $this->info('Categories: '.Category::count());
        $this->info('Brands: '.Brand::count());
        $this->info('Products: '.Product::count());
        $this->info('SKUs: '.Sku::count());
    }

    /**
     * These progress-log calls only ever run inside `run()`, which only
     * executes via Laravel's own `db:seed` command resolution (never via
     * {@see seedCatalog()}, which is what a direct `new self()` caller
     * like `HistoricalTransactionSeeder` actually uses) — so `$command`
     * is always set at this point.
     */
    private function info(string $message): void
    {
        $this->command->info($message);
    }

    /**
     * The real entry point — {@see HistoricalTransactionSeeder} calls this
     * directly (rather than going through `run()`) to get the created
     * models and each SKU's assigned lifecycle back in memory, no
     * round-trip through a throwaway database table needed.
     *
     * @return array{
     *     warehouses: array<int, Warehouse>,
     *     suppliers: array<string, Supplier>,
     *     skus: list<array{sku: Sku, lifecycle: string}>,
     * }
     */
    public function seedCatalog(): array
    {
        $warehouses = $this->seedWarehouses();
        $suppliers = $this->seedSuppliers();
        $categories = $this->seedCategories();
        $rows = $this->fetchRealProducts();
        $brands = $this->seedBrands($rows);
        $skus = $this->seedProductsAndSkus($rows, $categories, $brands, $suppliers);

        return ['warehouses' => $warehouses, 'suppliers' => $suppliers, 'skus' => $skus];
    }

    /**
     * @return array<int, Warehouse> keyed by buyabans warehouse id
     */
    private function seedWarehouses(): array
    {
        $rows = DB::select('
            SELECT id, name, location_code
            FROM buyabans_staging3.warehouses
            WHERE id IN ('.implode(',', self::WAREHOUSE_IDS).')
        ');

        $warehouses = [];

        foreach ($rows as $row) {
            $warehouses[$row->id] = Warehouse::create([
                'name' => trim((string) $row->name),
                'code' => $row->location_code ?: 'WH-'.$row->id,
                'address' => null,
                'status' => true,
            ]);
        }

        return $warehouses;
    }

    /**
     * @return array<string, Supplier> keyed by supplier company name
     */
    private function seedSuppliers(): array
    {
        $names = array_unique([...array_values(self::BRAND_SUPPLIERS), self::DEFAULT_SUPPLIER]);
        $suppliers = [];

        foreach ($names as $name) {
            // Bigger, established distributors (importing large appliances)
            // realistically carry longer lead times than a generic importer.
            $leadTime = $name === self::DEFAULT_SUPPLIER
                ? random_int(12, 20)
                : random_int(20, 42);

            $suppliers[$name] = Supplier::create([
                'name' => $name,
                'status' => true,
                'default_lead_time_days' => $leadTime,
                'minimum_order_value' => random_int(50, 400) * 1000,
            ]);
        }

        return $suppliers;
    }

    /**
     * @return array<int, Category> keyed by buyabans category id
     */
    private function seedCategories(): array
    {
        $rows = DB::select('
            SELECT ct.category_id, ct.name
            FROM buyabans_staging3.category_translations ct
            WHERE ct.locale = "en" AND ct.category_id IN ('.implode(',', self::CATEGORY_IDS).')
        ');

        $categories = [];

        foreach ($rows as $row) {
            $name = trim((string) $row->name);

            $categories[$row->category_id] = Category::create([
                'parent_id' => null,
                'name' => $name,
                'code' => Str::slug($name),
                'status' => true,
            ]);
        }

        return $categories;
    }

    /**
     * @return list<object{category_id: int, sku: string, product_name: string, price: float, brand_label: ?string}>
     */
    private function fetchRealProducts(): array
    {
        $categoryList = implode(',', self::CATEGORY_IDS);

        return array_values(DB::select('
            SELECT category_id, sku, product_name, price, brand_label FROM (
                SELECT
                    pc.category_id,
                    pf.sku,
                    pf.name AS product_name,
                    pf.price,
                    aot.label AS brand_label,
                    ROW_NUMBER() OVER (PARTITION BY pc.category_id ORDER BY pf.price DESC) AS rn
                FROM buyabans_staging3.product_categories pc
                JOIN buyabans_staging3.products p ON p.id = pc.product_id
                JOIN buyabans_staging3.product_flat pf ON pf.product_id = p.id AND pf.locale = "en"
                LEFT JOIN buyabans_staging3.product_attribute_values pav ON pav.product_id = p.id AND pav.attribute_id = 25
                LEFT JOIN buyabans_staging3.attribute_options ao ON ao.id = pav.integer_value
                LEFT JOIN buyabans_staging3.attribute_option_translations aot ON aot.attribute_option_id = ao.id AND aot.locale = "en"
                WHERE pc.category_id IN ('.$categoryList.')
                    AND pf.type = "simple" AND pf.status = 1 AND pf.web_available = 1 AND pf.price > 0
            ) ranked
            WHERE rn <= '.self::MAX_PRODUCTS_PER_CATEGORY.'
            ORDER BY category_id, rn
        '));
    }

    /**
     * @param  list<object{brand_label: ?string}>  $rows
     * @return array<string, Brand> keyed by brand label
     */
    private function seedBrands(array $rows): array
    {
        $labels = array_unique(array_filter(array_map(fn ($row) => $row->brand_label, $rows)));
        $brands = [];

        foreach ($labels as $label) {
            $brands[$label] = Brand::create([
                'name' => $label,
                'code' => Str::slug($label),
                'status' => true,
            ]);
        }

        return $brands;
    }

    /**
     * @param  list<object{category_id: int, sku: string, product_name: string, price: float, brand_label: ?string}>  $rows
     * @param  array<int, Category>  $categories
     * @param  array<string, Brand>  $brands
     * @param  array<string, Supplier>  $suppliers
     * @return list<array{sku: Sku, lifecycle: string}>
     */
    private function seedProductsAndSkus(array $rows, array $categories, array $brands, array $suppliers): array
    {
        $today = now()->startOfDay();
        $historyStart = $today->copy()->subYears(3);
        $result = [];
        $seenSkus = [];

        foreach ($rows as $row) {
            $category = $categories[$row->category_id] ?? null;

            if ($category === null) {
                continue;
            }

            // A real product can sit in more than one buyabans category
            // (e.g. cross-listed under a promo category too) — this
            // schema's Product has exactly one category, so keep only the
            // first (highest-price-ranked) occurrence and skip the rest.
            if (isset($seenSkus[$row->sku])) {
                continue;
            }
            $seenSkus[$row->sku] = true;

            $brand = $row->brand_label !== null ? ($brands[$row->brand_label] ?? null) : null;

            [$lifecycle, $launchDate, $endOfLifeDate] = $this->rollLifecycle($historyStart, $today);

            $product = Product::create([
                'category_id' => $category->id,
                'brand_id' => $brand?->id,
                'name' => Str::limit(trim($row->product_name), 250, ''),
                'product_type' => 'simple',
                'model_number' => null,
                'model_year' => null,
                'launch_date' => $launchDate->toDateString(),
                'end_of_life_date' => $endOfLifeDate?->toDateString(),
                'status' => true,
            ]);

            $price = (float) $row->price;
            $costPrice = round($price * 0.68, 2);

            $sku = Sku::create([
                'product_id' => $product->id,
                'product_variant_id' => null,
                'sku' => $row->sku,
                'barcode' => null,
                'cost_price' => $costPrice,
                'selling_price' => $price,
                'status' => true,
                'first_stock_date' => $launchDate->toDateString(),
                'last_stock_date' => $endOfLifeDate?->toDateString(),
            ]);

            $supplierName = $row->brand_label !== null
                ? (self::BRAND_SUPPLIERS[$row->brand_label] ?? self::DEFAULT_SUPPLIER)
                : self::DEFAULT_SUPPLIER;
            $supplier = $suppliers[$supplierName];

            SupplierSku::create([
                'supplier_id' => $supplier->id,
                'sku_id' => $sku->id,
                'supplier_sku' => null,
                'unit_cost' => $costPrice,
                'minimum_order_qty' => $price >= 200000 ? random_int(1, 3) : random_int(3, 15),
                'order_multiple' => $price >= 200000 ? 1 : random_int(1, 5),
                'expected_lead_time_days' => null,
                'is_primary' => true,
                'status' => true,
            ]);

            $result[] = ['sku' => $sku, 'lifecycle' => $lifecycle];
        }

        return $result;
    }

    /**
     * @return array{0: string, 1: CarbonInterface, 2: CarbonInterface|null}
     */
    private function rollLifecycle(CarbonInterface $historyStart, CarbonInterface $today): array
    {
        $roll = random_int(1, 100);

        return match (true) {
            $roll <= 5 => [
                'end_of_life',
                $historyStart,
                $today->copy()->subDays(random_int(30, 75)),
            ],
            $roll <= 15 => [
                'declining',
                $historyStart,
                null,
            ],
            $roll <= 27 => [
                'new',
                $today->copy()->subDays(random_int(3, 100)),
                null,
            ],
            default => [
                'established',
                $historyStart,
                null,
            ],
        };
    }
}
