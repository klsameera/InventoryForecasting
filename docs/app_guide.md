# App guide — InventoryForecasting

What the application does today, how a user moves through it, and how to get it
running locally.

For a non-technical management overview, operating procedures, control summary,
and go-live checklist, see [`management_guide.md`](management_guide.md).

> **Scope note.** This document describes the application **as it currently
> exists**. Since 2026-09-04 it is a **prediction system, not a stock
> manager**: catalog, stock levels and sales history are pulled read-only from
> the BuyAbans back office over an authenticated API (see "BuyAbans data sync"
> in §2), and the stock-operation modules, while still present and working,
> are no longer part of the navigation. All ten phases of `docs/app_plan.md` are now built: catalog
> (categories, brands, attributes, products, variants, SKUs), warehouses, an
> inventory ledger with manual stock adjustments and FIFO batch tracking,
> suppliers, purchase orders, goods receiving, stock transfers, sales
> orders, sales returns, a deterministic (non-ML) inventory analytics
> report, a nightly daily-snapshot pipeline, a real forecasting module
> (Laravel↔Python integration, real schema and UI, **two statistical baselines
> plus two trained neural models — a Temporal Fusion Transformer and DeepAR —
> chosen per SKU from real backtested accuracy**, with the trained models off
> by default via `ML_DEFAULT_ALGORITHM` and still trained on generated rather
> than real sales history, and real maturity-aware fallback forecasting for
> SKUs with little or no sales history), a real inventory decision engine
> covering replenishment, ageing prevention and multi-location optimization
> (transfer matching plus a central-allocation rollup) behind a human
> accept/modify/reject workflow, and six advanced-intelligence signals
> (supplier lead-time prediction, lost-sales estimation, demand anomaly
> detection, automatic product similarity, product cannibalization and
> successor candidates, and price elasticity) plus a full promotion-impact
> module — see §2's Forecasting and Advanced intelligence sections and
> `ml-service/README.md`. **Only roles/permissions remain unbuilt** — no
> permission package is installed, see §7. Sections below say so explicitly
> rather than describing planned features as real ones.

---

## 1. What the app is

A Laravel 13 + Inertia v3 + React 19 single-page application, styled with
Bootstrap 5.3 and a global SCSS theme. It is the foundation for an inventory
forecasting tool: authentication, account security, navigation, charts, the
design system, the catalog + inventory-ledger foundation, the sales +
purchasing workflow, deterministic inventory analytics, the daily snapshot
pipeline, a forecasting module (queued runs, a Python HTTP service serving two
statistical baselines and two trained neural models, a persisted
forecast/accuracy schema, cold-start fallback forecasting), and an
inventory decision engine (reorder points, dynamic safety stock, MOQ-aware
purchase recommendations, ageing/overstock-driven reduce-purchase,
do-not-reorder and clearance recommendations, and cross-warehouse transfer
recommendations plus a central-allocation rollup), and six advanced-
intelligence signals plus a promotion-impact module (see §2's Advanced
intelligence section) are complete and tested. The prediction algorithm
underneath it is one of four, chosen per SKU from real accuracy history: two
statistical baselines, or a trained Temporal Fusion Transformer / DeepAR. The
trained models are off unless `ML_DEFAULT_ALGORITHM` selects one, and they
learn from this repository's generated history rather than real sales — so
their accuracy is a measure of the pipeline, not of real-world demand. See
§2's Forecasting section.

**Stack:** Laravel 13 · PHP 8.3 · Inertia v3 · React 19 + TypeScript ·
Bootstrap 5.3 + SCSS · Vite · Pest 4.

---

## 2. Features that exist today

### Authentication (Laravel Fortify)

Configured in `config/fortify.php`; views are Inertia pages registered in
`app/Providers/FortifyServiceProvider.php`.

| Feature | Enabled | Page |
| --- | --- | --- |
| Registration | yes | `auth/register` |
| Login | yes | `auth/login` |
| Password reset | yes | `auth/forgot-password`, `auth/reset-password` |
| Email verification | yes | `auth/verify-email` |
| Two-factor authentication (TOTP) | yes, **confirmation required** | `auth/two-factor-challenge` |
| Passkeys (WebAuthn) | yes, password confirmation required | managed on the security page |
| Password confirmation | yes | `auth/confirm-password` |

After authenticating, Fortify sends the user to `/dashboard` (`fortify.home`).

**Rate limits** (`FortifyServiceProvider::configureRateLimiting`):

- `login` — 5/min, keyed on lowercased email + IP
- `two-factor` — 5/min, keyed on the pending login session id
- `passkeys` — 10/min, keyed on credential id (or session) + IP
- password update — `throttle:6,1` on the route itself

**Password strength** is environment-dependent (`AppServiceProvider`): in
production, minimum 12 characters with mixed case, letters, numbers, symbols,
and an uncompromised (HIBP) check. Locally there is no minimum, so local test
passwords do not reflect production rules.

### Account settings

| Page | Route | Notes |
| --- | --- | --- |
| Profile | `/settings/profile` | Change name and email. Changing the email **clears `email_verified_at`**, forcing re-verification. Also hosts account deletion. |
| Security | `/settings/security` | Password change, two-factor setup, passkey management. Behind `RequirePassword` — the user must re-confirm their password to open it. |
| Appearance | `/settings/appearance` | Light / dark / system theme. |

`/settings` redirects to `/settings/profile`. All three are reached from the
user menu in the topbar — they are deliberately not in the sidebar, which
carries data modules only.

Account deletion requires the current password (`ProfileDeleteRequest`), logs
the user out, deletes the record, invalidates the session, and redirects to `/`.
Passkeys cascade-delete with the user.

### System health

`/system-health` (requires `auth` + `verified`), under the sidebar's **Overview**
group. Everything on it is measured when the page loads; nothing is cached.

| Section | What it tells you |
| --- | --- |
| Dependencies | Database, ML service and BuyAbans API, each probed with a GET, with latency. **A 401 from the back office is a pass** — it is probed unauthenticated, so the question is only "is the host answering". |
| Forecasting models | Every algorithm the ML service offers, which one is being served, and **how far behind the demand each trained checkpoint is**. A stale checkpoint is reported by `/models` as `available: true` but will crash a forward pass, so the gap is shown rather than left to be noticed. |
| Last training run | Each model against its own held-out data, read from `training_summary.json`. |
| Last backtest | Every algorithm on the same held-out windows, from `evaluation.json` — the only comparison that says which to serve. |
| Demand data | All three grains, with a verdict on whether their unit totals agree. They describe the same sales, so disagreement is a fault, not rounding. Checked over the trailing 90 days. |
| Sync stages | The most recent run of each stage, flagged when overdue. |
| Forecast pipeline | The last batch, its duration, and what is awaiting a decision. |
| Queue | Depth, failures, and **the age of the oldest waiting job** — a forecast batch killed by a worker timeout leaves nothing in `failed_jobs`, so an old waiting job is the only symptom. |
| Runtime / Largest tables | What is actually running, and where the data sits. Table sizes need MySQL; on another driver the section says so rather than showing zeroes. |

### Dashboard

`/dashboard` (requires `auth` + `verified`). Eight KPI tiles in two rows, five
charts, a forecast-health card and six shortcuts — all computed on request by
`DashboardService` from the latest synced data. Nothing here is stored or
cached.

**Every window ends at the last day demand was synced for, not today**, and the
page says which days those are. A sync that falls behind would otherwise show a
fortnight of zeros with nothing on screen explaining it.

#### Trading — the headline window (30 days)

| Tile | What it is |
| --- | --- |
| Revenue | `SUM(revenue)` over the window, in the currency named by `BUYABANS_CURRENCY` (default `LKR`). Nothing converts currencies; the code is a label. |
| Units sold | `SUM(sold_qty)` over the same rows. |
| Orders | `SUM(order_count)`. |
| Average order value | Revenue ÷ orders. Its own tile because the two can move in opposite directions, and which one is moving is the first question anyone asks. |

Each carries a 12-week sparkline and a change comparing the last four weeks
with the four before them.

#### Stock position

| Tile | What it is |
| --- | --- |
| Stock on hand (retail) | Stock summed per SKU × `skus.selling_price`. **At retail, not at cost** — `cost_price` is populated for 149 of 10,892 SKUs, so a cost valuation would silently describe 1.4% of the catalogue. |
| Days of cover | Stock on hand divided by the recent daily rate of sale, over SKUs that have **both**. Mixes the back office's real stock with generated demand history, so it demonstrates the calculation rather than describing real coverage. |
| Lines out of stock | Products that sold in the window and now have nothing left. **A demonstration, not a work queue, while demand is seeded** — of 475 SKUs that sold, 321 have a back office stock row genuinely reading zero, but the sales that proved the demand were generated and never decremented that stock. |
| Reorder alerts | Purchase recommendations awaiting a human decision. |

#### The charts

| Chart | What it shows |
| --- | --- |
| Units sold per week | 12 weeks of `sold_qty`. |
| Revenue per week | The same 12 weeks in money. **Two charts, not one with two y-axes** — with two axes the drawing can imply any relationship you like by choosing the scales. |
| Where the money comes from | Top 8 categories by revenue. Ranked by money and not units on purpose: in this data the two orders disagree sharply, and a buying decision is made against revenue. |
| Where it sells | Top 8 warehouses by revenue. Empty at the `channel` and `national` grains, where rows carry no warehouse. |
| Top movers | Top 8 products by units — the units ranking the category chart deliberately is not. |
| How long stock will last | Products bucketed by days of cover: out of stock, under 2 weeks, 2 weeks–2 months, 2–6 months, over 6 months. Each band has a fixed colour so a band emptying out never repaints its neighbours, and empty bands stay on the chart. |

**The cover chart describes only the products that sold in the window**, and
says so under its title. A stocked SKU with no recent sales has no measurable
rate of sale; counting it as "over six months of cover" would report this
dataset's shape rather than the business's, because demand was generated for
163 SKUs out of a 10,892-SKU catalogue and every untouched SKU would pile into
one band.

#### Forecast health

Last run and its status, how many predictions it produced and over what
horizon, how many products are being forecast, and the accuracy so far.

**Accuracy shows "—", and that is correct.** It is only known once a forecast's
horizon has elapsed and `app:score-forecast-accuracy` has graded it;
`forecast_accuracy` has never held a row. The card says so in words rather than
leaving a bare dash.

#### Decisions waiting for you

Six shortcuts, to purchase recommendations, central allocation, forecasts, lost
sales, inventory analytics and the BuyAbans sync — the places this data is
meant to be acted on.

Every window is anchored to the last day demand was **synced**, not to today —
otherwise the charts would show a tail of zeros whenever the sync fell behind.
A metric with no data renders "—" rather than `0`.

### BuyAbans data sync

`/buyabans-sync`, under the sidebar's **Overview** group.

**This is where the application's data actually comes from.** The app forecasts
demand — it does not operate stock. The BuyAbans back office is the system of
record for the catalog, stock levels and sales, and everything shown anywhere
else in the app is pulled read-only from there through its
`/api/forecasting` endpoints.

The page shows four tiles (categories, brands, SKUs and warehouses synced), a
table of how much demand history is held at each location grain, controls for
running a sync, and the full history of every sync attempt — succeeded or
failed, what it fetched, what it wrote, how long it took and, when something
went wrong, why.

| Action | What it does |
| --- | --- |
| **Test connection** | Asks the back office what it holds without pulling anything — row counts and the date range of its orders. The quickest way to tell a credentials problem from an empty dataset. |
| **Sync now** | Runs one stage, or all of them, for a chosen number of days and location grain. Capped at 400 days from this page; a full-history pull belongs on the console command, which has no request timeout to hit. |

The stages run in dependency order — locations, categories, brands, attributes,
products, stock levels, then demand — because demand rows resolve against the
catalog and locations that come before them. A stage that fails stops the run and is
recorded as a failed row; the stages that already succeeded are kept.

Syncing is **idempotent**: re-running over a window already covered corrects
those rows rather than double-counting them. That matters because the nightly
schedule deliberately re-pulls the last 14 days every night — orders get
cancelled and refunded after the fact, which changes past days' demand
retroactively.

**Three location grains.** Demand can be aggregated per warehouse, per selling
channel, or nationally. All three can be synced and coexist; rows are keyed by
grain, so they never overwrite each other, and they are never added together —
each describes the same sales from a different angle.

If a synced SKU has no local counterpart, the demand row is **kept** with the
SKU code retained and no local SKU linked, and the count is shown on the page.
Unmatched demand is still real demand.

Nothing on this page writes back to the back office. Every call is a `GET`.

**What this system still writes.** Nothing that counts as data entry. Every
remaining write is either an operation this application performs on itself, or
your own account:

| Action | What it is |
| --- | --- |
| Run a sync / test the connection | Pulls data in from the back office |
| Start a forecast run | Queues a prediction job |
| Score accuracy | Grades forecasts whose horizon has elapsed |
| Capture a daily snapshot | Materialises one day of the pipeline |
| Capture supplier performance | Computes last month's lead times from existing history |
| Generate recommendations, then accept / modify / reject one | The decision engine, and the human decision it exists to record |
| Profile, password, 2FA, passkeys | Your account |

Suppliers and promotions are **read-only listings** like everything else. Note
the consequence: supplier lead times and promotion windows have no source in the
BuyAbans API and no entry point here, so they are whatever is already in the
database. The reorder engine reads lead times directly, and promotions are a
forecast covariate — if either needs to change, it needs a source.

Demand rows exist only for days that **sold** something — the feed is an
aggregate of orders, so a quiet day produces no row. Everything that reads this
data fills the calendar back in, treating a day with no sale as a day of zero
demand. That is not cosmetic: before it was fixed, forecasts across the catalog
were 91% too high, because a product with four sales in three years was being
read as selling on four consecutive days.

**What the forecasts are actually built from** is controlled by
`FORECAST_DEMAND_SOURCE` (see `server_architecture.md` §4). Set to `buyabans`,
every forecast — the series sent to the ML service, which pairs get forecast,
each SKU's maturity classification and the peer averages a cold-start SKU falls
back to — is computed from synced demand rather than this application's own
stock ledger. Set to `ledger`, everything behaves exactly as it did before the
integration existed.

### Catalog & Inventory (Phase 1 Foundation)

All routes require `auth` + `verified`; no role/permission restrictions exist
yet (see §7). Listed under the sidebar's **Catalog** and **Inventory** groups.

| Module | Route | What it does |
| --- | --- | --- |
| Categories | `/category` | Tree of catalog categories (`parent_id`). Deleting a category with sub-categories or products is blocked; a category can't be moved under its own descendant. |
| Brands | `/brand` | Flat list of product brands. |
| Attributes | `/attribute` | Properties like Size or Color, each with its list of values. **Normalised on the way in:** the back office defines a separate Size/Color/Capacity attribute per configurable product — 426 of its 475 attributes are these — and the sync collapses them onto one attribute per axis, so a size on one product is comparable with a size on another. 52 attributes are held locally, 3 of them flagged forecast-relevant. |
| Products | `/product` | Category + optional brand, `simple` or `configurable` type, model number/year, launch/end-of-life dates. A configurable product is a parent: it is not sellable itself, its variants are. |
| Variants | `/product-variant` | The child items of a configurable product — the thing that actually sells. The Attributes column shows the variant's size, colour or capacity where the back office records one (1,858 of 1,861 do). A standalone simple product has no variant; its SKU points straight at the product. |
| SKUs | `/sku` | The sellable, stock-tracked unit: product, optional variant, SKU code, barcode, cost/selling price, status, first/last stock dates. |
| Warehouses | `/warehouse` | Locations that hold inventory. |
| Inventory | `/inventory` | **Read-only** current stock balance per warehouse/SKU (on hand, reserved, available, incoming, average cost). There is no way to edit a balance directly — see Adjustments below. |
| Stock movements | `/stock-movement` | **Read-only, append-only ledger** of every stock change — the audit trail behind every balance. |
| Adjustments | `/stock-movement/create` | Record `Opening stock`, `Adjustment in`, `Adjustment out`, `Damage` or `Write-off` for a warehouse + SKU — the manual entry point for stock movements that aren't a sale, purchase or transfer. Writes a ledger row and updates the balance in one step. An outbound adjustment that would take on-hand stock negative is rejected with an error toast and nothing is written. An inbound adjustment with a unit cost rolls the balance's average cost forward (weighted average). |
| Stock transfers | `/stock-transfer` | Move stock between two warehouses: Draft → Approved → Dispatched → Received. Dispatching deducts the source warehouse; receiving adds to the destination at the same cost the stock left with. Cancellable from Draft/Approved only. |
| Batches | `/inventory-batch` | **Read-only** FIFO lot ledger — every batch of stock a warehouse/SKU balance is actually made of, oldest first, with received/remaining quantity, unit cost and age in days. This is what every outbound movement consumes from and what future ageing analysis will read. |

Every listing page has search, filters relevant to that module, 20-per-page
pagination, sortable columns, and (where the module supports writes)
edit/delete actions with a confirm dialog before delete.

> **This application is read-only.** It displays data; it does not author any.
> There are no create, edit or delete actions anywhere in it — and no disabled
> ones either. The routes, the controller methods, the form requests, the create
> and edit pages and the service write methods behind them were all removed, not
> hidden, because the BuyAbans back office owns this data and a local edit would
> be silently undone by the next nightly sync, which upserts every row it
> fetches.
>
> To change a product, a category, a warehouse or a supplier, change it in the
> back office and run a sync.
>
> The stock-operation modules — stock movements, stock transfers, purchase
> orders, goods receipts, sales orders and sales returns — keep their listings so
> the history they already hold stays readable, but they are out of the sidebar
> and have no write path at all: stock is operated in the back office.

### Purchasing & Sales (Phase 2)

Same auth rules as above. Listed under the sidebar's **Purchasing** and
**Sales** groups.

| Module | Route | What it does |
| --- | --- | --- |
| Suppliers | `/supplier` | Vendors you buy stock from, each with a repeatable list of SKUs they supply (their SKU code, unit cost, minimum order quantity, order multiple, lead time, and an "is primary" flag — marking one supplier primary for a SKU automatically un-marks any other). A supplier with purchase orders can't be deleted. |
| Purchase orders | `/purchase-order` | Order stock from a supplier into a warehouse: Draft → Approved → Ordered → Partially received/Received, or Cancelled (from Draft/Approved/Ordered only — not once anything's arrived). Only Draft orders can be edited or deleted. Line totals and the order total are computed server-side from quantity × unit cost, never trusted from the form. Approving and ordering are one-click actions from the order's detail page; once Ordered, a "Receive goods" button jumps straight to Goods receipts pre-filled with this order. |
| Goods receipts | `/goods-receipt` | Log what actually arrived against an Ordered or Partially-received purchase order — pick the PO, and every line still outstanding (ordered minus already received) is pre-filled with the remaining quantity and the PO's unit cost, both editable. Posting a receipt writes a `PURCHASE_RECEIPT` ledger entry per line, rolls the balance and a new FIFO batch, and updates the parent PO's received quantity and status. **Read-only, immutable** once posted — no edit or delete. |
| Sales orders | `/sales-order` | Record a sale to be fulfilled from a warehouse: Draft → Confirmed, or Cancelled (Draft only — a confirmed order needs a return, not a cancellation, since stock has already left). Confirming deducts stock via FIFO and captures the actual cost of what was sold onto each line, for margin reporting. Unit price auto-fills from the SKU's selling price when picked; only Draft orders can be edited or deleted. |
| Sales returns | `/sales-return` | Log stock coming back against a Confirmed sales order — pick the order, and every line with quantity still returnable (sold minus already returned) is shown with a quantity input and a Sellable/Damaged condition picker. A sellable line puts stock back at the original sale cost; a damaged line posts the same inbound entry immediately followed by an outbound `DAMAGE` entry, so the on-hand balance doesn't change but both events are on the ledger. **Read-only, immutable** once posted. |

Sales orders and purchase orders are unrelated to any CRM/customer-account
concept — a sales order's "customer" is a plain optional name field, not a
linked record.

### Inventory analytics (Phase 3)

`/inventory-analytics`, under the sidebar's **Inventory** group. One table,
one row per warehouse/SKU combination that currently has a balance, with a
column for every deterministic metric app_plan.md §79 asks for: daily sales
velocity, days of stock, inventory turnover, average stock age (from actual
batches), an ABC revenue-tier badge, a Fast/Slow/Dead-mover badge, and a
basic reorder point (with a red warning marker when available stock has
already dropped to or below it). Filter by warehouse, category, search,
movement speed or ABC class, or tick "Needs reorder only" to see just what's
below its reorder point. Two extra number inputs — **Lookback** (days of
sales history the velocity/revenue figures are computed from, default 30)
and **Safety** (extra days of stock added on top of lead-time demand for the
reorder point, default 7) — let you widen or narrow the analysis window
without touching any data.

This page is entirely **read-only and computed on request** — there is
nothing to create, edit or delete here, and nothing it shows is stored
anywhere; refreshing the page recalculates everything from the current sales
and stock history. It also has no ML or forecasting behind it: every number
is a straight historical calculation, which is the whole point of this phase
per the plan (build deterministic value first, forecast later). Reorder
points only show for SKUs that have a primary supplier configured with a
lead time (in Suppliers or the supplier's default lead time) — without one,
there's nothing to base a lead time on, so the column reads "—".

### Daily snapshots (Phase 4)

`/inventory-daily-snapshot`, under the sidebar's **Inventory** group. One
row per warehouse/SKU/day: opening and closing balance, how much arrived
(received/transferred in), how much left (sold/transferred out), any
adjustment, the resulting inventory value, and how many minutes that day the
SKU had zero available stock. Filter by warehouse, SKU, search, a date
range, or "Stockouts only" to see just the days something ran out.

This history is captured automatically every night just after midnight for
the *previous* day — a day isn't "complete" until it's over, so today is
never snapshotted until tomorrow's run. A **"Capture snapshot"** button on
the page (with an optional date picker) triggers the same capture
immediately, for backfilling a specific past date or checking today's
numbers without waiting for the schedule. Capturing an already-captured date
recomputes and updates that row in place rather than duplicating it — safe
to re-run any time, for example after correcting a stock adjustment that
happened earlier that day.

This table exists to answer a question raw sales figures can't: **did
nothing sell because nobody wanted it, or because there was nothing left to
sell?** Zero sales and zero stock look identical in a sales report but mean
opposite things for reordering — this is the record that tells them apart,
and it's what any future demand-forecasting work will read from instead of
recomputing history itself every time.

### Forecasting (Phase 5 scaffold, Phase 6 cold-start fallback, Phase 7 recommendations, Phase 8 ageing prevention, Phase 9 multi-location optimization, Phase 10 automatic model selection)

**Read this before trusting a predicted number.** These pages are a real,
working pipeline end to end — but the prediction itself comes from one of
two simple statistical baselines, not a trained ML model.
`ml-service/README.md` explains exactly what is and isn't real. Listed
under a new sidebar **Forecasting** group.

| Page | Route | What it does |
| --- | --- | --- |
| Forecast runs | `/forecast-run` | Queue a demand-forecast batch: pick a horizon (7/14/30/60/90/180 days) and optionally restrict it to specific warehouses (blank = every warehouse with inventory). The run starts `Queued`, moves to `Processing` once a background worker picks it up, and ends `Completed` or `Failed` (with an error message shown). Each row shows how many forecasts it produced and which model version made them. |
| Forecasts | `/forecast` | The predictions themselves: predicted quantity with a lower/upper range, a confidence score, which source produced it, and which of the four algorithms was used (a trained model that could not serve a particular SKU is recorded as the baseline that actually ran, never under the model name). Filter by warehouse or SKU. A **"Score accuracy"** button compares every forecast whose window has now elapsed against actual sales recorded in the daily snapshots, and shows the actual quantity and percentage error once scored — forecasts still in the future read "Not due yet." |
| Recommendations | `/inventory-recommendation` | What to buy, how much to hold back, what to clear, or what to transfer from another warehouse instead — computed from the latest forecast for each SKU plus its current/incoming stock, supplier lead time, dynamic safety stock, and real ageing/overstock signals. A **"Generate recommendations"** button re-runs the engine. Each row shows a **Type** badge (Purchase / Reduce purchase / Do not reorder / Transfer stock / Clearance — a Transfer stock row's Warehouse cell also shows which warehouse it would come "from"), Current/Incoming/30-day-forecast/Recommended quantity, a **Risks** column (Stockout, Overstock and Ageing badges — only shown when actually notable), and **Accept**, **Modify** (override the quantity with a reason — Supplier issue, Budget limitation, etc.) or **Reject** actions. A plain `Purchase` needs a completed forecast and a primary supplier; `Reduce purchase`/`Do not reorder`/`Clearance`/`Transfer stock` only need a completed forecast — see Suppliers and Forecast runs above. |
| Central allocation | `/inventory-recommendation/central-allocation` | A read-only rollup for SKUs with an open purchase-type recommendation in **two or more** warehouses at once: combined quantity needed across all of them, plus a per-warehouse breakdown — useful for placing one consolidated supplier order instead of working through each warehouse's row separately. A SKU only needed in one warehouse doesn't appear here; it just needs an ordinary purchase, already visible on the main Recommendations page. |

**A SKU with little or no sales history doesn't just get a zero.** Every
forecast run now classifies each SKU's maturity (Cold start / Early /
Established / Mature / Declining / End of life) from its actual sale
history, and a Cold-start or Early SKU's prediction is built from its peer
group instead of (or blended with) its own thin history — other SKUs in the
same category+size, then brand+category, then category, whichever is the
most specific with any real data. The **Source** badge on the Forecasts page
shows exactly which one was used (`Category + size`, `Brand + category`,
`Category`, `Hybrid` for a blended Early-maturity forecast, or `Cold start`
when literally no peer has any history either — an honest zero, not a
fabricated number). A well-established SKU is unaffected and still forecasts
purely from its own history, same as Phase 5.

**Four forecasting algorithms exist, and the system picks between them per
SKU.** Two are statistical: the original moving-average baseline, and one that
averages demand by day of week — useful for a SKU with real weekly seasonality
(weekend spikes, for example) that a plain moving average smooths away. Two are
trained neural models: a Temporal Fusion Transformer and DeepAR, which learn
from price, category, brand, promotions and stockout history as well as past
sales.

Once at least two algorithms have actually been scored against real outcomes
for a SKU (via "Score accuracy" above), the next forecast run for that SKU
automatically uses whichever has been more accurate historically. A SKU with
less history than that uses the configured default algorithm, and this is never
guessed from a one-sided comparison.

**The trained models are switched off unless you turn them on.** Set
`ML_DEFAULT_ALGORITHM=tft` in `.env` to use the Temporal Fusion Transformer for
established SKUs; left unset, everything behaves exactly as before and only the
statistical baselines run. `php artisan app:forecast-model-status` reports what
the service can serve and which algorithm is actually in use — worth running,
because a misconfigured setup still produces forecasts, just baseline ones.

**Two caveats worth stating plainly before anyone quotes a neural forecast.**
The models are trained on this repository's *generated* three-year history, not
on real sales, so their accuracy measures how well they reproduce the data
generator — it is not evidence about real demand. And a trained model decays as
its training data ages: roughly 8% better than the baselines when fresh, and
*worse* than them by about four months. `php artisan app:train-forecast-model`
retrains them, and runs monthly on the schedule to keep that from happening.

New or nearly-new SKUs never use a trained model at all. Their demand series is
substituted or blended with peer products' history (see below), which a trained
model would misread as this product's own; they stay on the moving-average
baseline.

Queueing a run does not forecast synchronously — it dispatches a queued job
(`php artisan queue:work` or `queue:listen` must be running, same as the
rest of local dev via `composer dev`) that calls out to the separate Python
ML service over HTTP. If that service isn't running, the run ends `Failed`
with the HTTP error recorded, not a silent hang. See
`server_architecture.md` for how to start it.

**The engine now also warns about stock that's aging or piling up, not just
what's running low.** Every recommendation is scored for ageing risk (0–100,
from real batch age and how little future demand exists relative to current
stock) and overstock risk (None/Moderate/Critical, from months of stock on
hand). A SKU that's genuinely urgent to reorder but also shows an elevated
ageing/overstock signal gets a **Reduce purchase** recommendation instead of
a plain Purchase — enough to cover the reorder point, deliberately without
the usual extra buffer. A SKU that isn't urgent at all but is significantly
overstocked or aging gets flagged **Do not reorder**; if it's both very
aged/low-demand *and* unlikely to sell through within 90 days, it gets
flagged **Clearance** instead — a stronger signal to consider promotional
pricing before it becomes genuinely dead stock.

**A shortage in one warehouse is now checked against real surplus in
another before recommending a purchase.** If a SKU is short in one
warehouse and a different warehouse genuinely has more of it than its own
90-day forecast needs, the shortage becomes a **Transfer stock**
recommendation naming the surplus warehouse, instead of a Purchase — moving
stock that already exists instead of buying more. This only happens when
one warehouse's surplus covers the full shortfall by itself; if it doesn't,
the pair keeps its ordinary Purchase/Reduce purchase recommendation
unchanged rather than a partial transfer. Acting on a Transfer stock
recommendation still means manually creating the actual movement on the
Stock transfers page (§2) — accepting the recommendation records the
decision, the same way accepting a Purchase recommendation doesn't
auto-create a purchase order either.

Once accepted or modified, a recommendation is never touched again by a
future "Generate recommendations" run, even if the underlying stock
situation keeps changing — a human decision is treated as final for that
SKU/warehouse pair. There's no "Create purchase order" button yet linking a
decision back to Purchasing, and no "Create stock transfer" button linking
an accepted transfer recommendation back to Stock transfers — see §7.

### Forecasts — the page anyone can read

`/forecast`, under the sidebar's **Forecasting** group. Rebuilt to answer the
three questions a reader actually has before the table starts:

1. **A sentence.** "Over the next 30 days we expect to sell about 27,952 units
   across 161 products", with the realistic range beneath it and what the last
   30 days actually sold, so the number has something to be compared against.
2. **Four tiles** — expected units (with the change on the previous period),
   products covered, confidence, and the forecast period.
3. **"Sales so far, and what comes next"** — twelve weeks of what actually sold,
   then the forecast, with a shaded band for the range the model considers
   likely. The two lines share the last real week so they join up; the band
   opens from that point and never covers weeks that already happened. The
   forecast is drawn as a **weekly average**, because the model produces one
   total for the whole window rather than a shape within it, and the legend says
   so.
4. **"Expected to sell most"** — the eight products with the highest predicted
   demand, as horizontal bars so the product names are readable.
5. **"How these numbers were worked out"** — each basis in a sentence:
   "Based on this product's own sales history", "Brand new — there is no sales
   history yet, so this is a cautious estimate", and so on.

The table underneath uses the same language: **Expected to sell** (with the
likely range), **Over** (the next N days), **How sure** (High/Medium/Low beside
the percentage), **Based on** (the sentence, not the enum name) and **How it
turned out** ("Still in the future" until the horizon elapses).

It lists the **latest run only**. Every run re-forecasts the same pairs, so
listing all of them showed each product once per run it had ever been through —
7,833 rows describing 966 predictions, most of them superseded.

### Advanced intelligence (Phase 10)

Six independent signals, plus a full promotion-tracking module, listed
under a new sidebar **Advanced intelligence** group. None of these change
what the decision engine above recommends — they're separate, additional
lenses on the same underlying data, each honest about what it can and can't
tell you.

| Page | Route | What it does |
| --- | --- | --- |
| Supplier performance | `/supplier-performance` | Real observed lead time, on-time delivery percentage and fill rate per supplier per month, computed from actual purchase-order and goods-receipt dates — compared directly against that supplier's configured lead time, so "they say 15 days but actually take 22" is visible at a glance. A **"Capture month"** button (plus a monthly schedule) computes a chosen month; "Quality issues" always reads **Not tracked** — nothing in this application records a quality/defect signal on a goods receipt to compute it from. |
| Lost sales | `/demand-insights/lost-sales` | Units likely missed to real stockouts: stockout minutes multiplied by the SKU's own normal daily rate on its non-stockout days in the same window. A SKU that was out of stock for the entire window is excluded — there's no in-stock data to estimate a normal rate from. |
| Demand anomalies | `/demand-insights/anomalies` | Days where a SKU's actual demand was a genuine statistical outlier (2+ standard deviations) from its own mean over the window. A SKU with no real day-to-day variability simply has no anomalies — nothing is forced to appear. |
| Product similarity | `/product-relationship/similarity` | Pick a SKU, see its closest peers — the same category+size → brand+category → category fallback hierarchy cold-start forecasting already uses internally, surfaced here as a lookup in its own right. |
| Cannibalization | `/product-relationship/cannibalization` | Pairs of SKUs in the same category whose daily demand moves in opposite directions strongly enough to be worth a look — a statistical candidate, not proof that one is actually stealing the other's sales. |
| Successors | `/product-relationship/successors` | A declining or end-of-life product paired with a newer product in the same category and brand — a heuristic built from real forecast-maturity classifications, since this catalog has no explicit "replaces" relationship. |
| Price elasticity | `/price-elasticity` | How much a SKU's demand actually moved with its real historical selling price, from its own confirmed sales orders. A SKU that has only ever sold at one price is excluded — there's no price variation to learn from. |
| Promotions | `/promotion` | Record a discount that ran (or will run) on a set of SKUs. Once a promotion's end date has passed, a **"View impact"** link shows real average daily demand during the promotion against a matching pre-promotion baseline window of the same length — before/after, not a forecast. A promotion that hasn't ended yet can't show impact: there's nothing real to measure until it actually happens. |

### Theming

Light and dark via `data-bs-theme` on `<html>`, resolved **before first paint**
in `resources/views/app.blade.php` so there is no flash. The choice persists in
an unencrypted `appearance` cookie; `sidebar_state` persists sidebar collapse
the same way. Both are exempt from cookie encryption in `bootstrap/app.php`.

The application uses one shared premium operations design across every module:
an inky navigation rail, sapphire-to-teal intelligence accents, layered
blue-grey canvases, frosted navigation chrome, framed page headers and elevated
content surfaces. Tables, filters, forms, status badges, charts, modals, toasts,
authentication screens and the public landing page all read from the same
light/dark token set in `resources/scss/base/_tokens.scss`, so module pages do
not carry their own visual theme. On small screens the sidebar becomes a modal
navigation panel, header actions wrap to the available width, breadcrumbs
collapse to the current page, and data tables retain their labelled stacked-row
view.

### Public pages

`/` renders the `welcome` marketing page with no layout wrapper.
`/up` is Laravel's health-check endpoint.

---

## 3. User flows

### Register → verify → dashboard

1. `/register` — name, email, password (+ confirmation). Rules come from
   `CreateNewUser` via the `ProfileValidationRules` / `PasswordValidationRules`
   traits.
2. A verification email is sent. With `MAIL_MAILER=log` it lands in
   `storage/logs/laravel.log`, not a real inbox — that is where to look locally.
3. `/verify-email` prompts until the link is followed.
4. Verified users reach `/dashboard`.

### Login (with optional second factor)

1. `/login` — email + password, 5 attempts/minute.
2. If 2FA is confirmed on the account, `/two-factor-challenge` asks for a TOTP
   code or a recovery code.
3. Alternatively, sign in with a passkey.
4. Land on `/dashboard`.

### Enable two-factor

1. Open `/settings/security` — password confirmation is demanded first.
2. Start setup; a QR code and secret key are shown.
3. Enter a code from the authenticator app to **confirm** — because
   `'confirm' => true`, 2FA is not active until this step succeeds.
4. Recovery codes are shown; they are the only fallback if the device is lost.

### Register a passkey

1. `/settings/security`, password already confirmed.
2. Create the passkey; the browser prompts for the platform authenticator.
3. It appears in the list with its authenticator name, creation time and last
   use. `/.well-known/passkey-endpoints` advertises enrol/manage URLs for
   password managers.

### Change password

`/settings/security` → current password + new password + confirmation.
Throttled to 6 attempts/minute. On success a toast confirms; the user is not
logged out.

---

## 4. Local setup

PHP on `PATH` is **8.2**, but this app requires **8.3+**. Prepend Laragon's 8.3
build in any shell that runs `artisan` or `composer`:

```bash
export PATH="/c/laragon/bin/php/php-8.3.33-Win32-vs16-x64:$PATH"
```

First-time setup — installs dependencies, creates `.env`, generates the key,
migrates and builds:

```bash
composer setup
```

Run everything for development (server + queue worker + Vite, concurrently):

```bash
composer dev
```

The app answers on **http://localhost:8000**.

Individually if preferred:

```bash
php artisan serve
php artisan queue:listen --tries=1
npm run dev
```

Local defaults from `.env`: MySQL (`DB_DATABASE=inventory`, Laragon's local
service), database-backed session/cache/queue, `MAIL_MAILER=log`. `php
artisan test` always uses a fresh in-memory SQLite database regardless —
`phpunit.xml` overrides `DB_CONNECTION`/`DB_DATABASE` for the test
environment, so the two never share state. **MySQL enforces a 64-character
limit on index/constraint identifiers that SQLite doesn't** — see
`app_architecture.md` §1n for a real migration bug this caused and how it
was fixed; a migration with a long composite-index name can pass every test
run and still fail the first time it's applied to this local MySQL database
or a MySQL production deployment.

### Seeding realistic sample data

```bash
php artisan db:seed --class=HistoricalTransactionSeeder
```

populates the app with a real, internally-consistent catalog and 3 years of
transaction history — for local demoing and for exercising the
forecasting/analytics pages with data that actually has patterns in it, not
for tests (tests use factories). Not part of the default `db:seed` run (which
only creates the local admin user) — it's slow (bulk-inserts ~600K+ rows) and
depends on `buyabans_staging3` existing, so it's invoked explicitly by class
name. It uses two seeders together:

- **`RealCatalogSeeder`** — pulls real categories, brands, products and SKUs
  from a sibling `buyabans_staging3` MySQL database (a real Bagisto-schema
  e-commerce staging DB reachable on the same local MySQL server via
  cross-database `DB::select('... FROM buyabans_staging3.table')` — no
  separate Laravel connection config needed) rather than inventing sample
  products. Also creates 6 real Sri Lankan regional warehouses, ~15
  distributor suppliers, and assigns each product a lifecycle (established /
  new / declining / end-of-life) with real launch/end-of-life dates.
- **`HistoricalTransactionSeeder`** — replays 3 years of purchasing
  (periodic review + a continuous reorder-point trigger), FIFO goods
  receipts/batches, daily sales with Sri Lanka seasonal demand (AC/fan
  categories peak mid-year), occasional damage/write-offs, weekly sales
  order aggregation, and 4 real Sri Lankan promotional calendar events
  (Avurudu, Vesak, Black Friday, New Year). It replicates the real Services'
  FIFO-cost and weighted-average-cost formulas in PHP memory and bulk-inserts
  in dependency order (`DB::table(...)->insert()`), rather than calling
  those Services hundreds of thousands of times — both seeders are Larastan-
  clean and Pint-clean like any other code in the repo.

Requires the `buyabans_staging3` database to exist on the same local MySQL
server. After seeding, trigger the AI pipeline against the real data:

```bash
php artisan tinker --execute 'Domain\Facades\ForecastRunFacade\ForecastRunFacade::store(["horizon_days" => 30]);'
php artisan app:generate-inventory-recommendations
php artisan app:capture-supplier-performance 2024-01   # repeat per historical month
```

(the ML service, §8 of `server_architecture.md`, must be running for the
forecast run's queued job to succeed).

The seeded demand is not random noise — it is generated from an explicit
causal model (per-SKU base rate, annual seasonality, a day-of-week retail
curve, Sri Lankan festival and payday effects, price elasticity, and real
promotion lift with a post-promotion dip). That structure is what makes the
analytics and forecasting pages show recognisable patterns rather than static,
and it is what the neural training pipeline below has to learn. See
`app_architecture.md` §1o.

### Training the neural forecasting models

Not required to run the app — the statistical baselines need none of it. See
`app_architecture.md` §1p for what these models are and — importantly — what
their accuracy numbers do and do not mean, and §1q for how a trained checkpoint
is actually served.

**The normal way, which is also what the monthly schedule runs:**

```bash
php artisan app:train-forecast-model      # export + retrain, hours on CPU
php artisan app:forecast-model-status     # what is servable, and what is in use
```

Then restart the ML service, since checkpoints are loaded once and memoised.

**The manual equivalent**, when you want to pass training options:

```bash
php artisan app:export-ml-training-data     # ~414K rows to storage/app/ml/
cd ml-service
.venv/Scripts/python.exe -m training.train     # trains DeepAR + TFT on CPU
.venv/Scripts/python.exe -m training.evaluate  # scores all 4 algorithms
```

Training writes two files per model into `ml-service/models/{name}/`, and
**both are needed to serve it**: `best.ckpt` (the weights) and
`dataset_params.pt` (the fitted feature pipeline — the encoders, normaliser and
time origin). A checkpoint without its params is reported as unavailable rather
than loaded, because a model fed differently-encoded inputs returns confident
nonsense instead of an error.

Two options matter more than the rest:

| Option | Why |
| --- | --- |
| `--tft-loss negative_binomial` | Trains the TFT with a count likelihood instead of quantile loss. Its point forecast becomes a mean by construction, rather than a median — and the median of demand that is ~71% zeros is zero. Also makes the TFT directly comparable to DeepAR by removing the loss as a variable. |
| `--holdout-days 365` | Reserves a full year for evaluation instead of the minimal two horizons. Costs a third of the training data, but is the only way to score windows that actually contain a promotion or festival — with the default split the single evaluable window is late July to late August, which contains neither. |

`evaluate.py` reads the split and loss back out of
`ml-service/models/training_summary.json`, so it always rebuilds the
prediction data the way the checkpoint was trained. Passing mismatched flags
by hand is not a failure mode you have to avoid.

`evaluate` prints a WAPE/MAE/Bias table comparing the two trained models
against the two statistical baselines on a held-out window none of them saw
during training, and says plainly when the trained models did **not** beat the
baseline.

Training alone does not change what the app serves: `ML_DEFAULT_ALGORITHM`
decides that, and it is `ewma` unless you set it. Note also that
`--holdout-days 365` is an *evaluation* setting — it buys honest measurement by
spending a year of training data, which makes the resulting checkpoint a year
staler than it needs to be. For a model you intend to serve, train on the
default split so it is as recent as possible, and rely on the already-recorded
year-long backtest for the accuracy question.

---

## 5. Checks before finishing any change

```bash
vendor/bin/pint --dirty --format agent
npm run types:check && npm run lint:check && npm run format:check
php artisan test --compact
```

`composer ci:check` runs the whole set the way CI does.

---

## 6. Test coverage today

Pest 4, in `tests/`:

| Area | File |
| --- | --- |
| Login / logout | `tests/Feature/Auth/AuthenticationTest.php` |
| Registration | `tests/Feature/Auth/RegistrationTest.php` |
| Email verification | `tests/Feature/Auth/EmailVerificationTest.php` |
| Verification resend | `tests/Feature/Auth/VerificationNotificationTest.php` |
| Password reset | `tests/Feature/Auth/PasswordResetTest.php` |
| Password confirmation | `tests/Feature/Auth/PasswordConfirmationTest.php` |
| Two-factor challenge | `tests/Feature/Auth/TwoFactorChallengeTest.php` |
| Dashboard access | `tests/Feature/DashboardTest.php` |
| Profile update / delete | `tests/Feature/Settings/ProfileUpdateTest.php` |
| Security page | `tests/Feature/Settings/SecurityTest.php` |
| Categories (incl. cycle/delete guards) | `tests/Feature/CategoryTest.php` |
| Brands | `tests/Feature/BrandTest.php` |
| Attributes (incl. nested value sync) | `tests/Feature/AttributeTest.php` |
| Products | `tests/Feature/ProductTest.php` |
| Variants (incl. nested attribute-value sync) | `tests/Feature/ProductVariantTest.php` |
| SKUs | `tests/Feature/SkuTest.php` |
| Warehouses | `tests/Feature/WarehouseTest.php` |
| Inventory (read-only) | `tests/Feature/InventoryTest.php` |
| Stock movements (ledger + balance rules) | `tests/Feature/StockMovementTest.php` |
| Suppliers (incl. nested SKU sync) | `tests/Feature/SupplierTest.php` |
| Purchase orders (incl. status workflow) | `tests/Feature/PurchaseOrderTest.php` |
| Goods receipts (incl. PO roll-forward) | `tests/Feature/GoodsReceiptTest.php` |
| Stock transfers (incl. status workflow) | `tests/Feature/StockTransferTest.php` |
| Sales orders (incl. confirm/cost capture) | `tests/Feature/SalesOrderTest.php` |
| Sales returns (incl. sellable/damaged) | `tests/Feature/SalesReturnTest.php` |
| Inventory batches (read-only + FIFO order) | `tests/Feature/InventoryBatchTest.php` |
| Inventory analytics (computed metrics) | `tests/Feature/InventoryAnalyticsTest.php` |
| Daily snapshots (incl. stockout minutes) | `tests/Feature/InventoryDailySnapshotTest.php` |
| Forecast runs (queueing, processing, ML HTTP failure, cold-start/hybrid fallback) | `tests/Feature/ForecastRunTest.php` |
| Forecasts (read-only listing + accuracy scoring) | `tests/Feature/ForecastTest.php` |
| Forecast maturity classification | `tests/Feature/ForecastMaturityServiceTest.php` |
| Demand profile fallback hierarchy | `tests/Feature/DemandProfileServiceTest.php` |
| Inventory recommendations (engine formula, human decisions, idempotency, ageing/overstock/clearance, transfer matching, central allocation) | `tests/Feature/InventoryRecommendationTest.php` |
| Automatic model selection (algorithm choice from real accuracy history) | `tests/Feature/ModelSelectionServiceTest.php` |
| Supplier performance (real lead time/fill rate/on-time capture) | `tests/Feature/SupplierPerformanceTest.php` |
| Demand insights (lost-sales estimation, anomaly detection) | `tests/Feature/DemandInsightsTest.php` |
| Product relationships (similarity, cannibalization, successors) | `tests/Feature/ProductRelationshipServiceTest.php` |
| Price elasticity (log-log regression from real sales) | `tests/Feature/PriceElasticityTest.php` |
| Promotions (CRUD, SKU sync, before/after impact) | `tests/Feature/PromotionTest.php` |
| ML training-data export (columns, promotion windows, overlap de-duplication) | `tests/Feature/MlTrainingDataTest.php` |

The Python ML service has its own `pytest` suite (`ml-service/tests/`) —
outside the Pest suite, run separately inside the service's own virtualenv:

| Area | File |
| --- | --- |
| EWMA baseline | `test_baseline.py` |
| Day-of-week seasonal baseline | `test_seasonal_naive.py` |
| Seasonal baseline's `as_of` backtest parameter | `test_seasonal_naive_as_of.py` |
| WAPE / MAE / Bias metrics | `test_metrics.py` |
| HTTP layer | `test_api.py` |

The training pipeline itself (`training/train.py`, `training/dataset.py`,
`training/evaluate.py`) has **no** automated tests — it is a long-running
offline script whose output is a model checkpoint, and asserting on training
outcomes in CI would be both slow and flaky. Its pure, deterministic parts
(the metrics) are tested; the orchestration is not. Stated here rather than
implied.

Laravel's own tests never depend on the Python service running:
`ForecastRunTest.php` uses `Http::fake()`/`Queue::fake()` throughout.

Browser tests are **not** part of the suite — see
[`app_architecture.md`](app_architecture.md) for why and what to do instead.

---

## 7. Not built yet

Recorded so nobody assumes otherwise:

- **No trained model is serving predictions yet.** A real training pipeline
  now exists (`ml-service/training/`, see `app_architecture.md` §1p) and
  genuinely trains two neural models — DeepAR and a Temporal Fusion
  Transformer — with a WAPE/MAE/Bias backtest across 11 rolling windows. The
  TFT does now beat the best statistical baseline, but **only by ~8% and only
  while freshly trained**: its advantage decays with model age (r = −0.70
  against months since training) and falls below the baselines within about
  four months. The baselines cannot decay, because they recompute from the
  trailing 180 days on every call. **Every prediction the app serves still
  comes from one of those two baselines**; wiring a checkpoint into
  `/forecast-run` is not done, and would also require a retraining cadence
  that nothing in this application currently provides.
- **The trained models learn synthetic data.** They are trained on
  `HistoricalTransactionSeeder` output, so their accuracy figures measure how
  well they recover that seeder's own generative rules — not real demand.
  This is a real test of the pipeline and of the models relative to each
  other; it is **not** evidence of production accuracy and must not be
  presented as such.

  **This has not changed with the BuyAbans integration.** Demand now arrives
  through a real, authenticated API rather than a cross-database query, and
  `sold_qty` and the daily price are genuinely measured — but the staging back
  office held only 55 orders across 14 SKUs, so a multi-year order history was
  *generated into it* (`ForecastingDemandHistorySeeder`, tagged `FCSTH-`) — now
  536,416 orders and 1,003,133 items over four years, across 533 products and
  10 locations. The data has real structure and exercises the whole pipeline
  honestly; it is still not real-world demand. SCM, where the real sales live,
  is not reachable. Nothing derived from this data is evidence about production
  accuracy.
- **`forecast_accuracy` has never been populated.** Nothing has ever been
  scored, which means `ModelSelectionService::chooseAlgorithm()` has always
  fallen through to its `ewma` default and its actual comparison branch has
  never once executed in this database. Its logic is tested
  (`ModelSelectionServiceTest.php`) but has never run on real data. Phase
  10's automatic model selection is therefore live code with no live effect.
  Related: it ranks algorithms on `percentage_error` (a MAPE-style measure),
  which is undefined at zero demand — and ~71% of days in this dataset have
  zero demand. That metric choice needs revisiting before the selection is
  meaningful; `ml-service/training/metrics.py` explains why WAPE is used
  instead on the Python side.
- Phase 6's cold-start fallback is real, working, rule-based logic (not a
  placeholder) — see below — but it is not a trained model either; it just
  decides which real historical numbers to feed the baseline.
- **Multi-location optimization's own real limitations** (Phase 9,
  `app_architecture.md` §1m): transfer matching only ever uses a single
  source warehouse per shortage (no combining surplus from several
  warehouses, no partial transfers), and nothing yet turns an accepted
  `Transfer stock` recommendation into an actual `StockTransfer` record —
  same "decision recorded, execution is a separate manual step" gap Phase 7
  already has with `PurchaseOrder`.
- **Advanced intelligence's own real limitations** (Phase 10,
  `app_architecture.md` §1n): supplier performance's `quality_issue_rate`
  column always reads "Not tracked" — no goods receipt in this schema
  records a quality/defect signal to compute it from; cannibalization
  candidates are an O(n²) comparison within each category, and successor
  candidates classify every candidate SKU's maturity individually (an
  N+1-shaped cost) — both real scaling limits for a very large catalog, not
  hidden from the docs; correlation-based signals (cannibalization, price
  elasticity) surface statistical candidates, never proof of cause and
  effect; and `Promotion` has no status workflow at all — "upcoming/active/
  ended" is derived purely from its dates, so there's no way to mark one
  cancelled independent of deleting it.
- Phase 6's fallback hierarchy only implements three of app_plan.md §27's
  six levels (category+size, brand+category, category) — the `Product`
  (parent-product) and `Global` (all-categories) levels aren't implemented,
  since `ForecastSource` has no dedicated case for either and stretching
  this scaffold's baseline further didn't seem worth it versus waiting for
  a real model. All six `ForecastSource` values are now genuinely reachable
  though (`SKU_HISTORY`, `CATEGORY_SIZE`, `BRAND_CATEGORY`, `CATEGORY`,
  `HYBRID`, `COLD_START`) — see `app_architecture.md` §1j.
- **Dynamic safety stock exists, but only on the Recommendations page, not
  the Analytics page.** `InventoryRecommendationService` (Phase 7) computes
  a real demand-variability-based safety stock (app_plan.md §44) for its
  reorder-point/purchase-quantity math. `InventoryAnalyticsService`'s own
  reorder point (Phase 3, on `/inventory-analytics`) still uses a flat,
  user-adjustable safety-days number — it hasn't been reunified with
  Phase 7's engine. Two reorder points can legitimately show slightly
  different numbers for the same SKU today; not a bug, just two
  not-yet-merged calculations.
- **No PO integration from a recommendation.** Accept/Modify/Reject (the
  human-override workflow, app_plan.md §53) are built; turning an accepted
  recommendation into an actual `PurchaseOrder` row ("Create PO" /
  "Bulk Create PO" on app_plan.md §61's screen mockup) is not — see
  `app_architecture.md` §1k. A decided recommendation is effectively
  terminal for that SKU/warehouse pair until a future phase adds a way to
  reopen it.
- Every recommendation's safety stock uses one fixed ~95% service level for
  every SKU — app_plan.md §45's per-ABC-class service levels (A 98%/B 95%/C
  90%) aren't wired in; see `app_architecture.md` §1k.
- **Ageing risk only uses 2 of app_plan.md §49's 8 listed inputs** (batch
  age and forecasted demand vs. current stock) — "product lifecycle,"
  "replacement pressure," "season" and "recent price changes" aren't
  computable from anything this schema tracks yet, so they're deliberately
  not fabricated. The 50/75-point risk thresholds and the 3/6-month
  overstock bands are round numbers, not calibrated against any real
  "did this become dead stock" outcome data — there isn't any yet. See
  `app_architecture.md` §1l.
- **No product-lifecycle classification** (`NEW`/`GROWTH`/`MATURE`/
  `DECLINING`/`END_OF_LIFE`, app_plan.md §54) — a related but distinct
  concept from Phase 6's per-forecast `ForecastMaturity`, not built.
  Ageing/overstock recommendations use the SKU's own batch age and forecast
  directly rather than a separate lifecycle stage.
- Inventory turnover (on the Analytics page) still uses *current* on-hand
  quantity as a stand-in for average inventory over the period, even though
  Phase 4's daily snapshots now exist and could compute a true historical
  average — `InventoryAnalyticsService` hasn't been updated to read from
  `inventory_daily_snapshots` yet. A natural next improvement, not done as
  part of Phase 4.
- No roles or permissions package; `.ai/rules/architecture.md` requires that
  permission middleware stay **omitted** until one is installed, because an
  undefined middleware alias throws at boot. All module routes are `auth` +
  `verified` only — any authenticated, verified user can read and write every
  module.
- No customer module — a sales order's `customer_name` is a plain optional
  string, not a linked record (`app_plan.md` never defines a `customers`
  table; see `app_architecture.md` §1f).
- No purchase returns (the `PURCHASE_RETURN` movement type exists in the
  schema, reserved for a future module) — not in Phase 2's own roadmap.
  Supplier performance metrics (`supplier_performance_metrics`), also once
  on this list, is now built — see the Advanced intelligence section above
  and `app_architecture.md` §1n.
- No API routes or versioning — `routes/` has `web.php`, `settings.php`,
  `modules.php`, `sales_purchasing.php`, `analytics.php`, `pipeline.php`,
  `forecasting.php`, `inventory_optimization.php`, `advanced_intelligence.php`
  and `console.php` only.
- Dashboard metrics have no data source; the four KPI tiles still show `—`.
- Inventory `reserved_qty` exists in the schema but nothing populates it yet —
  the plan has no stock-reservation/hold concept for sales orders.
  `incoming_qty` **is** populated now, from open purchase orders.
- No searchable/async combobox for large option lists — SKU, product and
  variant dropdowns are plain `<select>`s populated from the full active list.

All ten of `app_plan.md`'s phases are now built. To add a module beyond the
plan's own scope, follow `.ai/rules/module-checklist.md` and the
`module-generation` skill — and read `app_architecture.md` §1a–§1n first for
the patterns already established (nested child rows, a derived/read-only
module, an append-only ledger, cross-module `options()`, the
Inertia-redirect-not-JSON write path, the shared stock-affecting write path,
status-workflow documents, a computed report module with no migration/model
of its own, a nightly- or monthly-scheduled data-capture module, the
queued-job/Python-HTTP forecast scaffold with two selectable algorithms, the
maturity-classification/peer-fallback pattern for cold-start forecasting,
the forecast-driven decision engine with its idempotent regenerate +
respect-human-decisions pattern, extending an existing engine with a new
risk dimension rather than building a parallel one, a two-pass build-then-
match algorithm over an in-memory candidate map when a decision needs
visibility across more than one row at a time, a second read-only aggregate
view living on an existing module's controller instead of becoming its own
module, grouping thematically-related read-only reports into one Service
rather than one-Service-per-report, and a state derived purely from dates
rather than a stored status column when nothing needs a human to set it
explicitly — plus the `whereDate()`-vs-`where()` and
`diffIn*()`/`CarbonInterface` gotchas §1h spells out (both easy to
reintroduce in new date-handling code), and the MySQL composite-index
identifier-length limit §1n documents (invisible against the test suite's
SQLite database, real against a MySQL deployment)).
