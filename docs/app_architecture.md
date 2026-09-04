# App architecture — InventoryForecasting

Modules, models, services, data flow and the conventions that are enforced here.

Companion documents: [`app_guide.md`](app_guide.md) (what it does),
[`server_architecture.md`](server_architecture.md) (where it runs).

> The authoritative, enforced rules live in [`.ai/rules/`](../.ai/rules/). This
> document describes the codebase as it stands; where the two disagree,
> `.ai/rules/` wins and this file is out of date.

---

## 1. The layered architecture

This project uses a **Facade → Service → thin Controller** architecture. It
deliberately overrides the generic Laravel guidance that teaches Action classes
and constructor-injected services.

```
Request → Route → FormRequest → Controller → Facade → Service → Model
                                     ↓
                            Inertia page / API Resource
```

| Layer | Path | Responsibility |
| --- | --- | --- |
| Controller | `app/Http/Controllers/{Module}Controller.php` | Render Inertia, accept validated requests, call the Facade, return Resources/JSON |
| Facade | `domain/Facades/{Module}Facade/` | Static entry point; resolves the Service |
| Service | `domain/Services/{Module}Service/` | **All** business logic, transactions, logging |
| Request | `app/Http/Requests/{Module}/` | Validation rules and messages |
| Resource | `app/Http/Resources/{Module}/` | Output shaping |
| Model | `app/Models/{Module}.php` | Schema, casts, relationships |

**Hard prohibitions:** no Action classes, no repository pattern, no service
injected into a controller constructor, no new architectural layers. A
controller may not hold a query, a `DB::` call, a transaction, or inline
validation.

`Domain\` is PSR-4 mapped to `domain/` in `composer.json`. Run
`composer dump-autoload` after creating the first class in a new namespace.

### Current state of the domain layer

Phase 1 (Foundation), Phase 2 (Sales and purchasing), Phase 3 (Inventory
analytics), Phase 4 (the data pipeline), Phase 5 (an ML forecast
**scaffold** — see §1i, this is not a trained model), Phase 6 (cold-start
fallback forecasting — see §1j), Phase 7 (the inventory decision engine —
see §1k), Phase 8 (ageing prevention, extending the same engine — see §1l),
Phase 9 (multi-location optimization, extending the same engine again — see
§1m) and Phase 10 (advanced intelligence — six real, independently-scoped
signals; see §1n) are built — **all ten phases of `app_plan.md` are now
complete.** Thirty modules under `domain/Services/` /
`domain/Facades/`, following the pattern above exactly:

| Module | Table | Route prefix | Notes |
| --- | --- | --- | --- |
| `Category` | `categories` | `/category` | self-referencing `parent_id`; cycle guard, delete blocked if it has children/products |
| `Brand` | `brands` | `/brand` | |
| `Attribute` | `attributes` | `/attribute` | owns `attribute_values` — see §1a |
| `Product` | `products` | `/product` | belongs to Category, Brand |
| `ProductVariant` | `product_variants` | `/product-variant` | belongs to Product; tagged with attribute values — see §1a |
| `Sku` | `skus` | `/sku` | belongs to Product, optionally ProductVariant |
| `Warehouse` | `warehouses` | `/warehouse` | |
| `Inventory` | `inventories` | `/inventory` | **read-only** — see §1b |
| `StockMovement` | `stock_movements` | `/stock-movement` | **append-only ledger**, and the shared write path for every Phase 2 module — see §1b, §1e |
| `Supplier` | `suppliers` | `/supplier` | owns `supplier_skus` — see §1a |
| `PurchaseOrder` | `purchase_orders` | `/purchase-order` | status workflow, editable only while Draft — see §1f |
| `GoodsReceipt` | `goods_receipts` | `/goods-receipt` | **immutable, posted-on-create** — see §1f |
| `StockTransfer` | `stock_transfers` | `/stock-transfer` | status workflow, editable only while Draft — see §1f |
| `SalesOrder` | `sales_orders` | `/sales-order` | status workflow, editable only while Draft — see §1f |
| `SalesReturn` | `sales_returns` | `/sales-return` | **immutable, posted-on-create** — see §1f |
| `InventoryBatch` | `inventory_batches` | `/inventory-batch` | **read-only** FIFO lot ledger — see §1e |
| `InventoryAnalytics` | *(none — computed)* | `/inventory-analytics` | **read-only, no migration or model** — see §1g |
| `InventoryDailySnapshot` | `inventory_daily_snapshots` | `/inventory-daily-snapshot` | **read-only + one capture action**, written by a nightly schedule — see §1h |
| `MlServiceClient` | *(none — infrastructure)* | *(none — no controller)* | HTTP client to the Python ML service, **no migration or model**, called by `ForecastRunService` only — see §1i |
| `ForecastRun` | `ml_forecast_runs` | `/forecast-run` | **`index`/`create`/`store` only** — a run is a point-in-time job record, never edited — see §1i |
| `Forecast` | `forecasts` | `/forecast` | **read-only + one score-accuracy action** — see §1i |
| `ForecastMaturity` | *(none — infrastructure)* | *(none — no controller)* | Classifies a SKU's forecast maturity from its sales history, **no migration or model, nothing persisted** — see §1j |
| `DemandProfile` | *(none — infrastructure)* | *(none — no controller)* | Builds a peer-group fallback demand series, **no migration or model, nothing persisted** — see §1j |
| `InventoryRecommendation` | `inventory_recommendations` | `/inventory-recommendation` | **`index` + `generate` + four decision actions + `central-allocation` (read-only)** — no create/edit/update/delete — see §1k, §1l, §1m |
| `ModelSelection` | *(none — infrastructure)* | *(none — no controller)* | Picks EWMA vs. seasonal-naive per SKU from real backtested accuracy, **no migration or model, nothing persisted** — see §1n |
| `SupplierPerformance` | `supplier_performance_metrics` | `/supplier-performance` | **read-only + one capture action**, written by a monthly schedule — see §1n |
| `DemandInsights` | *(none — computed)* | `/demand-insights/lost-sales`, `/demand-insights/anomalies` | **read-only, no migration or model**, two reports — see §1n |
| `ProductRelationship` | *(none — computed)* | `/product-relationship/similarity`, `/…/cannibalization`, `/…/successors` | **read-only, no migration or model**, three reports — see §1n |
| `PriceElasticity` | *(none — computed)* | `/price-elasticity` | **read-only, no migration or model** — see §1n |
| `Promotion` | `promotions` | `/promotion` | Full CRUD, owns `promotion_skus`, plus one read-only `impact` action — see §1a, §1n |

Four supporting tables (`attribute_values`, `variant_attribute_values`,
`supplier_skus`, `promotion_skus`) are **not** independent modules — no
controller, routes or pages of their own. They are written by their parent
module's Service inside the same transaction (§1a) because they only make
sense scoped to that parent. `promotion_skus` is the simplest of the four —
a plain `belongsToMany`/`sync()`, no typed pivot class, since it carries no
columns beyond the two foreign keys (§1n). Line-item tables
(`purchase_order_items`, `goods_receipt_items`, `stock_transfer_items`,
`sales_order_items`, `sales_return_items`) follow the same rule — synced by
their header's Service, never their own module.

`app/Enums/ProductType.php`, `app/Enums/MovementType.php`,
`app/Enums/PurchaseOrderStatus.php`, `app/Enums/StockTransferStatus.php`,
`app/Enums/SalesOrderStatus.php`, `app/Enums/ReturnCondition.php` and
`app/Enums/PromotionDiscountType.php` are native PHP backed enums, cast via
each model's `casts()` — a value object, not a new architectural layer.

The one other exception in the codebase is `app/Actions/Fortify/` —
`CreateNewUser` and `ResetUserPassword`. These are **not** project Action
classes; they are Fortify's required contract implementations
(`CreatesNewUsers`, `ResetsUserPasswords`), registered in
`FortifyServiceProvider`. They are not a precedent for adding Action classes
anywhere else.

### 1a. Nested child rows: Attribute values and variant attribute values

Two places accept a nested array in the same request as the parent and sync
child rows inside the parent Service's transaction, instead of being their own
module:

- **`AttributeService::store()`/`update()`** accept `values: [{id?, value,
  sort_order}]`. Existing rows not present in the incoming list are deleted;
  new ones are created. **Guard:** a value still referenced by any
  `variant_attribute_values` row cannot be removed — the sync returns a
  failure envelope instead (`'Cannot remove an attribute value that is still
  assigned to a variant'`).
- **`ProductVariant::attributeValues()`** is a `belongsToMany(AttributeValue::class,
  'variant_attribute_values')` using a typed pivot,
  `App\Models\VariantAttributeValuePivot` (via `->using()`), because the pivot
  carries an extra `attribute_id` column beyond the two foreign keys — a plain
  anonymous `Pivot` can't expose that column to Larastan. `ProductVariantService::store()`/`update()`
  accept `attribute_values: [{attribute_id, attribute_value_id}]` and call
  `$variant->attributeValues()->sync(...)`.

Both `create.tsx`/`edit.tsx` pairs (`Attribute`, `ProductVariant`) use the same
frontend pattern: a `useState` array that controls how many row-groups render
(add/remove), with each row's own inputs left uncontrolled — `values[0][value]`,
`attribute_values[0][attribute_id]` — so Laravel parses them as an array on
submit. `ProductVariant`'s attribute select is the one exception that must be
controlled (`value`/`onChange`), because choosing an attribute has to filter
which values are offered in the next select.

### 1b. Inventory and StockMovement: derived balance, append-only ledger

These two modules deliberately do not follow the standard 8-route module shape
(`app_plan.md` §14, §88):

- **`Inventory`** has **no create/store/edit/update/delete** — `index`/`all`
  only. It is a derived balance table; the UI never writes to it directly.
- **`StockMovement`** has **no edit/update/delete** — only `index` (the
  ledger) and `create`/`store` (a manual adjustment entry). Ledger rows are
  never mutated after creation (`StockMovement::UPDATED_AT = null`; the
  migration has only `created_at`).

`stock_movements.movement_type` carries all eleven types from the plan
(`PURCHASE_RECEIPT`, `SALE`, …) so the schema is stable once
Sales/Purchasing/Transfers exist and start writing movements
programmatically, but **`StockMovement/create.tsx`'s dropdown, and
`CreateStockMovementRequest`'s `Rule::enum(MovementType::class)->only(...)`,
only allow the five with a manual entry point today:** `OPENING_STOCK`,
`ADJUSTMENT_IN`, `ADJUSTMENT_OUT`, `DAMAGE`, `WRITE_OFF`
(`MovementType::manualEntryCases()`).

`StockMovementService::store()` is the **only** write path that touches an
inventory balance: it creates the ledger row, then calls
`InventoryFacade::applyMovement($warehouseId, $skuId, $quantity, $isInbound,
$unitCost)` inside the same transaction — matching the "call the other
Facade instead of duplicating logic" rule. `applyMovement()` does not open its
own transaction; it relies on the caller's. It:

- upserts the `inventories` row (creates it at zero on the SKU's first
  movement in that warehouse), locking the row (`lockForUpdate()`) first,
- **rejects an outbound movement that would take `on_hand_qty` negative**
  (`'Not enough stock on hand: N available, M requested'`) — the one
  inventory business rule this phase enforces,
- recalculates `available_qty = on_hand_qty - reserved_qty`,
- on an inbound movement with a `unit_cost`, rolls `average_cost` forward by
  weighted average: `((old_on_hand × old_avg) + (qty × unit_cost)) /
  (old_on_hand + qty)`.

`reserved_qty` and `incoming_qty` exist in the `inventories` schema (plan §13)
but stay `0` in this phase — nothing populates them until Sales/Purchasing
(a later phase) exists. This is intentional, not an oversight.

### 1c. Cross-module reference data

Create/edit forms that need another module's records for a dropdown (e.g.
`Product/create.tsx` needs Category and Brand options) get them from a small,
non-baseline Service method — `Category::options()`, `Brand::options()`,
`Product::options()`, `ProductVariant::optionsForProduct($productId)`,
`ProductVariant::allOptions()`, `Warehouse::options()`, `Sku::options()` — each
returning a lightweight `[{id, name|sku}]` list, called from the *other*
module's Controller through that module's Facade and passed as an Inertia
prop. This is why `{Module}Controller::all()` exists (per the route-name
contract) but is **not** currently called by any page — cross-module lookups
go through `options()`, not a client-side fetch to `all()`. Plain native
`<select>`, no async-search combobox; fine at foundation scale, flagged here
as a known future enhancement rather than built now.

### 1d. Why store()/update()/delete() redirect instead of returning JSON

The module-generation skill's own template shows `store()`/`update()`/`delete()`
returning `response()->json(...)`. All nine controllers here deliberately
**do not** follow that literally — they call the Facade, then
`Inertia::flash('toast', ['type' => ..., 'message' => ...])` and
`return to_route(...)` / `back()`, matching the one pattern already proven to
work in this codebase (`Settings\ProfileController::update`). Reason: Inertia's
`<Form>` / `router.delete()` require an Inertia-protocol response (a redirect
or an `Inertia::render()`); a raw `response()->json(...)` breaks them (Inertia's
client can't find its `X-Inertia` page object and falls back to a full,
unstyled page load). `all()` and `get()` remain plain `JsonResponse` per the
route-name contract — they're fine as JSON because nothing calls them through
Inertia's router (see §1c).

Every Inertia prop that wraps a **single** Eloquent record through an API
Resource — `edit()`'s `'warehouse' => new WarehouseResource($model)` — must
call `->resolve()` (`(new WarehouseResource($model))->resolve()`), not pass the
Resource object directly. A bare `JsonResource` embedded in a prop tree still
gets Laravel's default `{"data": {...}}` wrap when it's JSON-serialized
(`->resolve()` bypasses that). Passing it unresolved silently produces
`{warehouse: {data: {...}}}` on the frontend instead of `{warehouse: {...}}`
— every field reads as `undefined`. Collection listings avoid this because
`->through(fn ($m) => (new XResource($m))->resolve())` already calls
`resolve()` per row.

Every `index()`/`all()` filters array passed as an Inertia prop is cast with
`(object) $filters` before it reaches `Inertia::render()`. PHP's
`$request->only([...])` returns `[]` (not `['search' => null, ...]`) when none
of those query keys are present, and an empty PHP array JSON-encodes as `[]`,
not `{}`. On the frontend, accessing a property on an array (`filters.sort`)
resolves to `Array.prototype.sort` instead of `undefined` — which then leaks
the native function's `toString()` into the next `router.get()` query string.
`(object)` guarantees object serialization even when `$filters` is empty.

### 1e. The shared stock-affecting write path (Phase 2)

Every module that moves stock — `GoodsReceipt`, `SalesOrder::confirm()`,
`StockTransfer::dispatch()`/`receive()`, `SalesReturn`, and Phase 1's manual
`StockMovement` adjustments — funnels through the same two calls, inside its
own transaction:

1. **`StockMovementFacade::post(array $data)`** — the real ledger write.
   `StockMovementService::store()` (the manual-adjustment UI's path) is now a
   thin wrapper: it checks `MovementType::manualEntryCases()`, opens a
   transaction, and calls `post()`. `post()` itself takes **no transaction** —
   it relies on the caller already being in one, matching
   `InventoryService::applyMovement()`'s convention — and accepts any
   `MovementType`, not just the manual-entry five. It creates the
   `stock_movements` row, calls `InventoryFacade::applyMovement()` for the
   aggregate balance, then:
   - if inbound with a `unit_cost`: calls `InventoryBatchFacade::receive()`
     to open a new FIFO lot.
   - if outbound: calls `InventoryBatchFacade::consumeFifo()`, which decrements
     the oldest open lots first. If the caller didn't supply a `unit_cost`
     (true for Sales, Transfers, and Damage/WriteOff — none of them know a
     cost up front), `post()` **backfills** the movement's `unit_cost` from
     whatever FIFO consumption actually cost, and returns it as
     `'batch_cost'` in the envelope. `SalesOrderService::confirm()` uses this
     to set `sales_order_items.cost` for margin reporting.
2. **`InventoryBatchService`** (`domain/Services/InventoryBatchService/`) —
   owns `inventory_batches`, never written to outside `post()`.
   `consumeFifo()` is **best-effort**: stock that predates batch tracking (any
   Phase 1 movement recorded before this phase, or any other gap) has no lot
   to draw from, so it just returns `consumed_qty: 0` rather than blocking the
   movement — `InventoryService::applyMovement()`'s on-hand-quantity check
   remains the only authoritative stock guard.

`stock_movements.reference_type`/`reference_id` (present since Phase 1's
migration but unused until now) are populated by every Phase 2 caller —
`'goods_receipt'`, `'stock_transfer'`, `'sales_order'`, `'sales_return'` — so
the ledger can always be traced back to the document that caused it.
`StockTransferService::receive()` reads the matching `TransferOut` movement's
`unit_cost` back out of `stock_movements` (a direct `DB::table()` read, not a
Facade call — a plain lookup, not business logic) so the cost carries forward
to the destination warehouse's new batch.

### 1f. Status workflows: PurchaseOrder, StockTransfer, SalesOrder

Three modules are documents with a lifecycle, not plain CRUD rows. All three
share the same shape: `store()` always creates in the first status;
`update()`/`delete()` only succeed while still in that first status; every
other transition is its own Service method (`approve()`, `cancel()`, …) that
checks the current status before mutating it, wrapped in its own transaction.

- **`PurchaseOrder`** — Draft → Approved → Ordered → PartiallyReceived/Received,
  or Cancelled from Draft/Approved/Ordered (not once any receipt exists).
  `registerReceipt(int $id, array $receivedItems)` is called by
  `GoodsReceiptService::store()` (not opening its own transaction, same
  convention as §1e) — it bumps each line's `received_qty`, rolls the header
  status to PartiallyReceived or Received, and calls the private
  `recalculateIncoming()` to refresh `inventories.incoming_qty`.
  `recalculateIncoming()` always **recomputes the full aggregate from
  scratch** (`SUM(quantity - received_qty)` across that SKU's open POs in
  that warehouse) rather than incrementing/decrementing a running total —
  simpler and immune to drift. It persists the result via a new
  `InventoryService::setIncoming()` (a "dumb setter"; `PurchaseOrderService`
  owns the aggregation, `InventoryService` owns the write, matching the
  "only the balance's own Service writes to `inventories`" rule).
  `reserved_qty` still stays inert — the plan has no reservation/hold concept
  for sales, so there's nothing to populate it from yet.
- **`StockTransfer`** — Draft → Approved → Dispatched → Received, or Cancelled
  from Draft/Approved only (once dispatched, stock has left the source
  warehouse; there's no "return to source" flow in this phase).
  `dispatch()` posts `TRANSFER_OUT` at `source_warehouse_id`; `receive()`
  posts `TRANSFER_IN` at `destination_warehouse_id` using the cost carried
  forward per §1e.
- **`SalesOrder`** — Draft → Confirmed, or Cancelled from Draft only. A
  confirmed order has already deducted stock (app_plan.md §16, §88), so
  reversing one is a `SalesReturn`, not a cancellation — `cancel()` and
  `delete()` both reject anything past Draft.

**`SalesReturn` and `GoodsReceipt` are immutable, posted-on-create** — no
`update()`/`delete()` at all, matching `StockMovement`'s §1b shape, because
both write straight to the ledger the moment they're submitted; there's no
draft state to edit. `SalesReturn::store()` guards that the returned quantity
per line never exceeds `quantity sold − already returned`
(`SUM(sales_return_items.quantity)` for that `sales_order_item_id`). A
**Damaged**-condition line posts an inbound `SALE_RETURN` (stock physically
comes back) immediately followed by an outbound `DAMAGE` in the same
transaction — net zero on-hand change, but both events land on the ledger
separately, matching app_plan.md §17's "separately track abnormal return
rates" rather than silently discarding the stock. FIFO consumption for that
`DAMAGE` line draws from whatever batch is actually oldest — not necessarily
the batch the paired `SALE_RETURN` just opened.

**Document numbers** (`po_number`, `transfer_number`, `order_number`,
`receipt_number`, `return_number`) are generated from the row's own
auto-increment `id` (`'PO-'.str_pad($model->id, 6, '0', STR_PAD_LEFT)`), which
means the number isn't known until *after* `create()` — but the column is
`NOT NULL` with no default. **`store()` sets a random placeholder
(`'PO-'.Str::upper(Str::random(10))`) before the first `create()` call, then
immediately overwrites it with the real sequential number once the id
exists.** Skipping the placeholder and leaving the number out of the initial
`create()` call throws a NOT NULL constraint violation that Pest's
`try`/`catch`-wrapped `store()` silently turns into a `success: false`
envelope — this exact bug shipped in early Phase 2 drafts of all five
document Services and was only caught by browser testing (the automated
feature tests happened to create records via `Model::factory()`, which sets
the number explicitly, bypassing `store()` entirely). Any future document
module with an auto-numbered column must use this same placeholder-then-update
pattern.

**Cross-module reads (not writes) use the other module's Facade, not a raw
model query** — e.g. `GoodsReceiptService::store()` calls
`PurchaseOrderFacade::get()` rather than `PurchaseOrder::find()` directly, and
`SalesReturnService` calls `SalesOrderFacade::get()`. This isn't a hard rule
(a pure read isn't "business logic"), but it's the pattern followed
throughout Phase 2 for consistency, and it means a future change to what
`get()` eager-loads benefits every caller automatically.

`customer_name` on `sales_orders` is a plain nullable string, **not** a
`Customer` module. `app_plan.md` §16 references `customer_id nullable` but
never defines a `customers` table anywhere in the document, and Phase 2's own
roadmap (§78) doesn't list a Customer module — inventing one would be scope
the plan never asked for. If customer relationship management is wanted
later, it's a new module built the normal way, with its own migration
replacing this column.

### 1g. `InventoryAnalytics` — a computed report, not a CRUD module (Phase 3)

`domain/Services/InventoryAnalyticsService/InventoryAnalyticsService.php` is
the one Service in the codebase with **no migration, no model, and no
constructor-injected model** — every other Service takes `private {Model}
$model`; this one takes nothing, because there's nothing it owns. It
aggregates across tables Phases 1–2 already own (`inventories`,
`stock_movements`, `inventory_batches`, `sales_orders`/`sales_order_items`,
`supplier_skus`/`suppliers`) via `DB::table()` queries, computes everything
in PHP, and never writes anything. There's no Resource class either — a
`JsonResource` wraps an Eloquent model, and rows here are plain `stdClass`
objects assembled by the Service itself. The Controller has a single
`index()`; there is no `create`/`store`/`edit`/`update`/`delete`/`get`, and
deliberately no `all()` either (nothing needs this data outside the one
report page yet).

**What it computes, one row per `(warehouse_id, sku_id)` pair that has an
`inventories` row** (app_plan.md §79):

- **Daily sales velocity** — net units sold (`SALE` minus `SALE_RETURN`
  quantities from `stock_movements`) over a `lookback_days` window (query
  param, default 30), divided by that window. This is the one deliberate
  reading of app_plan.md §17's "Net Demand = Sales − Valid Returns" formula:
  it doesn't split by return condition, matching the plan's literal wording.
- **Days of stock** — `available_qty / daily_velocity`; `null` when velocity
  is 0 (there's no such thing as "days of stock" for an item that isn't
  moving — showing `Infinity` or a huge fake number would be worse than
  admitting it's not computable).
- **Inventory turnover** — `units_sold_period / on_hand_qty`, using the
  *current* on-hand quantity as a stand-in for "average inventory over the
  period." This is a known, documented simplification: true turnover wants
  an average of historical balances, which doesn't exist until Phase 4's
  daily snapshots are built. Revisit once that table exists.
- **Stock age** — a `remaining_qty`-weighted average of
  `inventory_batches.received_date` ages, matching app_plan.md §47 ("age
  from actual batches").
- **ABC classification** — SKUs (per warehouse) ranked by trailing revenue
  (`sales_order_items.net_amount` on Confirmed orders in the lookback
  window), cumulative-percent bucketed A (≤80%), B (≤95%), C (rest) —
  app_plan.md §46's example thresholds. Revenue is computed **per warehouse**,
  not globally, matching the grain everything else in this report uses — a
  SKU can legitimately be A in one warehouse and C in another. Items with
  zero revenue in the window are `Unclassified`, not forced into C. Note the
  method's behavior with a small or highly concentrated item set: cumulative
  ABC assigns a class based on where each item's *own* cumulative position
  lands, so if the single highest-revenue item already represents >80% of
  total revenue on its own, it lands in B or C, not A — mathematically
  correct for the textbook cumulative method, just non-obvious with few SKUs.
- **Movement speed** — `Dead` (on hand, zero net sales in the window), `Fast`
  (top tertile of daily velocity among items that sold at all), `Slow`
  (everything else that sold something), `No stock` (neither on hand nor
  sold).
- **Basic reorder point** — `daily_velocity × (lead_time_days + safety_days)`,
  app_plan.md §43's formula, using the **primary** `supplier_skus` row's
  `expected_lead_time_days` (falling back to the supplier's
  `default_lead_time_days` via `COALESCE` in the query) for lead time.
  `null` when the SKU has no primary supplier — there's nothing to compute a
  lead time from. `safety_days` is a query parameter (default 7), not a
  persisted per-SKU setting: this is deliberately the "basic" version
  app_plan.md §79 asks for, not §44's demand-variability-driven dynamic
  safety stock, which is a later, ML-adjacent refinement.

**Pagination is manual.** Because ABC class and movement speed both require
ranking across the *entire* filtered result set before any row can be
labeled, the Service computes metrics for every matching row first, then
sorts/paginates an in-memory `Collection` — `new LengthAwarePaginator($rows
->forPage($page, $perPage), $rows->count(), $perPage, $page, [...])` — rather
than paginating at the SQL level like every other module's `all()`. Fine at
foundation scale; would need reconsidering if the catalog grows into the tens
of thousands of SKU/warehouse pairs.

### 1h. `InventoryDailySnapshot` — the data pipeline (Phase 4)

One table, `inventory_daily_snapshots` (app_plan.md §15), deliberately
covers all three of Phase 4's roadmap bullets (§80: daily snapshots, demand
aggregation, stockout history) at once — its `sold_qty` column *is* the
demand aggregation, and its `stockout_flag`/`stockout_minutes` columns *are*
the stockout history. There was no reason to split these into separate
tables when the plan's own §15 schema already carries all three, and
building a second, separate aggregation table would have been redundant
with what one row per warehouse/SKU/day already expresses.

`InventoryDailySnapshotService::captureDay(CarbonInterface $date)` computes
every column by **reconstructing from the `stock_movements` ledger**, never
from a running total: closing quantity is the net signed sum of every
movement up to that day's boundary, so capturing (or re-capturing) any
date — including a backfill far in the past — is always correct, not just
the "next" date in sequence. As a performance shortcut, it looks up the
previous calendar day's own snapshot and uses its `closing_qty` as the new
day's `opening_qty` when that snapshot exists, falling back to a full
ledger replay (`balanceAsOf()`) only when it doesn't (first-ever capture for
a pair, or a backfill that predates any snapshot history).

**Written only by this Service, on two paths:** the nightly schedule
(`app:capture-inventory-snapshots`, registered in `routes/console.php` —
`routes/server_architecture.md` §6 has the cron entry) always captures
*yesterday*, never today, because a day isn't "complete" until it's over;
and the `/inventory-daily-snapshot` page's "Capture snapshot" button, which
accepts an explicit date for backfilling. Both go through the same
`captureDay()` — there's no separate manual-vs-scheduled code path to keep
in sync.

**Stockout minutes are computed by replaying that day's movements in
chronological order**, starting from `opening_qty`: every gap where the
running balance is `<= 0` between two consecutive movements (or between the
day's start/last movement and its end) adds to the total. The upper bound
for an in-progress "today" capture is `min(end of day, now())`, not
midnight-to-midnight — otherwise a same-day capture would count movements
that haven't happened yet as if they had. A SKU's very first day will
correctly show stockout time from midnight until its first inbound
movement — there genuinely was no stock before that, which is accurate
bookkeeping, not a bug.

**`inventory_value` uses the *current* `inventories.average_cost`**, not a
historical point-in-time cost — reconstructing the weighted-average cost as
it stood on an arbitrary past date would mean replaying the full averaging
algorithm from the ledger, which this phase doesn't need. Documented
simplification, same category as §1g's turnover-ratio one.

**Two real bugs were found and fixed while building this Service, both
about SQLite's `date`-cast columns, and both worth internalizing before the
next date-comparison anywhere in this codebase:**

1. **A bare `where('a_date_column', $someDateString)` (or the equivalent
   criteria array inside `updateOrCreate()`) silently never matches an
   existing row.** `'date'`-cast columns are stored as `'Y-m-d 00:00:00'`
   (SQLite keeps the time-of-day component even though the migration column
   type is `date`), so comparing against a bare `'Y-m-d'` string never
   matches — `where()` builds a strict string-equality comparison, and
   `'2026-08-14' !== '2026-08-14 00:00:00'`. This is silent, not an error:
   `updateOrCreate()`'s failed lookup just falls through to its insert path,
   which then throws a *unique constraint* violation (since the row already
   exists under the differently-formatted value) — caught by the Service's
   own `try`/`catch`, logged, and returned as `success: false`, so a
   re-capture of an already-captured date looked like it "did nothing"
   rather than erroring loudly. **Use `whereDate()`, not `where()`, for any
   comparison against a `'date'`-cast column** — it wraps the column in a
   SQL `DATE()` extraction, which is immune to the stored time component.
   Because `updateOrCreate()`'s own internal lookup can't be given
   `whereDate()` semantics, this Service does the find-then-`update()`-or-`create()`
   pattern by hand instead of calling `updateOrCreate()` at all.
2. **`CarbonInterface::diffInSeconds()` (and the `diffInDays()` Phases 2–3
   already hit) does not reliably return an absolute value** in the Carbon
   version this app has installed, when comparing a `now()`-derived
   `CarbonImmutable` against a `DB::table()` row's raw string timestamp —
   the stockout-minutes replay's two `diffInSeconds()` calls both needed
   wrapping in `abs()`. This is now the fourth time in this codebase a
   `diffIn*()` call has needed an explicit `abs()` (Phase 2's
   `InventoryBatchService`, Phase 3's `InventoryAnalyticsService`, and both
   call sites here) — **treat every `diffIn*()` result in this codebase as
   requiring `abs()` unless you have specifically verified the sign,
   rather than trusting Carbon's documented "absolute by default"
   behavior.** Also still applicable: any method parameter that receives
   "the current time" (`now()`, `now()->subDays(N)`, …) must type-hint
   `Carbon\CarbonInterface`, never the concrete `Illuminate\Support\Carbon`
   — `now()` returns `CarbonImmutable` in this app's config, and a
   concrete-`Carbon`-typed parameter throws a `TypeError` the moment
   someone passes it `now()` instead of `Carbon::parse(...)`.

### 1i. ML Forecast V1 — a labeled scaffold, not a trained model (Phase 5)

> **Superseded on the serving question — see §1q.** Trained models can now be
> served. Everything below is still an accurate record of how the Phase 5
> pipeline was built, why the statistical baselines exist, and how the schema,
> queue job and HTTP contract are shaped — all of which are unchanged. What is
> no longer true is the claim that only a baseline can run.

**Read this before touching anything under `ForecastRun`/`Forecast`/
`ml-service/`.** app_plan.md's Phase 5 (§82) assumes a trained ML model
exists to forecast demand from. This is a fresh install with no real sales
history, and the project's own "no placeholder/demo code, no invented sample
data" rule forbids faking one. The user was asked how to proceed
(`AskUserQuestion`) and chose: build the real Laravel↔Python integration and
the real `ml_*`/`forecasts` schema from §24, but stand in a **clearly-labeled
deterministic statistical baseline** (an exponentially-weighted moving
average, not ML) where a trained model would eventually plug in. Every piece
of this is production-shaped — routes, jobs, the HTTP contract, the schema —
except the actual prediction algorithm, which is honestly weak on purpose and
documented as such in `ml-service/README.md`. **Do not present this as a
finished forecaster; swapping in a real trained model is future work, not
done here.**

**The Laravel↔Python HTTP contract.** Laravel never sends raw transaction
tables over HTTP (app_plan.md §35 warns against this) — it pre-aggregates.
`MlServiceClient::requestForecast(array $pairs, int $horizonDays)`
(`domain/Services/MlServiceClient/MlServiceClient.php`) is the one Service in
this codebase besides `InventoryAnalyticsService` (§1g) with **no
constructor-injected model** — it's a pure HTTP client, nothing to own. For
each `{warehouse_id, sku_id}` pair it reads up to 180 days
(`HISTORY_DAYS`) of `daily_sold_qty` from `inventory_daily_snapshots` (§1h —
this is why Phase 4 had to exist before Phase 5 could), builds a compact
per-pair time series, and `POST`s `{horizon_days, series}` to
`config('services.ml.url')` + `/forecast/run` (`ML_SERVICE_URL`, default
`http://127.0.0.1:8090`), with an optional bearer token
(`ML_SERVICE_TOKEN`/`services.ml.token`) if the Python service is configured
to require one. A non-2xx response throws — there's no silent partial
result at the HTTP layer, only at the run level (below).

**Why forecasting runs through a queued Job, not the HTTP request.**
`ForecastRunService::store()` only creates the `ml_forecast_runs` row in
`Queued` status and dispatches `App\Jobs\RunDemandForecast` — this is the
project's **first** custom Job/Queue usage (database driver, already
configured in Phase 1). The actual work — building series for every scoped
pair, calling `MlServiceClient`, persisting one `forecasts` row per pair — is
`ForecastRunService::processRun(int $runId)`, called only from
`RunDemandForecast::handle()`, matching app_plan.md §32's "scheduled bulk
forecasting should run through background workers rather than synchronous
HTTP calls." `processRun()` deliberately does **not** wrap its work in one
outer transaction — a batch can cover many SKUs, and a failure partway
through should keep whatever forecasts already persisted rather than roll
them all back; the run's own `status`/`error_message` records exactly what
happened. On success it `firstOrCreate`s a single fixed `MlModelVersion` row
(`name='baseline-moving-average', version='v1'`) and stamps every forecast
and the run itself with its id — this is the "model" a real trained model
would eventually replace, made explicit and queryable rather than hidden in
code. On any `Throwable`, the run is marked `Failed` with `error_message` set
instead of throwing further — a bad run is data, not a 500.

**`ForecastService::scoreAccuracy()`** is a manual/scheduled action (also
`app:score-forecast-accuracy`, `dailyAt('00:30')` in `routes/console.php` —
the project's second scheduled task after §1h's snapshot capture) that finds
every `forecasts` row whose `forecast_date` has already passed and has no
`forecast_accuracy` row yet, sums actual `sold_qty` from
`inventory_daily_snapshots` over the `[forecast_date − horizon_days,
forecast_date]` window, and records the absolute/percentage error. A
forecast whose window hasn't elapsed is silently skipped, not errored — it's
simply not due yet.

**Schema notes:**

- `ml_model_versions`, `ml_forecast_runs`, `forecasts`, `forecast_accuracy`
  are the four tables from app_plan.md §24, unmodified.
- `ForecastAccuracy`'s table is `forecast_accuracy` (singular, matching the
  plan's literal name) but Eloquent's default pluralization guesses
  `forecast_accuracies` — this model needs an explicit `protected $table =
  'forecast_accuracy';` or every query silently 500s with "no such table."
  Found via `tinker`, not a test (no test happened to hit the bare model
  without the override already in place). Any future model whose table name
  doesn't cleanly pluralize needs the same explicit `$table`.
- `ForecastRunService::all()` must eager-load `.with('modelVersion:id,name,version')`
  — without it, `ForecastRunResource` silently renders `model_version: null`
  for every row (no error, just an always-empty column in the UI) because
  Eloquent doesn't lazy-load relations accessed inside a Resource on an
  already-hydrated collection the same way it would on a single fresh model.
  Caught by browser inspection, not a test — worth a test if this module
  grows.
- `ml_forecast_runs.warehouse_ids` is a nullable JSON array; `null` means
  "all warehouses with inventory," matching `ForecastRunService::pairsInScope()`'s
  `when($warehouseIds, ...)` conditional.

**The Python service** lives in `ml-service/` (FastAPI, not a Laravel path —
see `server_architecture.md` for how to run it). It has its own
`README.md` stating plainly that the baseline is not real ML. Laravel's test
suite never depends on it being running: `ForecastRunTest.php` uses
`Http::fake()`/`Queue::fake()` exclusively.

### 1j. Cold-start fallback forecasting (Phase 6)

app_plan.md §82 calls Phase 6 "one of the most important phases," and
§27's demand hierarchy (`SKU → Product → Brand+Category+Size → Category+Size
→ Category → Global`) is what a new SKU with no sales history should fall
back through instead of forecasting from nothing. Unlike Phase 5's core
problem — a *trained model* fundamentally cannot exist without real data to
train on — a hierarchical fallback is deterministic, rule-based logic that
can be built for real without fabricating anything: it aggregates whatever
historical data genuinely exists (which may honestly be nothing yet, exactly
like every other report in this app when the database is empty). So Phase 6,
unlike Phase 5, needed no `AskUserQuestion` — there was no placeholder-vs-real
conflict to resolve.

**Two new infrastructure Services, both "no migration, no model, nothing
persisted"** — the same shape as §1g's `InventoryAnalyticsService` and §1i's
`MlServiceClient`:

- **`ForecastMaturityService`** (`domain/Services/ForecastMaturityService/`)
  — `classify(int $skuId): array{maturity: ForecastMaturity, days_of_history:
  ?int, first_sale_at: ?string}`. `ForecastMaturity` is app_plan.md §28's six
  states (`ColdStart`, `Early`, `Established`, `Mature`, `Declining`,
  `EndOfLife`). Classification is deliberately simple and documented as
  such, the same category as §1g's basic reorder point:
  - `EndOfLife` takes priority over everything else, whenever the SKU's
    product `end_of_life_date` has passed — checked first, regardless of
    sales history, since an EOL item's reorder question ("should we even
    forecast this for restocking?") is different from its demand-history
    question. The underlying history-based tiers below still apply to *which
    series* an EndOfLife SKU forecasts from (see below) — the maturity label
    and the series-selection rule are deliberately independent.
  - `ColdStart` — zero `SALE` movements ever, read from `stock_movements`'
    `occurred_at` (the ledger's *business* timestamp — not `created_at`,
    which is when the row was inserted; they can differ for backfills).
  - `Early` — first sale within the last 30 days (`EARLY_THRESHOLD_DAYS`).
  - `Declining` — trailing-30-day `sold_qty` (summed across all warehouses,
    from `inventory_daily_snapshots`) is less than half the prior 30-day
    window's — a meaningful drop, not any decrease. Checked before the
    Established/Mature split, so a long-lived SKU that has recently fallen
    off a cliff is labeled by its current trend, not its age.
  - `Mature` — 365+ days of history and not declining; `Established` —
    everything else with 30+ days.
- **`DemandProfileService`** (`domain/Services/DemandProfileService/`) —
  `fallbackSeries(int $warehouseId, int $skuId, int $days): array{source:
  ForecastSource, daily_sold_qty: list<float>}`. Walks a **3-tier** hierarchy
  (narrower than §27's full 6-level one — collapsed to the tiers
  {@see ForecastSource} actually has enum cases for, see below), all scoped
  to the same warehouse as the request:
  1. **Category + size** (`ForecastSource::CategorySize`) — peer SKUs in the
     same category whose product variant is tagged with the same
     `attribute_value` under any `attributes.forecast_relevant = true`
     attribute (§1a's nested attribute-value tagging; `forecast_relevant`
     was already a Phase 1 column, unused until now — see
     `app_plan.md`'s own §5 field list). Not hardcoded to an attribute
     literally named "Size" — any attribute flagged forecast-relevant
     qualifies, so a future "Color" or "Pack size" attribute participates
     the same way without code changes.
  2. **Brand + category** (`ForecastSource::BrandCategory`) — peer SKUs
     sharing both `products.brand_id` and `category_id`, ignoring size.
  3. **Category** (`ForecastSource::Category`) — peer SKUs in the same
     category only.

  Each tier is tried in order; the first with **any** peer history wins.
  If none of the three has a single peer with any `inventory_daily_snapshots`
  row, the result is `ForecastSource::ColdStart` with an **all-zero** series
  — an honest "nothing to base this on" rather than a fabricated number,
  the same philosophy as §1g's `null` days-of-stock for a non-moving item.
  A tier's series is the **average daily `sold_qty` per peer SKU** (not a
  demand-weighted size curve — app_plan.md §30's more elaborate two-forecast
  approach) — deliberately the simplest defensible statistic, matching §29's
  own "you shouldn't necessarily hard-code these percentages; models can
  learn them." The `Global` (all-categories) and `Product` (parent-product)
  levels from §27's full hierarchy are not implemented — `ForecastSource`
  (Phase 5's enum) has no dedicated case for either, and a real trained
  model is a more honest place to add finer hierarchy levels than stretching
  this scaffold's baseline further.

**Wiring into the pipeline** — `ForecastRunService::prepareSeries()` (called
from `processRun()`, replacing the direct `MlServiceClientFacade::requestForecast()`
call Phase 5 used) classifies every pair before building its request:

- `ColdStart` → the pair's series is fully **replaced** by
  `DemandProfileFacade::fallbackSeries()`; `forecast_source` becomes
  whatever tier it found (or `ColdStart` itself if nothing did).
- `Early` → the pair's own (sparse) series is **blended** with the fallback,
  element-wise, `0.3 × own + 0.7 × fallback` (`EARLY_SKU_WEIGHT`) —
  app_plan.md §29's illustrative early-SKU weighting, simplified to one
  fixed split (this scaffold has no separate seasonality signal to weight
  in). `forecast_source` becomes `ForecastSource::Hybrid`.
- `Established` / `Mature` / `Declining` / `EndOfLife` → unchanged from
  Phase 5: the SKU's own `inventory_daily_snapshots` history is sent as-is,
  `forecast_source` stays `SkuHistory`.

**Laravel decides `forecast_source`, not Python.** `MlServiceClient` was
refactored (`buildSeries()`/`send()` both made public; `requestForecast()`
kept as a backward-compatible `send(buildSeries($pairs), ...)` wrapper) so
`ForecastRunService` can build the series, substitute/blend fallbacks in,
then call `send()` directly. Python's `ForecastResult.forecast_source`
still defaults to `"SKU_HISTORY"` (it has no way to know *why* a series was
chosen — it just runs the same baseline over whatever numbers arrive), but
`processRun()` now stamps its **own** per-pair source when persisting each
`Forecast` row rather than trusting whatever Python echoed back — this is
exactly what `ForecastRunTest.php`'s Cold-start test asserts (a fake
response hardcoding `SKU_HISTORY` still ends up persisted as `COLD_START`).

**All six `ForecastSource` values are now genuinely reachable** (Phase 5's
own docblock had noted five of six were "reserved for when category/
category-size/cold-start strategies exist" — that's this phase).

**Bug caught before it shipped:** the first draft of `DemandProfileService`'s
peer-aggregation query grouped by the raw `inventory_daily_snapshots.snapshot_date`
column directly — the same `'date'`-cast-stores-as-`'Y-m-d 00:00:00'` trap
§1h documents, which would have made every date-keyed lookup in the series
miss. Fixed the same way §1h's fix does: wrap the column in SQL `DATE(...)`
for both the `SELECT` and `GROUP BY`, never compare/group a `date`-cast
column's raw value directly. Caught during implementation, before any test
was written against it — not by a failing test.

### 1k. The inventory decision engine (Phase 7)

app_plan.md §40's formula — `Forecast + current/incoming stock + supplier
lead time + safety stock + MOQ/order multiple = Recommendation` — implemented
as `InventoryRecommendationService` (`domain/Services/InventoryRecommendationService/`),
writing `inventory_recommendations` (app_plan.md §52). This is where the
Phase 5/6 forecast pipeline finally produces an actionable output rather
than just a predicted number — **`generate()` requires a completed
`Forecast` to already exist for a pair; if nobody has ever run a forecast
for a warehouse/SKU, the pair is silently skipped, never given a fabricated
recommendation.** This is why Phase 7 could not have come before Phase 5/6.

**Only `RecommendationType::Purchase` is ever produced.** app_plan.md §51
lists nine action types; the rest (`REDUCE_PURCHASE`, `DO_NOT_REORDER`,
`TRANSFER_STOCK`, `PROMOTE`, `DISCOUNT`, `CLEARANCE`, `RETURN_TO_SUPPLIER`,
`REVIEW_PRODUCT`) are overstock/ageing-driven and reserved for Phase 8
("ageing prevention") — same reserved-value pattern as §1i/§1j's
`ForecastSource`. `overstock_risk`/`ageing_risk` likewise exist as columns
(matching §52's full schema) but stay `null`; only `stockout_risk` is
computed this phase.

**The formula, per `(warehouse_id, sku_id)` pair:**

1. `daily_rate = latest forecast's predicted_qty / horizon_days` — the most
   recently created `forecasts` row for the pair, regardless of which
   `ml_forecast_runs` batch produced it or whether that run ultimately
   succeeded (a run can have partially-persisted forecasts even if it later
   fails, per §1i — those are still real, usable predictions). No forecast,
   or a non-positive rate, means the pair is skipped.
2. **Lead time** comes from the SKU's primary `supplier_skus` row (same
   `COALESCE(expected_lead_time_days, suppliers.default_lead_time_days)`
   lookup §1g's `InventoryAnalyticsService` already uses for its own basic
   reorder point). No primary supplier means the pair is skipped — there's
   no one to buy from, so nothing is actionable.
3. **Dynamic safety stock** (app_plan.md §44) — the classic
   `z × demand_std_dev × √lead_time_days` formula, with `demand_std_dev`
   computed from real `inventory_daily_snapshots.sold_qty` over a 90-day
   window. Falls back to a flat `daily_rate × 7 days` (§1g's original
   "basic" formula) when fewer than two data points exist to compute a
   standard deviation from. **`z` is a single fixed ~1.65 (~95%) for every
   SKU** — app_plan.md §45's per-ABC-class service levels (A 98%/B 95%/C
   90%) are a natural next refinement once this engine also classifies ABC;
   today only `InventoryAnalyticsService` does that, computed per-request
   inside a paginated report rather than as a reusable per-SKU lookup.
   Documented simplification, not an oversight.
4. **Reorder point** = `ceil(daily_rate × lead_time_days + safety_stock)`.
5. **Inventory position** (app_plan.md §41) = `on_hand_qty + incoming_qty −
   reserved_qty`. `reserved_qty` stays inert (§1b) but is included in the
   formula for when it isn't.
6. **Urgency gate** — a pair only gets a recommendation if it's already at
   or projected to reach the reorder point within the supplier's own lead
   time (`days_until_reorder_point_breach <= lead_time_days`). This matches
   app_plan.md §42's own worked example almost exactly (110 available, 5/day
   demand → 22 days to stockout; 30-day lead time → act now) — the engine
   doesn't wait until a pair has *already* run out to flag it.
7. **Recommended quantity** — an order-up-to target
   (`reorder_point + daily_rate × 14 days`, a simple (s, S) review-period
   buffer so purchasing isn't ordering the bare minimum every run) minus the
   current inventory position, then rounded up to the SKU's `minimum_order_qty`
   and `order_multiple` from `supplier_skus` — Phase 2's existing MOQ/pack-size
   columns, unused by any Service until now. This is what app_plan.md §83's
   "MOQ support" and "Pack-size support" actually mean in this codebase:
   using columns that already existed, not new schema.
8. **Stockout risk** — `Critical` when expected days-until-stockout (from
   `available_qty`, not inventory position) is within the lead time,
   `Moderate` when the reorder point has been reached but there's still
   time, `None` otherwise — app_plan.md §42's three-tier reading.

**`generate()` is idempotent and respects human decisions (app_plan.md
§53).** Re-running it:

- **Updates in place** any `New`/`Reviewed` ("open") recommendation for a
  pair that's still urgent, with fresh numbers.
- **Removes** an open recommendation for a pair that's no longer urgent
  (stock got replenished, forecast changed, etc.) — the list always
  reflects current reality, the same "recomputed live" philosophy as
  `InventoryAnalyticsService`, except here the *result* is persisted rather
  than purely computed on read (persistence is what lets a human decision
  survive a re-run).
- **Never creates a second row for a pair that already has ANY row**,
  decided or not. A pair with an `Accepted`/`Modified`/`Rejected`/`Completed`
  recommendation is skipped entirely, even if it's still (or newly) urgent.
  This is a deliberate reading of §53's human override as *final*, not just
  "don't edit the numbers" — but it is also a **known Phase 7 limitation**:
  nothing in this phase closes the loop (there's no "the purchase order was
  received, this pair can be re-evaluated" signal, since "Create PO"
  integration wasn't built this phase — see below), so a decided
  recommendation is effectively terminal for that pair until a future phase
  adds a way to reopen it.

**The four decision actions** (`review`/`accept`/`modify`/`reject`,
`InventoryRecommendationController`) each go through
`InventoryRecommendationService::applyDecision()`, a small shared
transaction/log/envelope wrapper (the one deliberate abstraction in this
Service — four near-identical try/catch/transaction blocks collapsed to
one, not a new architectural layer). `accept()` copies `recommended_qty`
into `decided_qty` verbatim; `modify()` takes an explicit quantity and
optional reason from the Controller; `reject()` sets `decided_qty` to `0`.
`decided_by`/`decided_at` are stamped from `$request->user()?->id`/`now()`
— **the Controller passes the user id into the Service explicitly, matching
`ForecastRunController::store()`'s `created_by` convention, rather than the
Service calling the `Auth` facade itself** (no Service in this codebase
calls `Auth::` directly; keeping "who is the current user" resolution at
the HTTP boundary keeps the Service testable without faking auth state).

**Not built this phase, on purpose:** the "Create PO" / "Bulk Create PO"
actions app_plan.md §61's screen mockup shows. §83's own "Add:" list for
Phase 7 (stockout prediction, dynamic safety stock, reorder point,
recommended quantity/date, MOQ/pack-size/lead-time support) doesn't
actually require it — it's an example on the screen mockup, not a listed
deliverable — and building a real PO-prefill integration would have meant
extending Phase 2's `PurchaseOrder/create.tsx` to accept query-string
prefill data it doesn't support today. Accept/Modify/Reject (§53's actual
ask) are built and working; turning an accepted recommendation into a real
`PurchaseOrder` row is a natural next step, not done here.

**The nightly schedule** (`app:generate-inventory-recommendations`, added to
`routes/console.php` — the third scheduled task, see
`server_architecture.md` §6) matches app_plan.md §62's "Nightly: … Generate
recommendations." It runs 15 minutes after `app:score-forecast-accuracy` —
not because recommendations depend on that day's accuracy scoring, but to
keep the whole nightly batch in one predictable, non-overlapping sequence
(snapshots → accuracy → recommendations).

### 1l. Ageing prevention — extending the decision engine (Phase 8)

app_plan.md §84 doesn't add a new module — it extends §1k's
`InventoryRecommendationService::buildRecommendation()` with the other half
of app_plan.md §40's decision space: what to do about stock that's aging or
overstocked, not just what to buy. This is also where the `overstock_risk`/
`ageing_risk` columns (present in the schema since Phase 7, always `null`
until now) and three more of §51's `RecommendationType` values
(`ReducePurchase`, `DoNotReorder`, `Clearance`) start getting populated.

**Every pair with a forecast and an inventory row now gets ageing/overstock
signals computed, regardless of whether it's urgent for purchase** — unlike
§1k's purchase path, this doesn't need a supplier or a lead time, since
"don't buy more" and "consider clearance" require no one to buy *from*:

- **Ageing risk score** (`ageing_risk`, 0–100, app_plan.md §49) — the
  average of two of §49's eight listed inputs, the two this schema can
  compute honestly:
  - **Age component**: real `inventory_batches` age, weighted by
    `remaining_qty`, normalized so 365+ days scores 100. Recomputed here
    rather than shared with `InventoryAnalyticsService::weightedAgeByPair()`
    (§1g) — that method operates on a whole filtered report's pairs at
    once, not a single-pair lookup; duplicating the aggregation was judged
    simpler than reshaping an already-shipped report Service around this
    one's needs, the same trade-off §1k already made for the lead-time
    lookup.
  - **Demand component**: `100 − min(100, projected 90-day demand ÷
    available stock × 100)` — low future demand relative to current stock
    scores high.
  - When a pair has no open batches to age at all (predates batch
    tracking, or genuinely has none — §1e's "best-effort" FIFO caveat),
    the score is the demand component alone, not `null` — a SKU can still
    be flagged purely for having far more stock than forecasted demand
    could plausibly move.
  - **"Product lifecycle," "replacement pressure," "season" and "recent
    price changes"** (the other four of §49's eight inputs) aren't
    computable from anything this schema tracks and are deliberately not
    fabricated — a narrower, honest version of §49's fuller signal list,
    matching every other "basic version first" simplification in this
    codebase.
- **Overstock risk** (`overstock_risk`, a new `OverstockRisk` enum — same
  three tiers as `StockoutRisk`, kept as its own type rather than reused
  since the two columns have independent meanings) — app_plan.md §50's
  "months of stock" (`available_qty ÷ (daily_rate × 30)`), bucketed at 3
  and 6 months. **Zero forecasted demand with real stock on hand is
  automatically `Critical`** — dead stock with nothing projected to move it
  at all is the worst case the formula can express, and doesn't need a
  months-of-stock division by zero to say so.
- **Sell-through estimate** (not persisted as its own column, computed
  inline where needed) — the percentage of current stock app_plan.md §50's
  90-day horizon is projected to move: `min(100, projected 90-day demand ÷
  available_qty × 100)`.

**Recommendation type decision, per pair:**

1. **Urgent for purchase** (§1k's existing reorder-point-breach check) —
   if ageing risk is at or above 50, or overstock risk is Moderate/Critical,
   the purchase becomes `ReducePurchase`: sized to cover only the reorder
   point itself, with **no review-period buffer** on top (§1k's normal
   order-up-to target adds 14 days of extra coverage; piling on more of
   exactly the stock the signal is warning about would defeat the point).
   Otherwise it's a plain `Purchase`, unchanged from Phase 7.
2. **Not urgent for purchase** (including "no forecasted demand at all" and
   "no primary supplier configured," both of which §1k previously returned
   `null` for outright) — ageing/overstock is still evaluated:
   - **`Clearance`** when ageing risk is ≥75 **and** projected sell-through
     is below 30% — both a high risk score and genuinely poor odds of
     selling through, not either alone.
   - **`DoNotReorder`** when ageing risk is ≥50 or overstock risk is
     Moderate/Critical, but the stricter `Clearance` bar isn't met.
   - Otherwise, nothing — the same "not notable this run" skip Phase 7
     already had for a merely-not-urgent pair with no other concern.
3. A pair with zero `available_qty` never gets an ageing/overstock
   recommendation regardless of score — there's no stock to warn about
   ageing or clear.

**Thresholds (50/75-point risk bands, 3/6-month overstock bands, 30%
sell-through) are round numbers, not calibrated against real outcomes** —
there's no historical "did this stock actually end up dead" data yet to
calibrate against. Documented as a defensible starting point, the same
epistemic honesty as §1k's fixed service-level `z`.

**`recommended_qty` is `0` for every `DoNotReorder`/`Clearance` row** — both
types are about existing stock, not a purchase, so there's genuinely
nothing to order. `stockout_risk` is `null` on these rows for the same
reason: a pair with too much stock was never at risk of running out.

**Schema note:** `ageing_risk` stayed a plain nullable `string` column
(unchanged from the Phase 7 migration) rather than getting an `ALTER` to a
numeric type — the column had never held data before this phase, so casting
it to `integer` on the `InventoryRecommendation` model (`'ageing_risk' =>
'integer'`) was a safe, purely additive choice over a migration for a
column nothing else depends on.

**`/inventory-recommendation`'s table** now shows a **Type** column
(previously every row was silently `Purchase`, so it wasn't worth a column)
and a combined **Risks** column replacing Phase 7's single stockout-risk
one — Stockout/Overstock badges only render when not `None`, and an Ageing
badge only renders at 50+, keeping a mostly-fine SKU's row uncluttered.
Three new filters (`recommendation_type`, `overstock_risk`, plus the
existing `stockout_risk`) — no new routes, the existing `index` route just
accepts more query filters.

### 1m. Multi-location optimization — transfers and central allocation (Phase 9)

app_plan.md §85 lists four things: "warehouse demand forecasting," "branch-
specific size demand," "transfer recommendation," and "central allocation
optimization." **The first two were already true of this codebase before
Phase 9 touched anything** — every `Forecast` row has been keyed by
`(warehouse_id, sku_id)` since Phase 5 (§1i), and a `Sku` already *is* a
specific product/variant/size, so a forecast has always been both warehouse-
specific and size-specific. Phase 9 adds no code for either; it only adds
the two genuinely new pieces, both as extensions of §1k's
`InventoryRecommendationService` — no new module, same pattern §1l already
set.

**Transfer recommendation** (`RecommendationType::TransferStock`, the last
of app_plan.md §51's nine values still reserved after Phase 8): when a SKU
is short in one warehouse and has real surplus in another, `generate()` now
recommends moving stock between them instead of always buying more —
app_plan.md §85's own worked example (Colombo surplus, Kandy shortage,
transfer instead of purchasing).

- **`generate()` became two passes, not one.** Every pair's candidate
  (`buildRecommendation()`, unchanged) is built and held in memory first,
  keyed by `sku_id` then `warehouse_id`, *before* anything is persisted.
  `matchTransferOpportunities()` then runs across that whole map, since
  finding a transfer needs to see every warehouse's candidate for a SKU at
  once — something the old single-pair-at-a-time persist loop couldn't do.
  Only after that second pass does the same create/update/stale-cleanup
  logic from Phase 7 run, unchanged.
- **A source** is any `DoNotReorder`/`Clearance` candidate with real
  surplus — `available_qty` minus its own 90-day forecasted demand (the
  same subtraction `sellThroughPercent()` already does, just expressed in
  units). **A destination** is any `Purchase`/`ReducePurchase` candidate,
  with its need being the *raw* pre-MOQ/pre-order-multiple shortfall
  (`buildRecommendation()`'s `_raw_need_qty`) — a transfer isn't bound by a
  supplier's pack size, so it uses the real physical need, not the
  MOQ-rounded purchase quantity.
- **Basic version, not a solver.** Only SKUs stocked in two or more
  warehouses this run are considered at all. Destinations are matched
  largest-need-first against whichever remaining source has the largest
  surplus that still covers the need *in full* — surplus already promised
  to an earlier, bigger destination isn't available to a later one within
  the same run. **No partial transfers and no combining multiple sources**:
  if no single warehouse's surplus covers a destination's full need, that
  destination keeps its ordinary `Purchase`/`ReducePurchase` recommendation
  unchanged, not a partial transfer plus a reduced purchase. This keeps one
  destination's stock coming from exactly one source, matching what
  `StockTransfer` (§1f, the execution module a `TransferStock`
  recommendation feeds) already expects — one `source_warehouse_id`, one
  `destination_warehouse_id`.
- **`inventory_recommendations` gained one nullable column**,
  `source_warehouse_id` (FK `warehouses`, `nullOnDelete()`), set only on
  `TransferStock` rows. `buildRecommendation()`'s `$base` array now sets it
  explicitly to `null` on every candidate (not just omits it) — the same
  bug class §1l's `ageing_risk` schema note was careful about: leaving a key
  out of the array entirely would mean `$existingOpen->update($data)` never
  clears a stale `source_warehouse_id` left over from an earlier run where
  the pair *was* a transfer match. A regression test
  (`generate clears a stale transfer source once the surplus that backed it
  disappears`) exists specifically for this.
- **Nothing is reserved in the database.** A matched transfer only affects
  which candidate becomes which row during *this* `generate()` run — a
  human still has to act on the `TransferStock` recommendation by creating
  a real `StockTransfer`, the same "decision recorded, execution is a
  separate manual step" relationship §1k's `Purchase` rows already have
  with `PurchaseOrder` (neither integration was built in either phase).
- **`/inventory-recommendation`'s Warehouse column** now shows a small
  "from {source warehouse}" line under the destination's name on
  `TransferStock` rows; the Type filter gained a `TRANSFER_STOCK` option.

**Central allocation** (`InventoryRecommendationService::centralAllocation()`,
`GET /inventory-recommendation/central-allocation`, a second read-only page
on the same controller) is app_plan.md §85's "central allocation
optimization," read narrowly: someone placing one consolidated supplier
order across several warehouses needs to see the combined need and how it
splits by location, instead of reading each warehouse's `Purchase`/
`ReducePurchase` row one at a time.

- **A real aggregation of already-computed numbers, not a new optimization
  formula.** Each warehouse's `recommended_qty` still comes straight from
  `buildRecommendation()`'s own reorder-point math (§1k); this method only
  groups open (`New`/`Reviewed`) `Purchase`/`ReducePurchase` rows by
  `sku_id` and sums them. It does not weigh warehouse capacity, transport
  cost, or per-warehouse service-level trade-offs — app_plan.md doesn't
  specify a formula for any of those either, so none are fabricated.
- **Only SKUs needed in two or more warehouses appear.** A SKU needed in
  exactly one warehouse has nothing to allocate across locations — it just
  needs an ordinary purchase, already visible on the main list. This is why
  a SKU that became a `TransferStock` match (above) can legitimately
  disappear from this view entirely: with the shortage side now a transfer
  and the surplus side never a `Purchase`/`ReducePurchase` in the first
  place, there may be zero `Purchase`-type warehouses left to aggregate.
- **Paginated with a manual `LengthAwarePaginator` over a `Collection`**,
  the same shape `InventoryAnalyticsService::report()` (§1g) already uses
  for a computed, non-Eloquent-paginated report — grouping and summing has
  to happen in PHP after the query, not in SQL, since a SKU's warehouse
  count/total need only exist once its rows are grouped.

### 1n. Advanced intelligence — six independent signals (Phase 10)

app_plan.md §86 lists nine loosely-related "Later:" ideas, not one coherent
feature. Two were judged not honestly buildable without inventing something
the plan never defines (**promotion impact** needed a `promotions` concept
that didn't exist — built here — and **automatic model selection** needed a
second real forecasting algorithm to select between — also built here, so
both actually landed). The other seven split into **six shipped signals**
below (product similarity, cannibalization and successor detection share
one module) — each extends an existing engine, is a small read-only
computed report, or (Promotion alone) a full CRUD module — the same
"basic version first, no fabrication" discipline every prior phase used,
not a new architectural pattern.

**Automatic model selection** (`ModelSelectionService`/`ModelSelectionFacade`,
no controller) — the Python `ml-service` now implements **two** real,
distinct algorithms:

- `app/forecasting/baseline.py` — the existing EWMA baseline (Phase 5).
- `app/forecasting/seasonal_naive.py` (new) — day-of-week seasonal
  averaging: each of the 7 weekdays gets its own historical average, summed
  across the forecast horizon. Genuinely different from EWMA (which ignores
  weekly seasonality entirely), not a placeholder second model. Assumes the
  series' last entry is *yesterday* relative to `date.today()` — the same
  "history ending now" assumption the Laravel side's series-building
  already makes — so historical and forecast-horizon days can both be
  mapped to real weekdays.

`SeriesInput` (`ml-service/app/schemas.py`) gained an `algorithm: "ewma" |
"seasonal_naive"` field (default `"ewma"`), settable **per series**, so a
single batch request can mix algorithms across SKUs; `ForecastResult` echoes
back which one actually ran. Python has no opinion on *which* to use — it
only dispatches (`main.py`'s `_ALGORITHMS` map). The **selection** is
Laravel's: `ModelSelectionService::chooseAlgorithm(int $skuId)` looks at
real `forecast_accuracy` history joined through `forecasts.model_version_id`
for that SKU, averages `percentage_error` per algorithm, and picks the
lower — **only when both algorithms already have at least one scored
forecast for that SKU**; anything less falls back to `DEFAULT_ALGORITHM =
'ewma'`, never guessing from a one-sided comparison. Two fixed
`MlModelVersion` rows (`baseline-moving-average`, `seasonal-naive`) exist for
the same reason the original one did — this scaffold's algorithms aren't
"trained," so each registers once rather than growing a version history.

`ForecastRunService::prepareSeries()` now also stamps each series entry's
`algorithm` and returns an `$algorithmByPair` map alongside its existing
`$sourceByPair`; `processRun()` looks up the correct `model_version_id`
**per forecast row** (not once per run, as before) via
`ModelSelectionFacade::modelVersionFor($algorithm)`. The run's own
`model_version_id` is now informational only — set to whichever algorithm
covered the most pairs in that run, since a single run can genuinely mix
both. The Forecasts page (`/forecast`) shows which algorithm produced each
row (`model_version` — a small addition to `ForecastResource`/
`ForecastService::all()`'s eager-load, not a new page).

**Supplier lead-time prediction** (`SupplierPerformanceService`/
`SupplierPerformanceFacade`/`SupplierPerformanceController`, `/supplier-performance`)
— builds the `supplier_performance_metrics` table app_plan.md documents
(around line 750) but never migrated until now. The plan's own worked
example is exactly this feature — *"Supplier says 15 days… when
historically they actually take 20–25 days"* — and `capturePeriod()`
computes that real gap from actual `purchase_orders.order_date` /
`goods_receipts.received_date`, never a fabricated estimate. Six of the
plan's seven metrics are real: `ordered_qty`/`received_qty` (summed from
`purchase_order_items`), `average_lead_time_days`/`lead_time_std_dev`
(order date to each PO's *first* receipt), `on_time_percentage` (against
`purchase_orders.expected_date`, only counting POs that set one), and
`fill_rate`. **`quality_issue_rate` stays `null` permanently** — no goods
receipt in this schema records a quality/defect/rejection signal to compute
it from, the same "narrower, honest version" choice §1l made for ageing
risk. Captured **monthly** (not daily, like §1h's snapshots — a supplier's
lead time needs a real batch of orders to average over) by
`app:capture-supplier-performance {month?}`, scheduled
`monthlyOn(1, '01:00')` in `routes/console.php`, plus a manual "Capture
month" button on the page. Uses the same `whereDate()`-not-`updateOrCreate()`
re-capture pattern §1h documents — `period_start` is a `date`-cast column,
so `updateOrCreate()`'s search array would never match the stored value.

**Lost-sales estimation** and **demand anomaly detection**
(`DemandInsightsService`/`DemandInsightsFacade`/`DemandInsightsController`,
`/demand-insights/lost-sales`, `/demand-insights/anomalies`) — grouped in
one Service because both read the same `inventory_daily_snapshots` rows and
answer the same "what is demand telling us that inventory alone doesn't"
question, not because they share a formula. No migration, no model,
computed on request like `InventoryAnalyticsService` (§1g).

- **Lost sales**: for a warehouse/SKU pair with at least one stockout day in
  the window, `estimated_lost_units = Σ (stockout_minutes ÷ 1440) ×
  normal_daily_rate`, where `normal_daily_rate` is that pair's own average
  `sold_qty` over its **non-stockout** days in the same window. A pair out
  of stock for the *entire* window has no non-stockout days to establish a
  rate from and is skipped — an honest "not enough data," not a rate
  borrowed from elsewhere.
- **Anomalies**: flags individual days where a pair's `sold_qty` is at
  least 2 standard deviations from that pair's own mean over the window
  (sample std-dev, matching `InventoryRecommendationService::dynamicSafetyStock()`'s
  convention). Needs ≥2 days of history and non-zero variance to have a
  std-dev at all; a pair with zero variability has no anomalies by
  definition, never "everything is an anomaly."

**Product similarity, cannibalization and successor detection**
(`ProductRelationshipService`/`ProductRelationshipFacade`/
`ProductRelationshipController`, `/product-relationship/similarity`,
`/…/cannibalization`, `/…/successors`) — three SKU-to-SKU relationship
signals in one Service, again grouped by theme, not formula.

- **Similarity** (`similarSkus()`) surfaces the same category+size →
  brand+category → category peer-tier walk `DemandProfileService` (§1j)
  already uses internally for cold-start forecasting, but returns the peer
  SKUs themselves instead of an aggregated demand number. The tier-walk
  *shape* is duplicated, not shared — that method returns a number, this
  one returns identities — the same small-lookup-duplication trade-off §1k
  made for the lead-time lookup.
- **Cannibalization** (`cannibalizationCandidates()`) computes the Pearson
  correlation of daily `sold_qty` between every pair of SKUs **within the
  same category** over the window, flagging pairs at or below `-0.5`
  (moderate-to-strong negative) with at least 14 overlapping days of real
  history. Scoped to same-category pairs — a real business assumption
  (substitutes are usually in the same category) that also keeps this
  **O(n²)-per-category** comparison bounded; a genuine scaling limitation
  for a very large catalog, documented rather than hidden. **Correlation is
  not causation** — this surfaces candidates worth a human look, not a
  proven effect.
- **Successors** (`successorCandidates()`) pairs a SKU whose
  `ForecastMaturityService` (§1j) classification is Declining/EndOfLife
  with a Cold-start/Early SKU in the **same category and brand** — a
  heuristic built entirely from real maturity classifications, since this
  schema has no explicit "replaces" link. Classifies every candidate SKU's
  maturity (one call each) — a real N+1-shaped cost for a large catalog,
  the same category of limitation as cannibalization's O(n²); filter by
  `category_id` to keep it bounded.

**Price elasticity** (`PriceElasticityService`/`PriceElasticityFacade`/
`PriceElasticityController`, `/price-elasticity`) — estimated from real
historical price variation already sitting in `sales_order_items.unit_price`
(Phase 2); no price-history table exists or is needed. For each SKU with
**two or more** distinct confirmed-order prices in the window, computes the
ordinary-least-squares slope of `ln(quantity)` on `ln(price)` across its own
price points — the standard single-coefficient reading of a demand curve. A
SKU that has only ever sold at one price has no variation to learn from and
is excluded, not given a fabricated coefficient.

**Promotion impact** (`Promotion`/`PromotionFacade`/`PromotionService`/
`PromotionController`, `/promotion`) — the **one new full CRUD module**
this phase adds; every other Phase 10 signal extends an existing engine or
is a read-only report. A `Promotion` (name, discount type/value, date range,
notes) records a discount that ran on a set of SKUs, synced via
`promotion_skus` in the same `store()`/`update()` transaction (§1a's nested-
child-row pattern, simplified — a plain `belongsToMany`/`sync()`, no typed
pivot, since the pivot carries no extra columns). **Deliberately has no
status workflow** — "upcoming/active/ended" is derived purely from
`start_date`/`end_date` against today (`PromotionResource::state()`), not a
field someone has to remember to update, unlike `PurchaseOrderStatus`/
`StockTransferStatus`'s explicit workflows.

`PromotionService::impact()` is a fifth, read-only action beyond the
standard eight: compares each promoted SKU's real average daily demand
during `[start_date, end_date]` against an **equal-length baseline window
immediately before `start_date`**, both read from
`inventory_daily_snapshots.sold_qty`. Returns `elapsed: false` — no
computation attempted — until `end_date` has actually passed, the same
"not due yet" honesty `ForecastService::scoreAccuracy()` already has for
forecast accuracy. A SKU with zero baseline-window demand reports
`percent_change: null` rather than a divide-by-zero or a fabricated
percentage.

**A real bug found and fixed while building this phase, unrelated to any
of the six signals above**: two existing migrations
(`create_stock_transfers_table`, `create_inventory_daily_snapshots_table`)
had Laravel-auto-generated composite index names exceeding MySQL's 64-
character identifier limit (65–69 characters). SQLite has no such limit,
so this never surfaced against the Pest suite's in-memory SQLite database
— it only broke a fresh `migrate` against MySQL, and did so in a
confusing way: MySQL's `CREATE TABLE` auto-commits independent of the
surrounding `ALTER TABLE … ADD INDEX`, so the table itself would get
created and stick even though the migration as a whole failed, leaving
`migrate`'s own tracking table permanently out of sync with the real
schema on every subsequent run. Fixed by giving both indexes explicit,
short names. **Any new migration with a 3+ column composite index name
should be checked against this limit** if the project ever deploys to
MySQL — `strlen("{table}_{col1}_{col2}_{col3}_index")`.

### 1o. Sample-data seeders — `RealCatalogSeeder`, `HistoricalTransactionSeeder`

Two `database/seeders/` classes (not part of the default `db:seed` run — see
`app_guide.md`'s "Seeding realistic sample data") populate the app with a
real catalog and 3 years of internally-consistent transaction history, for
local demoing and for exercising every forecasting/analytics page with data
that has genuine patterns rather than empty states.

`RealCatalogSeeder` sources products/categories/brands/SKUs from a sibling
`buyabans_staging3` MySQL database (a real Bagisto-schema staging DB on the
same local MySQL server) via cross-database `DB::select()` — no separate
Laravel connection config needed since both databases share the same server
and `root` user. `HistoricalTransactionSeeder::run()` calls it directly
(`(new RealCatalogSeeder)->seedCatalog()`), then replays 3 years of
purchasing (periodic review plus a continuous reorder-point trigger:
`$reorderPoint = $rate * $leadTimeDays * 1.5`, to avoid unrealistically long
stockouts between ~45-day reviews), FIFO goods receipts/batches, daily sales
from the demand model below, and real past promotions.

**The demand model (`dailyRate()`).** Six factors multiply together; only the
closing `jitter()`/`poissonish()` draw is random, so every structural signal
is deterministic and reproducible:

| Factor | Method | Effect |
| --- | --- | --- |
| Per-SKU base rate | `baseRateForPrice()` | cheaper SKUs sell faster, plus per-SKU variety |
| Annual seasonality | `dailyRate()` | sinusoid peaking mid-year, AC/Fan/Air-Cooler categories only |
| Lifecycle | `dailyRate()` | new-product ramp, decline curve, end-of-life cutoff |
| Day of week | `dayOfWeekFactor()` | `DAY_OF_WEEK_FACTORS`, Sat 1.42 → Tue 0.76, summing to exactly 7.0 so total volume is unchanged |
| Calendar events | `calendarEventFactor()` | Avurudu 1.9×, Christmas 1.55×, Vesak 1.35×, Deepavali 1.25×, January lull 0.82×, month-end payday 1.28× |
| Price | `priceElasticityFactor()` | constant elasticity `(P/P_ref)^-1.2` against the SKU's present-day price |
| Promotions | `promotionFactor()` | `1 + discount × 4.0` during the window (a 20% promotion → 1.8×), then a 0.78× payback for 10 days |

**Promotions are planned before demand is generated, and this is a
deliberate reversal.** `buildPromotionPlan()` runs ahead of the sales loop
and `seedPromotions()` merely persists what it decided. The original seeder
created promotions *after* the fact, specifically so `Promotion::impact()`
could not be accused of showing a rigged result — a sound instinct that had
an unintended consequence: the promotion flag had **zero** correlation with
demand, which teaches any covariate-aware forecasting model that promotions
do not matter. That is the opposite of the real-world truth and makes the
dataset useless for training one. The lift is now modelled into the world;
`Promotion::impact()` is untouched and still measures only what the data
genuinely contains. Each template also recurs **once per year** the history
covers (12 promotions across 3 years, not 4), because one instance of a
promotion type is a single observation and nothing can generalise from it.

**Why this matters beyond realism.** Before this change the only structure in
the dataset was one annual sinusoid, and there was **no day-of-week signal at
all** — meaning `seasonal_naive` (§1n) was modelling a pattern that had never
been generated and could not beat EWMA no matter how it was scored. The
seeded data now shows a real weekly rhythm (Saturday ≈1.33× the mean vs.
Tuesday ≈0.81× as actually measured after seeding, compressed from the
designed factors by stock-availability censoring) and real festival peaks
(April ≈1.3× the annual mean, January the trough).

**Bulk-generate-then-insert, not real Services**: calling
`GoodsReceiptService`/`StockMovementService`/`SalesOrderService` once per
historical event across ~1,100 days and several hundred warehouse/SKU pairs
would mean hundreds of thousands of individual DB round trips. Instead the
seeder replicates each Service's exact end-state formula in PHP memory
(FIFO oldest-first batch consumption, the same weighted-average-cost
formula as `InventoryService::applyMovement()`, PO/GR/SO numbering from the
row's own id) and bulk-inserts via `DB::table($table)->insert($chunk)` in
dependency order, with explicit sequential primary keys computed from
`MAX(id)+1` — safe only because the seeder runs solo with no concurrent
writes, not a pattern to copy for anything that runs alongside real traffic.

**A real bug this surfaced, unrelated to the seeders themselves**: running
the queued forecast job against this real data (rather than `Http::fake()`,
which is all `ForecastRunTest.php` had ever exercised) hit an HTTP 422 from
the ML service — `ml-service/app/schemas.py`'s `SeriesInput.daily_sold_qty`
was `list[int]`, which strictly rejects the genuinely fractional series a
Cold-start/Early pair gets from `DemandProfileService`'s peer-average or
`ForecastRunService::blend()`. Fixed by widening to `list[float]` across
`schemas.py`, `baseline.py` and `seasonal_naive.py` (§1i, §1j) — the
Laravel-side contract in `MlServiceClient` already documented this
correctly; only the Python schema was too strict. See §8 of
`server_architecture.md`.

### 1p. The neural training pipeline — `MlTrainingDataService` + `ml-service/training/`

> **Its closing decision — "nothing is wired into serving" — was reversed; see
> §1q.** The training pipeline, the experiments, the staleness finding and the
> limitations below all still stand, and the synthetic-training-data caveat in
> particular still stands. Only the serving decision changed.

The first genuinely *trained* models in this project, as opposed to §1i's
labelled statistical baselines. Two are trained: **DeepAR** (probabilistic
benchmark) and a **Temporal Fusion Transformer** (the covariate-aware main
model), both via `pytorch-forecasting` on CPU.

**Read §1i first.** Everything it says about the baselines being honest
stand-ins remains true of the baselines. What changes here is that real
models now exist alongside them — and whether they are actually *better* is
a measured question with a recorded answer, not an assumption.

**The measured answer, as of 2026-08-22: a negative-binomial TFT beats the
best statistical baseline by ~8%, but only while it is freshly trained.**
Three experiments were run, and the first two gave the wrong answer for
methodological reasons worth understanding before trusting any of it.

**Experiment 1 — one held-out window (2026-07-22 → 2026-08-20).**

| Algorithm | WAPE | Bias |
| --- | --- | --- |
| `ewma` | **0.3214** | +0.063 |
| `seasonal_naive` | 0.3243 | +0.098 |
| `deepar` | 0.3741 | +0.082 |
| `tft` (negative binomial) | 0.5091 | +0.009 |
| `tft` (quantile) | 0.6278 | −0.288 |

Two findings here survive. First, **the TFT's loss was a genuine defect**:
retraining with the same negative-binomial likelihood DeepAR uses — the only
variable changed, same split, same seed, same features — improved WAPE 19%
(0.6278 → 0.5091) and removed the systematic under-forecasting (bias −0.288
→ +0.009). Second, **the window was a bad test**: it contains no promotion
and no festival, so the TFT's distinguishing covariates could contribute
nothing.

**Experiment 2 — 11 rolling windows over a full year** (`--holdout-days 365`,
training cut at 2025-08-20), which is the fair test:

| Algorithm | WAPE | Bias |
| --- | --- | --- |
| `tft` | **0.2791** | +0.036 |
| `seasonal_naive` | 0.2815 | +0.008 |
| `ewma` | 0.2925 | +0.006 |
| `deepar` | 0.3046 | +0.054 |

The TFT went from clearly last to nominally first, confirming experiment 1's
window was genuinely unfair. But +0.9% over `seasonal_naive` is noise, and
the TFT wins only 5 of 11 windows. **The pooled number is the wrong number.**

**What actually drives the result is model staleness, not promotions.**
The original hypothesis — that the TFT earns its keep in windows containing
a promotion — does not survive contact with the data:

| Correlation with TFT's margin over `seasonal_naive` | Pearson r |
| --- | --- |
| Months since the training cutoff | **−0.704** |
| Promotion SKU-days in the window | +0.156 |

The largest-promotion window (325 SKU-days) has the TFT *losing* by 2.9%,
while it wins two promotion-free windows by ~13%. Splitting by model age
instead:

| Model age | `tft` | best baseline | TFT margin |
| --- | --- | --- | --- |
| Fresh (windows 1–3) | **0.2597** | 0.2817 | **+7.8%** |
| 4–6 months stale | 0.2705 | 0.2603 | −3.9% |
| 7+ months stale | 0.2949 | 0.2869 | −2.8% |

A trained model decays as the forecast origin moves away from its training
data; the baselines cannot decay because they re-derive from the trailing
180 days on every single call. So the honest statement is conditional: **a
negative-binomial TFT retrained at least quarterly is ~8% better than the
best baseline; left alone it falls below them within about four months.**
The choice is not "which model" but "are you willing to operate a retraining
cadence" — and nothing in this application currently retrains anything.

**Nothing is wired into serving regardless** — `_ALGORITHMS` in
`ml-service/app/main.py` is unchanged and the app still serves
EWMA/seasonal-naive. The deciding reason is not the accuracy margin but
§1p's last limitation: these models are trained on `HistoricalTransactionSeeder`
output, so +7.8% measures how well a TFT recovers that seeder's own
formula. Serving it would mean shipping predictions from a model fitted to
synthetic rules. The margin justifies revisiting this the moment real sales
history exists; it does not justify shipping now.

One more result that holds across every experiment: **`deepar` is last or
near-last in almost every window.** Its inability to use past-observed
covariates (below) is the likely reason, and it is the weakest of the four
on this data.

**Day-of-week structure cancels at this grain.** §1o's enriched weekly
signal is real and strong, but a 30-day total spans ~4.3 whole weeks, so
weekday effects very nearly average out — which is why `seasonal_naive`
barely separates from `ewma` despite the data genuinely containing the
weekly pattern it models. The weekly signal should pay off at per-day grain
and at short horizons, neither of which is what `forecasts.predicted_qty`
stores.

**Point forecasts from a quantile model: use the mean, not the median.** A
quantile-loss model's natural point output is the median, and for demand
that is ~71% zeros the median is simply zero. Summing medians across a
30-day horizon predicts zero for nearly every SKU, which scores WAPE
exactly 1.0 and bias exactly −1.0 — a result that looks like catastrophic
model failure but is really the wrong statistic being read. Inventory
decisions need *expected* demand.
`training/prediction.py::expected_value_from_quantiles()` recovers E[X] by
trapezoid-integrating the quantile function over [0,1], holding the
outermost trained quantiles flat into the tails. That moved the TFT from
1.0000 to 0.6278.

`training/prediction.py` exists because `train.py` and `evaluate.py` were
reporting the *same checkpoint* differently — `evaluate.py` converted
quantiles to an expectation while `train.py` scored the raw median, so a run
could print "TFT validation: WAPE 1.0000" and then evaluate at 0.6278 with
nothing wrong. Both now go through `point_forecast()`, which decides from
`is_quantile_loss(model)` — read off the checkpoint's own loss object, never
inferred from the model's name, so a negative-binomial TFT is not
double-corrected.

The cleaner fix is to train the TFT with a count-appropriate distribution
loss (negative binomial, as DeepAR already uses) so its point prediction is
a mean by construction — which also stops the comparison confounding
architecture with loss function. `train.py --tft-loss negative_binomial`
does this; `build_datasets(count_target=...)` switches the normaliser
independently of `for_deepar`, since the normaliser follows the *loss* while
the feature narrowing follows the *architecture*.

**Training data leaves as a file, not over the HTTP contract.**
`MlTrainingDataService` (`domain/Services/MlTrainingDataService/`, no
constructor-injected model — infrastructure, same shape as §1g/§1i) writes
one CSV row per warehouse/SKU/day to `storage/app/ml/training_data.csv` via
`php artisan app:export-ml-training-data`. `MlServiceClient` (§1i) stays a
*serving* contract: a capped 180-day window for a few hundred pairs, per
app_plan.md §35. Training needs every day of every pair at once — hundreds of
thousands of rows — which would be slow and memory-hungry through JSON and
would blur a boundary worth keeping sharp.

Rows stream through a `cursor()` and are written incrementally, so memory
stays flat. Two traps the implementation deliberately avoids:

- **Promotions are resolved in memory, not with a date-range join.** Joining
  `promotion_skus` on a between-dates predicate fans out whenever two
  promotions overlap on one SKU, silently duplicating snapshot rows and
  corrupting the target series.
- **`discount_type` is compared against `PromotionDiscountType::Percentage->value`,
  not a string literal.** The enum backs to uppercase `'PERCENTAGE'`; a
  lowercase literal matched nothing and exported every discount as `0.0` —
  an on/off flag with no magnitude and no error to notice. Caught by
  inspecting the export, not by a failing test; there is now a regression
  test for it.

**Feature engineering lives in Python** (`ml-service/training/dataset.py`).
Calendar features are derived from the date rather than shipped, keeping the
export small and the derivation single-sourced. Feature roles:

| Role | Features |
| --- | --- |
| Static categorical | `warehouse_id`, `sku_id`, `category_id`, `brand_id` |
| Static real | `log_selling_price` |
| Known future | day of week, month, day of month, week of year, payday, Avurudu/Vesak/Deepavali/Christmas flags, `on_promotion`, `promotion_discount` |
| Past observed | `sold_qty` (target), `stockout_minutes`, `stockout_flag`, `available_qty`, `received_qty` |

**DeepAR and the TFT do not receive the same features, and that is a
capability difference rather than a tuning choice.** DeepAR is
autoregressive, so `pytorch-forecasting` asserts its encoder and decoder
variables match — which rules out past-observed covariates entirely. Only
the TFT can condition on the fact that last week's zero sales happened
during a stockout. This is a substantive reason the TFT is the main model
and DeepAR the benchmark, and it is why `build_datasets(for_deepar=True)`
narrows `time_varying_unknown_reals` to the target alone. It also switches
the target normaliser: DeepAR's negative-binomial likelihood needs an
uncentred, untransformed target, while the TFT's quantile loss wants a
softplus-transformed one.

**Splits are chronological, never random** — a random split lets a model see
a series' future while predicting its past, which inflates every metric. The
final 30 days are the test window, the 30 before that validation.

**Evaluation uses WAPE / MAE / Bias, not MAPE** (`training/metrics.py`,
`training/evaluate.py`). ~71% of days in this dataset have zero demand, and
MAPE is undefined at zero and explodes near it — ranking models on MAPE over
intermittent demand mostly ranks them on their behaviour in the undefined
case. WAPE is the headline; bias is reported alongside it because a model
with good WAPE and bad bias quietly builds up either stockouts or dead
stock, which WAPE alone cannot reveal. A window with no demand at all scores
`NaN` rather than a flattering `0.0`.

`evaluate.py` scores all four algorithms on the same held-out windows at the
**horizon-total** grain, because that is what `forecasts.predicted_qty`
stores and because the two baselines emit only a total. Baselines receive
exactly `MlServiceClient::HISTORY_DAYS` (180) days, the window Laravel
really sends them — scoring them on three years would benchmark a
configuration that never runs. Actuals are derived twice by independent
routes (the neural dataloader's `y` tensor, and slicing the source frame by
the prediction index) and the two are asserted equal, because a silent
misalignment there would make every number in the table meaningless while
still looking plausible.

**Rolling origins start after the *validation* cutoff, not the training
cutoff.** The window immediately following training is the one early
stopping selected the checkpoint on. Scoring there is a smaller leak than
training on it, but it still reports a figure the model was tuned against.
This was a real bug in the first draft of `evaluate.py`, caught by review
rather than by any failing test — there is nothing to fail, the numbers just
come out flatteringly.

**Window choice dominates the result, and the default split cannot show
it.** The covariates the TFT has and the baselines do not — promotions, the
festival calendar — only pay off in a window that actually contains such an
event. With the default two-horizon holdout, the single evaluable window is
the last 30 days of the dataset, which in this seeded history is late July
to late August: **no promotion and no festival**. The covariate-aware models
are judged precisely where they have least to offer, so a poor result there
is not evidence they are bad.

Fixing that is a *training* decision, not an evaluation one: a model trained
up to day T can only honestly be scored after T, so covering a seasonal
cycle means reserving one. `build_datasets(holdout_days=...)` /
`train.py --holdout-days 365` trades a third of the training data for ~11
evaluable windows spanning every festival and promotion in the history.
`evaluate.py` prints each window's promotion SKU-day count and says so
explicitly when every evaluated window contains none.

**`seasonal_naive.forecast()` gained an optional `as_of` parameter**, an
additive change that defaults to `date.today()` and preserves the previous
behaviour exactly. Every weekday in that model is resolved relative to it, so
backtesting a historical window while `as_of` silently defaulted to today
misaligned the entire weekly pattern and made the model look far worse than
it is — with no error.

**Known limitations, not fabricated around:**

- **No daily price series exists in this schema.** Demand genuinely responds
  to price in the generated world, but nothing records what a SKU's price was
  on a given past day: `skus.selling_price` is current-only and
  `sales_order_items.unit_price` exists only on days with a sale, at weekly
  grain. Price ships as a static per-SKU feature instead of a fabricated or
  forward-filled daily one.
- **Demand is censored by stockouts.** `sold_qty` is what sold, not what was
  demanded; on a stockout day the target is biased downward. `stockout_minutes`
  and `stockout_flag` ship as covariates so the TFT can condition on it, but
  this does not fully correct the censoring — that needs an explicit censored
  likelihood.
- **A genuinely new SKU still gets no useful neural forecast**, having no
  learned embedding. `NaNLabelEncoder(add_nan=True)` stops that crashing the
  split, but cold start remains §1j's peer-hierarchy problem, handled in
  Laravel.
- **These models are trained on synthetic data.** Every accuracy figure they
  produce describes how well they recover `HistoricalTransactionSeeder`'s
  generative process, not real-world demand. That is a genuine test of the
  pipeline and of relative model capability; it is **not** evidence of
  production accuracy, and must never be presented as such.

---

### 1q. Serving the trained models (`neural.py`) — the switch actually flipped

**§1i and §1p both predate this and say the trained models are not served. They
now are.** §1i remains an accurate record of how Phase 5 was built and why the
baselines exist; §1p remains the accurate record of how the models were trained
and benchmarked. This section is what changed.

**The decision reversed, and on whose authority.** §1p's recorded decision was
*not to serve* a trained model, for two stated reasons: the models learn
`HistoricalTransactionSeeder` output rather than real demand, and nothing in the
application retrains anything. The user asked for the model to be run in the app
anyway. The second reason is now addressed — `app:train-forecast-model` runs
monthly (server_architecture.md §6). **The first is not, and cannot be until
real sales history exists.** A served `tft` forecast today is a working pipeline
producing numbers from a model fitted to synthetic rules; it must not be
presented to anyone as validated accuracy.

**Serving is off by default.** `ML_DEFAULT_ALGORITHM` is `ewma` unless set, so a
deployment that does nothing keeps exactly the previous behaviour. Setting it to
`tft` is what puts the trained model in the path.

#### Why a checkpoint alone is not a model

`ml-service/app/forecasting/neural.py` needs three things the two baselines do
not, and each is a way to get confidently wrong numbers:

1. **Fitted pipeline state.** `TimeSeriesDataSet` holds the categorical encoders
   that map a `sku_id` to an embedding row, the per-series target normaliser,
   and the continuous scalers. Feed a model inputs encoded any other way and it
   returns plausible nonsense with no error. So
   `training/train.py::save_serving_artifacts()` now persists `get_parameters()`
   to `models/{name}/dataset_params.pt`, and serving rebuilds the identical
   pipeline with `from_parameters()`. **`train.py`'s docstring claimed it
   already wrote these; it did not.** That gap is why no trained model could be
   served before, independent of the accuracy argument.
2. **A shared time origin.** `time_idx` is a known-future *real* the model reads
   directly — days since the first date of the training frame. `epoch_date` is
   saved alongside so serving resolves calendar dates to the same integers.
   Recomputing it per request would place every forecast at `time_idx ≈ 0` and
   misrepresent the date to the model entirely.
3. **The full covariate set.** The TFT was trained on price, category, brand,
   promotions and stockouts. Serving it the demand series alone is not a
   slightly worse forecast — it is the model being told this SKU costs nothing,
   belongs to no category and was never out of stock.

#### The request contract grew a `features` block

`SeriesInput.features` (`ml-service/app/schemas.py`) is optional and
**all-or-nothing**: nine fields, or none. Laravel's
`MlServiceClient::buildNeuralFeatures()` assembles it column-for-column against
what `MlTrainingDataService` exports for training — that identity is the point.
Every daily list is parallel to `daily_sold_qty` and ends on the same day; a
short list is front-padded, never back-padded, because the series are anchored
at their *end* and back-padding would attribute last week's stockout to last
month.

`future_on_promotion` / `future_promotion_discount` cover the horizon, because a
retailer genuinely knows its own promotion calendar in advance — which is what
makes them legitimate known-future inputs rather than leakage. They are sent for
at least `MlServiceClient::NEURAL_DECODER_DAYS` (30) days regardless of the
horizon asked for, because the trained decoder always runs its full fixed length
and Python truncates afterwards.

#### Refusal, not degradation

`neural.py` raises `UnsupportedSeries` — and `main.py` answers with EWMA — for
every case where the model has nothing real to say:

| Refused | Why |
| --- | --- |
| No `features` block | The model would be reading zeros as facts |
| Horizon > 30 days | The decoder's length is fixed at training time |
| Fewer than `min_encoder_length` (45) days of history | Not enough context to encode |
| A `series_id` the encoder never saw | Cold start — no learned embedding. `add_nan=True` means this does *not* raise on its own, which is exactly why it is checked explicitly |
| Checkpoint or `dataset_params.pt` missing | Nothing to load |

**The response echoes the algorithm that actually ran**, plus a
`fallback_reason`. `ForecastRunService::processRun()` stamps each forecast row's
`model_version_id` from that echo rather than from what it requested. This is
the load-bearing part: filing a baseline result under the neural model's name
would give that model accuracy history for forecasts it never produced, and it
could then win a `ModelSelectionService` comparison it never took part in.

#### Which pairs are eligible

`ForecastRunService::prepareSeries()` attaches the feature block only for
**Established/Mature/Declining** pairs. A Cold-start or Early pair is forced
back to a baseline *in Laravel*, before the request: its series has been
substituted or blended with **peer** demand (§1j), and pairing that with this
SKU's own embedding, price and category would describe one product using another
product's sales. Python would refuse a genuinely new SKU anyway on the
unknown-SKU rule — but not an Early SKU that *is* in the training data, which is
the case that matters and the reason the check lives here.

#### Selection had to change to allow it at all

`ModelSelectionService` previously required **every** candidate algorithm to
have scored history before it would rank, returning `ewma` otherwise. With two
candidates that was reasonable. Two problems surfaced:

- With four candidates, "all of them" would mean the comparison never runs. It
  now ranks whenever **at least two** have history, comparing those.
- The comparison **cannot bootstrap itself**. A forecast is scored only after
  its horizon elapses, so on a fresh install (`forecast_accuracy` has 0 rows —
  it still does) every SKU falls through to the default, and whatever that
  default is, is what actually runs, possibly for months. Hardcoding `ewma`
  there quietly meant "the trained model is never used, regardless of whether
  one exists". That default is now `preferredAlgorithm()`, reading
  `ML_DEFAULT_ALGORITHM`.

`tft` and `deepar` register `ml_model_versions` rows
(`temporal-fusion-transformer`, `deepar`, both `v1`). **Known simplification:**
retraining overwrites `best.ckpt` in place and reuses `v1`, so forecasts from an
older checkpoint are indistinguishable from newer ones in the data. Given the
models decay with age, a real deployment wants a version per training run; that
needs a checkpoint identifier written by `train.py` and read back in
`modelVersionFor()`, and is deliberately not built.

#### A pre-flight availability check was built and removed

`MlServiceClient::availableAlgorithms()` and the `/models` endpoint behind it
exist, and are used by `app:forecast-model-status`. Gating *selection* on them
was tried and reverted: it put an extra HTTP call on every forecast run and
bought nothing, because the service already refuses what it cannot run and says
so in the response. A misconfiguration is now visible in the data rather than
hidden by a probe.

#### The accuracy case for the served model is *not* made on this data

The checkpoint now being served was trained on the default (minimal) split so it
is as recent as possible — the right choice for serving, and the reason the
`--holdout-days 365` evaluation setting is not used for a production model.
The cost is that only **one** 30-day window remains honestly scorable, and on it
the trained models lose:

| Algorithm | WAPE | Bias |
| --- | --- | --- |
| `ewma` | **0.3214** | +0.063 |
| `seasonal_naive` | 0.3243 | +0.098 |
| `tft` | 0.3535 | −0.051 |
| `deepar` | 0.3749 | +0.083 |

`evaluate.py` prints "The trained models did NOT beat the statistical baseline
here. Do not promote them into serving." That output is correct and is left
as-is.

**This is weak evidence, in both directions.** The window (2026-07-22 →
2026-08-20) contains no promotion and no festival, which is precisely the
condition where the covariate-aware models have least to offer — the same trap
§1p's experiment 1 fell into. §1p's fair test, 11 rolling windows over a year,
put a fresh TFT ahead by ~8%. But a single unfavourable window is what this
particular checkpoint can be scored on, and it is negative.

**Which is why `ML_DEFAULT_ALGORITHM` stays `ewma`.** The serving path is built,
tested and demonstrably working; the case for *using* it on this data is not
established, and the switch is left where the evidence points. Turning it on is
a one-line change whenever real sales history makes the question answerable.

#### Cost of a neural run, measured

441 pairs, 30-day horizon, CPU: **~20s warm**, ~30s including the first-request
checkpoint load. Two HTTP timeouts exist because the two paths differ by an
order of magnitude — `ML_SERVICE_TIMEOUT` (30s, baselines) and
`ML_SERVICE_NEURAL_TIMEOUT` (300s). The flat 30s that was there before was
**not** enough and the first real neural run failed on it, which is how the
split came about.

That first failure was worth having: the cause was not the timeout but
`neural.py` calling `model.predict()` **per series**, each building its own
Lightning trainer and dataloader — ~880 of them for one run.
`neural.forecast_many()` now assembles one frame for every series requesting the
same model and makes two passes over it (point forecast, then quantiles),
independent of batch size. Predictions are matched back to series by the group
id in `return_index`, never by position: the dataloader does not preserve input
order, and aligning positionally would hand SKUs each other's forecasts with
every number still looking plausible.

#### Findings from wiring this up

- **`seasonal_naive`'s `as_of` parameter is inert.** It changes no output value.
  `_weekday_averages` labels the history backwards from `as_of - 1` and the
  horizon is read forwards from `as_of`, so a shift moves the weekday buckets
  and the forecast days by the same amount and cancels exactly. The parameter
  buys readability at `evaluate.py`'s call site, nothing more, and
  `seasonal_naive.forecast`'s docstring claiming a wrong `as_of` "makes the
  model look far worse than it is" overstates it. Not changed — the behaviour is
  *correct*, since an element's weekday is fixed by its offset from the end of a
  bare list of numbers and nothing else is knowable. Pinned by
  `test_as_of_does_not_change_the_prediction`.
- **The test that should have caught that was structurally incapable of
  failing.** `test_a_historical_as_of_is_not_silently_aligned_to_today` asserted
  the two callers differ, guarded by "skip if today is a Saturday" — and the
  assertion cannot hold on any day, for the invariance reason above. It only
  ever ran on non-Saturdays; 2026-08-22, the last day the suite was run before
  this work, *was* a Saturday, so it was skipped and the suite reported green.
  Rewritten.
- **Snapshots lag the calendar.** `inventory_daily_snapshots` ends 2026-08-20
  while today is 2026-09-02 — seeded history stops where the seeder ran. The
  serving frame therefore anchors its forecast origin to **the day after the
  last observed day**, not `today()`. Anchoring to today would leave a gap
  between the encoder's last row and the decoder's first, which
  `TimeSeriesDataSet` fills by interpolation — inventing history rather than
  forecasting.

### 1r. The BuyAbans integration — where the data actually comes from

> **This reframes the application.** Every phase above builds machinery that
> reasons about demand; §1o's seeders filled it from a cross-database
> `DB::select()` against `buyabans_staging3`, which was a local convenience,
> not an integration. This section replaces that with a real, authenticated,
> read-only API — and settles what this application is: a **prediction system**,
> not a stock manager. The BuyAbans back office owns the catalog, the stock and
> the orders. This application forecasts them.

**The layers.**

| Layer | Path | Responsibility |
| --- | --- | --- |
| Client | `domain/Services/BuyabansClient/` | HTTP, OAuth token, page walking. Knows nothing about meaning |
| Service | `domain/Services/BuyabansSyncService/` | Maps the feed onto local records; owns idempotence |
| Facade | `domain/Facades/BuyabansSyncFacade/` | Entry point |
| Controller | `app/Http/Controllers/BuyabansSyncController.php` | Index, probe, manual run |
| Command | `app/Console/Commands/SyncBuyabansData.php` | `app:sync-buyabans {stage}` |
| Page | `resources/js/pages/BuyabansSync/index.tsx` | Sync history, demand summary, triggers |

The back-office side is documented in `server_architecture.md` §10.

**Idempotence is the whole design.** The sync runs nightly, gets re-run by hand
after a failure, and deliberately overlaps windows it has already covered. So
every write is an upsert keyed on something stable and externally meaningful:

| Local record | Keyed on |
| --- | --- |
| `Category` | `code` = `bab-c{buyabans id}` |
| `Brand` | `code` = `bab-b{option id}` |
| `Warehouse` | `code` = the real `location_code` |
| `Sku` / `Product` | `skus.sku` = the back-office SKU code |
| `BuyabansDailyDemand` | grain + location + SKU + date |
| `BuyabansStockLevel` | SKU + inventory source |

Prefixed synthetic codes rather than raw ids because `code` is user-visible and
unique: a bare `92` tells nobody anything, and would collide the first time
somebody created a category by hand.

**A MySQL null trap the tests now pin.** The demand unique index spans
`location_code`, and MySQL treats NULLs there as distinct — so the `national`
grain, which genuinely has no location, would have inserted a *fresh row every
night* instead of upserting, silently multiplying measured demand by the number
of syncs. The sync writes an empty string instead of null.

**Three new tables, and why none of them is an existing one.**

`buyabans_daily_demands`, `buyabans_stock_levels` and `buyabans_sync_runs` are
deliberately separate from `inventory_daily_snapshots` and `inventories`:

- A snapshot (§1h) is *this* application's statement about stock it holds,
  derived from its own append-only ledger. A synced demand row is *another*
  system's statement about sales it recorded. Merging them makes it impossible
  to say which number came from where.
- The nightly snapshot job would overwrite synced rows on its next pass.
- `inventories.on_hand_qty` is derived from `stock_movements`. Writing a synced
  figure into it that no movement produced breaks the invariant every balance
  there depends on — silently, and only visibly much later.

Failures are rows in `buyabans_sync_runs`, not just log lines, because a sync
that quietly stopped succeeding is the failure mode that rots every forecast
downstream while nothing visibly breaks.

**Unmatched rows are kept, not dropped.** Demand for a SKU with no local
counterpart still lands, with `sku_id` null and the SKU code retained, and the
count surfaces on the page. The same applies to products whose category did not
resolve: `products.category_id` is NOT NULL, so they attach to a
`bab-uncategorised` placeholder rather than being discarded. A product that
sells is worth forecasting whether or not its categorisation came across
cleanly.

**Training on synced demand — and what it honestly contains.**
`MlTrainingDataService::export()` now takes a `source`: `ledger` (unchanged,
§1p) or `buyabans`. The BuyAbans query fills the same column contract, but the
columns are not equally real:

| Column | On the `buyabans` source |
| --- | --- |
| `sold_qty` | **Real.** Measured demand, net of cancellations and refunds — the target, and the reason to prefer this source |
| `selling_price` | **Real, and better than the ledger source** — the price actually charged that day, which §1p explicitly could not provide |
| `category_id`, `brand_id`, calendar | Real |
| `opening_qty`, `closing_qty`, `available_qty` | **Not a history.** The back office reports a *current* stock position, so today's figure repeats for every day of the series |
| `received_qty`, `adjustment_qty`, `stockout_minutes`, `stockout_flag` | **Unknown**, exported as zero |

This is the same admission §1p already makes about price, in the other
direction. It is recorded rather than hidden because a neural model reads those
columns as fact: **a model trained on this source learns calendar, price,
promotion, category and brand effects on real demand, and learns nothing about
stock availability.** The statistical baselines are unaffected — they read the
daily series and nothing else. `app:export-ml-training-data --source=buyabans`
prints that warning on every run, because once a checkpoint is on disk it is
indistinguishable from a ledger-trained one.

**`warehouse_id` is a location key, not always a warehouse.** At the
`warehouse` grain it is the real id; at `channel` and `national` no warehouse
exists, so it is a dense rank over `location_code`. Stable within an export,
and never mixed, because an export covers exactly one grain.

**Five call sites follow the source, not four.** Switching demand sources is not
just a training-data change — every place that reads a demand series has to
follow, or the pipeline silently disagrees with itself:

| Reader | What it needs the source for |
| --- | --- |
| `MlTrainingDataService::export()` | the training CSV |
| `MlServiceClient::buildSeries()` | the daily series sent for inference |
| `MlServiceClient::buildNeuralFeatures()` | the covariate block |
| `ForecastRunService::pairsInScope()` | which pairs a run covers |
| `ForecastMaturityService` | first sale date, and the decline check |
| `DemandProfileService` | the peer series a cold-start SKU falls back to |

The last two were found by running it, not by reading it. `ForecastMaturityService`
took its first-sale date from `stock_movements` — a table that is *empty* for
synced SKUs, because this application records no stock movements of its own any
more. The first real run against synced demand produced **837 cold-start and 12
SKU-history forecasts out of 966**, despite every pair having three years of
history: each SKU looked like it had never sold. After pointing maturity and the
peer profile at the same source, the identical run produced **966 SKU-history
forecasts**. Nothing errored in the broken version — it just quietly forecast
from category averages instead of real demand, which is exactly the class of
failure a source switch invites.

**The training export excludes what the serving path will not forecast.** At the
warehouse grain, demand whose order carried no location code is real but
unattributed, and `pairsInScope()` will never forecast such a pair — so
`buyabansDemandQuery()` drops it too rather than training on a series that is
never served.

**The stock-operation modules are still here, and still work.** Purchase
orders, goods receipts, stock transfers, sales orders, sales returns, stock
movements and manual adjustments were **not** removed — every route, service,
test and page is intact and passing. They are simply no longer navigation
(`app-sidebar.tsx`), because operating stock is the back office's job. This was
a deliberate choice over deletion: the repository is not under version control,
so a deletion would have been unrecoverable, and the recommendation engine's
accept-path still refers to the PO and transfer workflows.

### 1s. The read-only boundary — the write paths are gone, not guarded

This application authors nothing. Not "authors nothing by default", not "refuses
writes with a 403" — the create, edit, store, update and delete paths for every
data module were **deleted**.

**Why deletion rather than a guard.** A guarded route is still a route: it
appears in `route:list`, Wayfinder still generates a helper for it, the
controller method and form request still sit there, and a create page still
exists that nothing can reach. That is dead weight that reads as live code to
the next person. The rule that made it dead — the back office owns this data,
and a local edit is silently undone by the next sync, which upserts every row it
fetches — does not change, so neither does the code's uselessness.

**What went.**

| Layer | Removed |
| --- | --- |
| Routes | 78 write routes across 15 modules — `create`, `edit`, `store`, `update`, `delete`, and the PO/transfer/sales-order status transitions |
| Controllers | The matching methods; every data controller is now `index` / `all` / `get` only |
| Form requests | 27 files — every `Create*Request` / `Update*Request` for those modules |
| Pages | 27 `create.tsx` / `edit.tsx` files |
| Services | The `store` / `update` / `delete` / transition methods, plus the private helpers left orphaned by them (`totals`, `transition`, `syncValues`, `pivotData`, `syncSupplierSkus`) |
| Middleware | `ReadOnlyResource` and `BUYABANS_ALLOW_LOCAL_WRITES` — with the routes gone there is nothing left to guard |
| Enum | `MovementType::manualEntryCases()`, which only the deleted manual-adjustment path used |

Roughly 4,200 lines of PHP.

**What survives, and why.** Every listing, every `index` / `all` / `get`, every
model, migration and table — the history already in those tables stays readable.
Plus the writes that are operations rather than data entry: syncing, starting a
forecast run, scoring accuracy, capturing a snapshot or supplier performance,
generating recommendations and recording an accept/modify/reject, and the user's
own account.

**`StockMovementService::post()` is the one deliberate survivor.** It is the
ledger's write primitive, and nothing in the application calls it any more. It
stays because it wrote the history the `ledger` demand source still reads, and
because {@see InventoryDailySnapshotTest} builds its fixtures with it. Its
sibling `store()` — the manual-adjustment entry point, with the
negative-stock guard — was deleted with the route that reached it.

**A latent bug that only became visible once the callers were gone.** `post()`
writes the ledger row *before* applying the balance, and opens no transaction of
its own — every caller it ever had wrapped it. Called bare, a refused movement
leaves the row behind. `StockLedgerTest` asserts that as the contract rather
than wishing it away, because it is what any future caller inherits.

**Tests: 77 removed, 4 recovered, 15 added.** The deleted tests exercised the
deleted routes, and `.ai/rules/workflow.md` requires saying so out loud. But
four of them covered logic that survived — FIFO batch consumption and
weighted-average costing through `post()` — so they moved to `StockLedgerTest`
and call the Facade directly instead of being lost with the route. Each module
suite gained a test asserting its write routes do not exist, which is what stops
them quietly coming back.

**Two traps worth recording.** Removing methods by brace-counting over raw text
is unsafe in this codebase: an apostrophe inside a comment ("don't") opens a
phantom string and the matcher runs past the method's real end, silently
swallowing every method after it — it emptied four services before being caught.
The rewrite uses PHP's own `token_get_all()`, which knows code from comment. And
the JSX equivalent bit too: a column keyed `'actions'` on the promotions page
held a read-only *View impact* link, not write actions, and was removed by a
name match before being restored.

---

---

## 2. Models

### `User` — `app/Models/User.php`

The only application model. Uses PHP attributes rather than properties for
fillable/hidden, which is the Laravel 13 style:

```php
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
```

Traits: `HasFactory`, `Notifiable`, `PasskeyAuthenticatable`,
`TwoFactorAuthenticatable`.

Casts: `email_verified_at` → `datetime`, `password` → `hashed`,
`two_factor_confirmed_at` → `datetime`.

Relationships come from the Fortify traits — `passkeys()` from
`PasskeyAuthenticatable`. There is no hand-written relationship yet.

### Schema

| Table | Migration | Notes |
| --- | --- | --- |
| `users` | `0001_01_01_000000` | `email` unique; two-factor columns added by `2025_08_14_170933` |
| `password_reset_tokens` | `0001_01_01_000000` | email-keyed |
| `sessions` | `0001_01_01_000000` | database session driver |
| `cache`, `cache_locks` | `0001_01_01_000001` | database cache store |
| `jobs`, `job_batches`, `failed_jobs` | `0001_01_01_000002` | database queue |
| `passkeys` | `2024_01_01_000000` | `user_id` FK cascade-on-delete, `credential_id` unique, `credential` JSON |
| `warehouses`, `brands`, `categories` | `2026_08_15_1532{40,41,42}` | `categories.parent_id` self-referencing FK, `nullOnDelete` |
| `attributes`, `attribute_values` | `2026_08_15_1532{43,44}` | `attribute_values` unique on `(attribute_id, value)`; no `status`, no `SoftDeletes` — synced/hard-deleted by the parent Attribute |
| `products` | `2026_08_15_153245` | `category_id` `restrictOnDelete`, `brand_id` nullable `nullOnDelete`, `product_type` enum-backed string |
| `product_variants` | `2026_08_15_153246` | `product_id` `cascadeOnDelete` |
| `variant_attribute_values` | `2026_08_15_153247` | pivot for `ProductVariant belongsToMany AttributeValue`, unique on `(product_variant_id, attribute_id)`, extra `attribute_id` pivot column — see §1a |
| `skus` | `2026_08_15_153248` | `product_id` `restrictOnDelete`, `product_variant_id` nullable |
| `inventories` | `2026_08_15_153249` | unique on `(warehouse_id, sku_id)`; **no `status`, no `SoftDeletes`** — derived, never soft-deleted, see §1b |
| `stock_movements` | `2026_08_15_153250` | **`created_at` only, no `updated_at`** (`StockMovement::UPDATED_AT = null`) — immutable ledger, see §1b, §1e; `reference_type`/`reference_id` populated from Phase 2 onward |
| `suppliers`, `supplier_skus` | `2026_08_16_09000{0,1}` | `supplier_skus` unique on `(supplier_id, sku_id)`; `is_primary` enforced unique-per-SKU-across-suppliers at the Service layer, not the DB — see §1a |
| `purchase_orders`, `purchase_order_items` | `2026_08_16_09000{2,3}` | `po_number` unique; `status` enum-backed string; `purchase_order_items.received_qty` defaults 0, rolled forward by receiving — see §1f |
| `goods_receipts`, `goods_receipt_items` | `2026_08_16_09000{4,5}` | **`created_at` only, no `updated_at`** — immutable, see §1f |
| `stock_transfers`, `stock_transfer_items` | `2026_08_16_09000{6,7}` | `source_warehouse_id`/`destination_warehouse_id` both FK `warehouses`, app-level guard they differ — see §1f |
| `sales_orders`, `sales_order_items` | `2026_08_16_09000{8,9}` | `customer_name` plain nullable string, not a Customer FK — see §1f; `sales_order_items.cost` nullable until `confirm()` |
| `sales_returns`, `sales_return_items` | `2026_08_16_0900{10,11}` | **`created_at` only, no `updated_at`** — immutable, see §1f; `sales_return_items.condition` enum-backed string |
| `inventory_batches` | `2026_08_16_090012` | FIFO lot ledger, `source_type`/`source_id` plain columns (not a morph relation, matching app_plan.md §23's schema literally); no `status`, no `SoftDeletes` — see §1e |
| `inventory_daily_snapshots` | `2026_08_16_100000` | unique on `(snapshot_date, warehouse_id, sku_id)`; `snapshot_date` is a `date`-cast column — compare with `whereDate()`, never `where()`, see §1h |
| `ml_model_versions` | `2026_08_16_110000` | unique on `(name, version)`; `accuracy_metrics` JSON — see §1i |
| `ml_forecast_runs` | `2026_08_16_110001` | `warehouse_ids` nullable JSON array, `model_version_id` nullable FK, `status` enum-backed string — see §1i |
| `forecasts` | `2026_08_16_110002` | `forecast_run_id`/`model_version_id` FKs, `forecast_source` enum-backed string — see §1i |
| `forecast_accuracy` | `2026_08_16_110003` | unique on `forecast_id`; table name doesn't pluralize from `ForecastAccuracy` — explicit `$table`, see §1i |
| `inventory_recommendations` | `2026_08_16_120000`, `..._130000` | `forecast_id` nullable FK; `recommendation_type`/`status`/`stockout_risk`/`overstock_risk` enum-backed strings, `ageing_risk` a plain string cast to `integer` on the model; `source_warehouse_id` nullable FK `warehouses` added in the second migration, set only on `TransferStock` rows — see §1k, §1l, §1m |
| `supplier_performance_metrics` | `2026_08_17_090000` | Unique on `(supplier_id, period_start)`; `quality_issue_rate` a real nullable column that always stays `null` — no data source, see §1n |
| `promotions`, `promotion_skus` | `2026_08_17_100000` | `discount_type` enum-backed string; no `status` column — state is derived from `start_date`/`end_date`, not stored; `promotion_skus` is a plain pivot, no extra columns — see §1n |

`Category`, `Brand`, `Attribute`, `Product`, `ProductVariant`, `Sku`,
`Warehouse`, `Supplier`, `PurchaseOrder`, `StockTransfer`, `SalesOrder` and
`Promotion` all use `SoftDeletes` (each guarded to Draft-only delete at the
Service layer where a status workflow exists — `Promotion` has none, so its
delete is unguarded). `Inventory`, `StockMovement`, `GoodsReceipt`,
`SalesReturn`, `InventoryBatch`, `InventoryDailySnapshot`,
`InventoryRecommendation` and `SupplierPerformanceMetric` do not — balances
are upserted/recaptured in place, and
ledger/immutable-document/derived-history/regenerable rows are never
soft-deleted (a stale `InventoryRecommendation` is hard-deleted by
`generate()` itself — see §1k).

**Model conventions for new models:** explicit fillable (never `$guarded = []`),
explicit `casts()`, explicit relationships, `SoftDeletes` where records are
recoverable. Cast `status` to `boolean`, money to `decimal:2`, JSON to `array`.

**Migration conventions:** index every foreign key and anything filtered or
sorted by; unique constraints on slugs and codes; `nullable()` only when the
column is genuinely optional.

---

## 3. HTTP layer

### Controllers

Two, both thin and both in `app/Http/Controllers/Settings/`:

- `ProfileController` — `edit`, `update`, `destroy`
- `SecurityController` — `edit`, `update`

They render Inertia pages and mutate the authenticated user directly. Because
there is no `UserService`/`UserFacade`, these predate the domain layer; they are
the starter kit's own code. New modules must go through a Facade.

`SecurityController::edit` is the busiest method — it assembles passkey data,
two-factor capability flags and password rules for the security page.

### Requests

`app/Http/Requests/Settings/`: `ProfileUpdateRequest`, `ProfileDeleteRequest`,
`PasswordUpdateRequest`, `TwoFactorAuthenticationRequest`.

Validation logic is shared through **traits in `app/Concerns/`**, not through
base classes:

- `PasswordValidationRules` — `passwordRules()`, `currentPasswordRules()`
- `ProfileValidationRules` — `profileRules()`, `nameRules()`, `emailRules()`

Both `CreateNewUser` and the FormRequests consume these traits, so registration
and profile-update stay in lockstep. Follow this pattern rather than duplicating
rules.

### Middleware

Registered in `bootstrap/app.php`, appended to the `web` group:

| Middleware | Purpose |
| --- | --- |
| `HandleAppearance` | Shares the `appearance` cookie with Blade for pre-paint theming |
| `HandleInertiaRequests` | Shares `name`, `auth.user`, `sidebarOpen` with every page |
| `AddLinkHeadersForPreloadedAssets` | Preload headers |

`appearance` and `sidebar_state` are excluded from cookie encryption — the
pre-paint inline script must read `appearance` before JS boots.

Exceptions render as JSON when the request `is('api/*')` or expects JSON.

### Routes

| File | Contents |
| --- | --- |
| `routes/web.php` | `/` (welcome), `/dashboard` (auth + verified), requires `settings.php` and `modules.php` |
| `routes/settings.php` | Profile, security, appearance, `.well-known/passkey-endpoints` |
| `routes/modules.php` | All Phase 1 module route groups (Category, Brand, Attribute, Product, ProductVariant, Sku, Warehouse, Inventory, StockMovement), one `Route::middleware(['auth', 'verified'])` block |
| `routes/sales_purchasing.php` | All Phase 2 module route groups (Supplier, PurchaseOrder, GoodsReceipt, StockTransfer, SalesOrder, SalesReturn, InventoryBatch), same middleware block |
| `routes/analytics.php` | Phase 3's single `inventory-analytics.index` route, same middleware block |
| `routes/pipeline.php` | Phase 4's `inventory-daily-snapshot.{index,capture}` routes, same middleware block |
| `routes/forecasting.php` | Phase 5's `forecast-run.{index,create,store}` and `forecast.{index,score-accuracy}` routes, same middleware block |
| `routes/inventory_optimization.php` | Phase 7's `inventory-recommendation.{index,generate}` and per-id `{review,accept,modify,reject}` routes, same middleware block |
| `routes/console.php` | `inspire`, plus the `app:capture-inventory-snapshots` (§1h), `app:score-forecast-accuracy` (§1i) and `app:generate-inventory-recommendations` (§1k) schedules |

Fortify registers its own auth routes. Health check at `/up`.

`routes/modules.php` / `routes/sales_purchasing.php` / `routes/analytics.php`
/ `routes/pipeline.php` / `routes/forecasting.php` / `routes/inventory_optimization.php` are a deliberate, documented departure from the
module-checklist's literal instruction to append each group directly to
`web.php` — nine (then sixteen) modules made a single file unwieldy, and
requiring a dedicated file is an already-established pattern here
(`settings.php`). Splitting by phase rather than growing one ever larger file
was the same call made once already at Phase 1 (see that file's own
docblock) — do the same for the next phase rather than appending
indefinitely to one file. Each module's route group still follows the
checklist's shape exactly: `/create` and `/all` declared **before**
`/{id}/...`, names
`{module}.{index,create,edit,all,get,store,update,delete}` — `Inventory`,
`StockMovement`, `InventoryBatch`, `GoodsReceipt`, `SalesReturn`,
`InventoryAnalytics`, `InventoryDailySnapshot`, `ForecastRun`, `Forecast` and
`InventoryRecommendation` omit the routes their write-shape doesn't have
(§1b, §1f, §1g, §1h, §1i, §1k) — `InventoryDailySnapshot` replaces
`store`/`update`/`delete` with a single `capture` action; `ForecastRun` has
only `index`/`create`/`store` (a run is never edited); `Forecast` has only
`index` plus a `score-accuracy` action, no `create` at all;
`InventoryRecommendation` has only `index` plus a `generate` action, no
`create`/`edit`/`update`/`delete` at all — a recommendation is only ever
written by the engine. `PurchaseOrder` and `StockTransfer` add extra
`POST /{id}/{verb}` status-transition routes (`approve`, `cancel`,
`mark-ordered` / `dispatch`/`receive`) beyond the checklist's eight, and
`InventoryRecommendation` adds four of its own (`review`, `accept`,
`modify`, `reject`) — the checklist's shape is a floor, not a ceiling, for
modules with a real workflow.

Phase 10 (§1n) follows the same "omit what the write-shape doesn't have"
rule: `SupplierPerformance` mirrors `InventoryDailySnapshot` exactly
(`index` plus one `capture` action, no create/edit/update/delete);
`DemandInsights`, `ProductRelationship` and `PriceElasticity` have **only**
their read-only report action(s) — no `create`, `store`, `edit`, `update` or
`delete` routes at all, since nothing is ever written. `Promotion` is the
one Phase 10 module with the full eight-route shape (it owns real records),
plus a ninth, read-only `impact` action beyond even
`InventoryRecommendation`'s extra four — the same "floor, not ceiling"
allowance.

**Permission middleware is deliberately omitted.** The strict spec calls for
`middleware('permission:{module}_view')`, but no permission package is
installed, and an undefined middleware alias throws at boot. Keep `auth` (and
`verified` where appropriate) until one is added. Installing a permission
package (e.g. `spatie/laravel-permission`) is a dependency change and needs
separate approval — not done as part of Phase 1, Phase 2, Phase 3 or Phase 4.

---

## 4. Frontend architecture

```
resources/js/
├── app.tsx                     Inertia bootstrap + layout resolution
├── pages/                      one folder per module; {index,create,edit}.tsx
├── components/                 shared UI kit — reuse before adding
├── components/charts/          hand-rolled SVG charts, no chart library
├── layouts/                    app / auth / settings shells
├── hooks/  lib/  types/
└── actions/ routes/ wayfinder/ GENERATED by Wayfinder — never hand-edit
resources/scss/
├── app.scss                    the only entry point
├── abstracts/_variables        Bootstrap overrides (must precede the import)
├── base/_tokens                CSS custom properties + dark overrides
└── layout/ components/ utilities/
```

### Layout resolution

`app.tsx` picks the layout from the page name: `welcome` gets none, `auth/*`
gets `AuthLayout`, `settings/*` gets `[AppLayout, SettingsLayout]`, everything
else gets `AppLayout`. Adding a page under one of those prefixes wires the
layout automatically — no per-page import.

React runs in `strictMode` with the React Compiler babel plugin enabled.
`ToastHost` is mounted once via `withApp`.

### Styling — Bootstrap 5.3 only

**Tailwind, shadcn/ui and Radix were removed on 2026-08-15 and must not
return.** No `@apply`, no `tailwind.config.js`, no `dark:` variants, no
`class-variance-authority`, no `tailwind-merge`.

- Bootstrap utilities for layout (`d-flex`, `gap-3`, `row`, `col-lg-6`)
- Project SCSS classes for appearance (`app-card`, `app-table`, `app-field`,
  `app-badge`)
- Tokens in `base/_tokens.scss` — never hard-code a colour
- Conditional classes via `cn()` from `@/lib/utils` (clsx only; no class merging)
- Dark mode via `data-bs-theme`, never a `dark:` variant
- Modals are **React-owned** (`@/components/modal`), not Bootstrap modal JS, so
  the DOM has a single owner. Bootstrap's JS bundle is imported once in
  `app.tsx` for dropdown/collapse/offcanvas only.

The shared visual system is deliberately shell-first. The light and dark
canvases, midnight navigation rail, sapphire-to-teal brand treatment, surface
elevation, page-header framing and component states all come from runtime CSS
properties in `base/_tokens.scss`; no module page owns a private palette. In
addition to the existing surface/text/chart variables, the shell uses
`--app-canvas*`, `--app-sidebar-*`, `--app-surface-raised`,
`--app-surface-highlight`, `--app-gradient-panel`, `--app-gradient-sidebar`
and `--app-gradient-grid`. Shared SCSS consumes those tokens in the layout and
component partials, which is why changing the shell, cards, forms and tables
updates the full application without rewriting each module page.

`AppLayout` keeps the fixed sidebar + sticky frosted topbar structure. Direct
children of `app-shell__content` receive the same vertical rhythm as pages that
explicitly use `app-stack`, closing the visual gap between older fragment-based
module pages and newer dashboard/report compositions. `PageHeader` is the
standard framed context surface on every authenticated page; `AuthLayout` and
the public `welcome` page reuse the same tokens but provide purpose-built
split-panel and marketing compositions.

### The UI kit

Check here before writing a component:

| Need | Component |
| --- | --- |
| Page title + actions | `PageHeader` |
| Content surface | `SectionCard` |
| Listing table | `DataTable` (+ `TableFilters`, `Pagination`) |
| KPI tile | `StatCard` (optional `Sparkline`) |
| Quick action tile | `ShortcutCard` |
| Dialog | `Modal`, `ConfirmDialog` |
| Field + label + error | `FormField`, `InputError`, `PasswordInput`, `OtpInput` |
| State pill / empty / busy | `StatusBadge`, `EmptyState`, `Spinner` |
| Inline notice / transient | `Alert`, `toast` from `@/lib/toast` |
| Charts | `TrendChart`, `BarChart`, `Sparkline`, `Meter` |

### Charts

Hand-rolled SVG, no chart library. The categorical palette is a fixed six-slot
ramp in `base/_tokens.scss`, validated for colour-vision separation in both
themes. Non-negotiable: colours assigned **by slot index, never by rank** (so
filtering never repaints); **one y-axis, ever**; a legend at ≥2 series; no
seventh generated hue — fold into "Other" or facet.

### Listing pages

Every `pages/{Module}/index.tsx` needs filters, **20-per-page** pagination
(`DEFAULT_PER_PAGE`, matching the backend), sortable columns, loading and empty
states, per-row actions, and `ConfirmDialog` for deletes — never
`window.confirm`. Drive round-trips with
`router.get(url, params, { preserveState: true, replace: true })` and debounce
the search box.

### Editable `<table>` rows (Phase 2: GoodsReceipt, SalesReturn create forms)

Where a repeatable row is rendered as a `<tr>` (an outstanding PO/sales-order
line, one per table row) rather than a flex-row `<div>` (§1a's pattern), any
hidden `<input type="hidden">` carrying that row's id **must go inside a
`<td>`**, not as a direct child of `<tr>` — browsers silently reparent an
`<input>` placed directly under `<tr>`, which passes a plain build but throws
a React hydration warning in dev (`%s cannot be a child of <%s>`) and can
desync the DOM from React's tree. Both `GoodsReceipt/create.tsx` and
`SalesReturn/create.tsx` originally got this wrong (hidden input before the
first `<td>`) and were only caught by checking the browser console during
manual QA — `tsc`/ESLint/Prettier have no way to catch invalid HTML nesting.

### Wayfinder

`resources/js/actions/` and `resources/js/routes/` are **generated** from
Laravel routes by the Wayfinder Vite plugin (`formVariants: true`). Import route
helpers from `@/routes/...` and `@/actions/...` instead of hard-coding URLs.
Never hand-edit these files; regenerate instead.

---

## 5. Data flow

**Page load.** Route → middleware (`HandleAppearance` shares the theme,
`HandleInertiaRequests` shares `auth.user`, `name`, `sidebarOpen`) → controller
→ `Inertia::render('page/name', props)` → `app.blade.php` boots with the theme
already resolved → `app.tsx` picks the layout → the page component renders.

**Form submission.** Inertia `useForm`/`<Form>` → POST/PATCH → FormRequest
validates → controller acts → `Inertia::flash('toast', [...])` → redirect →
`ToastHost` shows the toast. Server errors arrive in Inertia's `errors` object;
`FormField` surfaces them. Never hand-roll client validation that contradicts
the FormRequest.

**For new modules,** the controller must delegate to a Facade rather than acting
directly, and the listing endpoint must paginate at 20, apply an explicit sort
(`latest()` at minimum), and eager-load anything the Resource touches.

---

## 6. Service conventions

Model injected in the constructor. Every write wrapped in a transaction. Every
failure logged. A consistent return envelope:

```php
return ['success' => true, 'message' => '…', 'data' => $model];
return ['success' => false, 'message' => 'Error creating …'];
```

Baseline methods: `count()`, `all()`, `get($id)`, `store(array $data)`,
`update(array $data, $id)`, `delete(int $id)`. Add module-specific methods only
where needed. Never let a write fail silently; never duplicate logic between
services — extract a shared private method or call the other Facade.

Facades contain nothing but `getFacadeAccessor()` returning the service class.
Laravel resolves it from the container; no manual binding is needed unless the
service takes non-resolvable constructor arguments.

The controller calling that Service's `store()`/`update()`/`delete()` does not
return the envelope as JSON — it turns it into an Inertia redirect with a
flashed toast. See §1d for why, and §1a–§1c for the Attribute/ProductVariant
nested-row sync, the Inventory/StockMovement write-path exception, and the
cross-module `options()` pattern.

---

## 7. Tooling and agent configuration

| File | Purpose |
| --- | --- |
| `CLAUDE.md` / `AGENTS.md` | Canonical instructions — **kept byte-identical** |
| `.github/copilot-instructions.md` | Points at `AGENTS.md` |
| `.ai/rules/` | Enforced project rules, mapped by glob in `index.md` |
| `.claude/skills/` | Skills for Claude Code |
| `.agents/skills/` | Mirror for other agents |
| `.claude/settings.json` | Permissions + MCP enablement |
| `.mcp.json` / `.codex/config.toml` | MCP servers |
| `boost.json` | Which Boost skills/guidelines are generated |

### Skills

Project-authored (safe to edit): `module-generation`, `premium-ui-design`,
`project-workflow`, `claude-config`, `browser-qa`.

Boost-generated (listed in `boost.json`, **regenerated by `boost:update` — do
not hand-edit**): `fortify-development`, `inertia-react-development`,
`infer-conventions`, `laravel-best-practices`, `pest-testing`,
`wayfinder-development`.

### MCP servers

| Server | Command | Provides |
| --- | --- | --- |
| `laravel-boost` | `php artisan boost:mcp` | `search-docs`, `database-query`, `database-schema`, `tinker`, `browser-logs`, `get-absolute-url`, `record-rule` |
| `playwright` | `npx -y @playwright/mcp@latest --browser chromium` | Real-browser E2E and visual checks |

### Testing

Pest 4 with `pestphp/pest-plugin-laravel`. Larastan (level per `phpstan.neon`)
for static analysis; Pint for formatting; ESLint + Prettier + `tsc --noEmit` for
the frontend.

Each module has `tests/Feature/{Module}Test.php` (guest redirect, authenticated
index render, store/validation, update, delete — soft or business-rule-blocked
as applicable). `StockMovementTest.php` additionally covers the
balance-mutation business rule: an inbound movement creates/rolls forward the
`Inventory` row (including weighted-average cost), an outbound movement
exceeding `on_hand_qty` is rejected without writing a ledger row or mutating
the balance, and only `MovementType::manualEntryCases()` validate through the
form. `AttributeTest.php` and `ProductVariantTest.php` cover the nested-row
sync (§1a) including the in-use guard.

Phase 2 tests additionally cover: `PurchaseOrderTest.php` — totals computed
server-side, the Draft→Approved→Ordered workflow setting `incoming_qty`,
non-Draft edit/delete rejection, cancellation clearing `incoming_qty`;
`GoodsReceiptTest.php` — full and partial receipts rolling the parent PO's
status and `received_qty`, the balance/batch side effects, and the
wrong-warehouse guard; `StockTransferTest.php` — the
Draft→Approved→Dispatched→Received workflow moving stock between two
warehouses while carrying the FIFO cost forward, same-warehouse validation,
and the "can't cancel once dispatched" guard; `SalesOrderTest.php` — Draft
orders never touching stock, `confirm()` deducting stock and capturing FIFO
cost onto the line, the on-hand guard rejecting an over-large confirm, and
Confirmed orders being un-cancellable/undeletable; `SalesReturnTest.php` — a
sellable return restoring stock at the original sale cost, a damaged return
netting zero on-hand change while posting both ledger entries, and the
per-line over-return guard; `InventoryBatchTest.php` — read-only route
assertions plus an end-to-end FIFO consumption-order check (oldest batch
depletes first) driven through the public `stock-movement.store` route, not a
direct Service call. `SupplierTest.php` covers the nested `supplier_skus`
sync (§1a) including the cross-supplier `is_primary` uniqueness enforcement
and the has-purchase-orders delete guard.

Resource classes carry `@mixin {Model}` PHPDoc so Larastan resolves the
magic-property access (`$this->id`, `$this->name`, …) `JsonResource::__get()`
proxies to the underlying model — without it, level-7 analysis reports every
property access as undefined. Service methods that return a paginator are
typed `Illuminate\Pagination\LengthAwarePaginator` (the concrete class), not
the `Illuminate\Contracts\Pagination\LengthAwarePaginator` interface — only the
concrete class declares `->through()`, which every `index()`/`all()` uses to
map paginated rows through a Resource without disturbing pagination metadata.
A Resource property typed non-nullable in its model's PHPDoc (e.g.
`PurchaseOrder::$order_date`) must be accessed with `->`, never `?->` — Pint's
`treatPhpDocTypesAsCertain` setting flags the nullsafe operator on a
provably-non-null type as an error, not a style nit.

**Browser tests are not in the suite.** `pestphp/pest-plugin-browser` requires
`ext-sockets`, which is missing from the local PHP 8.3 build (the DLL ships with
Laragon but is commented out at line 960 of its `php.ini`). v5 of the plugin
additionally requires PHP ^8.4, so v4.3 is the usable line here. Until sockets
is enabled, browser verification is interactive through Playwright MCP — see the
`browser-qa` skill.

**Playwright MCP's synthetic `browser_click` is unreliable against Inertia
`<Form>` submit buttons reached via a preceding chain of `browser_select_option`
/`browser_type` calls** — the click sometimes doesn't register at all (zero
network requests fire), while the exact same button clicked via
`browser_evaluate`'s native `element.click()` always works. This was
discovered — and worked around by switching to `browser_evaluate`-driven
clicks with an explicit ~1s wait — during Phase 2's browser verification; it
is a testing-tool timing quirk, not an application bug (confirmed by the
underlying request always succeeding with correct data once the click
actually fires). Trust `assertRedirect`/database assertions in Pest over
browser-snapshot timing for the same reason: a `browser_snapshot` taken
immediately after a `browser_click` can reflect the DOM *before* React/Inertia
finishes committing the update, reading as "nothing happened" when it did.
Separately, every successful Inertia `<Form>` submission-then-redirect in this
app logs a `TypeError: Failed to construct 'FormData': parameter 1 is not of
type 'HTMLFormElement'` to the browser console during the old page's unmount
(`@inertiajs/react`'s own `updateDirtyState`, not application code) — it fires
after the navigation has already completed, throws in nobody's catch block,
and has no observed effect on functionality. Known and benign; do not treat it
as a regression.

`InventoryAnalyticsTest.php` (Phase 3) reads computed rows straight off the
Inertia response's props (`$response->getOriginalContent()->getData()['page']
['props']['rows']['data']`) rather than `assertInertia()`'s fluent `where()`
chain — the rows are plain `stdClass` objects with ~18 computed fields each,
which doesn't fit the fluent assertion style well. **Access fields with `->`,
not array syntax** — `firstWhere()`/`pluck()` on the resulting `Collection`
both work fine against `stdClass` (Laravel's `data_get()` handles both), but
`$row['field']` throws `Cannot use object of type stdClass as array`; this
tripped up the test's first draft. Seed exact, hand-computed inputs (fixed
quantities, fixed `occurred_at`/`received_date` offsets) and assert the exact
expected output — velocity, days of stock, turnover, reorder point are all
plain arithmetic once the inputs are pinned down, so there's no reason to
assert anything looser.

`InventoryDailySnapshotTest.php` (Phase 4) tests the manual-capture HTTP
route rather than calling the Service directly, both to exercise the real
request/validation path and because that's what the hand-computed
opening/closing/stockout-minutes scenario (§1h) needed to be checked against
end to end. Its idempotency test asserts the *response* was a success toast,
not just that the row count stayed at 1 — a row count alone can't tell "the
second capture correctly updated in place" apart from "the second capture
silently failed and left the first row untouched," which is exactly the bug
§1h documents.

---

## 8. Naming conventions

| Thing | Convention | Example |
| --- | --- | --- |
| Model | PascalCase singular | `LoyaltyRule` |
| Table | snake_case plural | `loyalty_rules` |
| Route prefix | kebab-case singular | `/loyalty-rule` |
| Route names | `{module}.{action}` | `loyalty-rule.index` |
| Permissions (when added) | `{module}_{action}` | `loyalty_rule_view` |
| Page folder | PascalCase singular | `resources/js/pages/LoyaltyRule/` |

PHP style: curly braces always, constructor property promotion, explicit return
types and parameter type hints, TitleCase enum keys, PHPDoc over inline comments
with array shapes documented.
