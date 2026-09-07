# Inventory Forecasting — presenter notes

Use these notes with
[`inventory-forecasting-management-presentation.pptx`](inventory-forecasting-management-presentation.pptx).
The same notes are embedded in the PowerPoint. The PDF handout and eleven
reusable 1600×900 process infographics are in this folder.

This version reflects the re-audited system on **4 September 2026**.

## Recommended delivery

- **Management version, 20–25 minutes:** slides 1–4, 6–7, 9–15, 18–21.
- **Team version, 35–45 minutes:** all slides, then the live demo on slide 22.
- **Executive version, 10 minutes:** slides 1–4, 7, 11, 13, 15, 21.
- Repeat the core boundary: **BuyAbans is operational truth; this application
  reads, explains and predicts.**
- Say **generated** whenever presenting current volumes or accuracy. The data
  validates integration and modelling behavior, not real business performance.
- Do not present the current recommendation quantities as BuyAbans decisions.
  Their workflow is implemented, but their stock input still comes from the
  legacy local ledger.

## Slide-by-slide talk track

### 1. Inventory Forecasting

“The system has changed materially. It is no longer positioned as a second
stock-management application. BuyAbans remains the system of record. This app
connects to it, curates the data, predicts demand and presents governed
decisions without writing business data back.”

Set expectations: this deck describes the current system as audited on
4 September 2026, including limitations.

### 2. The system now has a clear job

Explain the four verbs:

1. **Read** — authenticated catalog, current stock and sales data.
2. **Trust** — idempotent synchronization, catalog normalization and grain
   reconciliation.
3. **Predict** — dense daily series, four algorithms, uncertainty and evidence.
4. **Govern** — EWMA remains the default, and people retain decisions.

The amber boundary matters: forecasting uses current synced demand;
recommendations and some advanced views still read legacy local data.

### 3. End-to-end: source data to human decision

Walk from left to right:

- BuyAbans supplies catalog, current stock and order outcomes.
- Nine `GET` endpoints are protected by Passport client credentials.
- The sync processes dependencies in order and normalizes the catalog.
- A sparse sales feed is rebuilt into a complete daily time series.
- Laravel selects EWMA, seasonal naive, TFT or DeepAR per SKU.
- The dashboard and forecast page turn the output into understandable evidence.

The data path is one-way. There is no catalog, stock or order write-back.

### 4. One system of record; one prediction layer

“Business corrections belong in BuyAbans. The forecasting application has no
create, edit or delete route for business data — the old write paths were
removed, not hidden.”

The local database still records the application's own operations: sync runs,
forecast runs and results, later accuracy scores, recommendation decisions and
account security changes.

Suppliers and promotions are also read-only. They have no source in the current
BuyAbans API, so their values are frozen until a source is agreed.

### 5. What users can see and do today

Use this as the navigation map:

- **Overview:** Dashboard and BuyAbans Sync.
- **Catalog:** Products, Variants, SKUs, Categories, Brands and Attributes.
- **Inventory views:** current Inventory, Analytics, Batches, Daily Snapshots
  and Warehouses.
- **Supply:** read-only Suppliers.
- **Forecasting:** Forecasts, Runs, Recommendations and Central Allocation.
- **Advanced intelligence:** supplier, demand, product, price and promotion
  views.

Historical stock-operation listings remain reachable by direct URL, but they
are not navigation and have no write routes.

### 6. How a BuyAbans sync becomes usable data

The stages run in dependency order: locations, categories, brands, attributes,
products, current stock, then demand.

- **Authenticated:** a machine identity obtains and caches an OAuth token.
- **Idempotent:** the same period is corrected, never double-counted.
- **Recoverable:** a failed stage is recorded with its reason; completed stages
  remain.

The nightly job re-pulls 14 days at 00:05 because cancellations and refunds can
change earlier demand. “Test connection” diagnoses access without importing;
“Sync now” runs one stage or all stages.

### 7. All three demand views reconcile to the unit

The current four-year synchronized history is:

| Grain | Rows | Locations | SKUs | Units |
| --- | ---: | ---: | ---: | ---: |
| Warehouse | 942,873 | 11 | 535 | 1,826,136 |
| Channel | 571,362 | 4 | 535 | 1,826,136 |
| National | 348,937 | 1 | 535 | 1,826,136 |

All cover 2022-09-04 through 2026-09-04 and contain zero unmatched demand
SKUs. The identical unit total is the reconciliation control.

The active training and serving grain is **warehouse**. The three grains are
alternative aggregations of the same sales and must never be added together.

### 8. The sync rebuilds a usable product tree

The earlier integration flattened Bagisto child variants into ordinary
products. The current sync uses three passes: create parents and standalone
products, link variants, then remove orphans.

Current local shape:

- 363 configurable parents
- 1,857 linked variants
- 9,035 standalone products
- 10,892 SKUs
- 1,929 normalized variant-axis values

The source defines Color, Size and Capacity separately for many configurable
products. The sync collapses 475 source attributes into 52 usable local
attributes, including three common forecast axes.

Source conflicts remain visible: 151 duplicate SKU claims and one childless
parent are reported instead of silently churned.

### 9. A missing row means zero sales — not a missing day

The BuyAbans aggregate contains a row only on days that sold something. That is
a faithful integration record but an incomplete time series.

Before the correction, the system treated selling days as consecutive days and
forecast 51,919 units against 27,132 observed — 91% too high. After rebuilding
the calendar with zero-demand days, the forecast was 27,952, within 3%.

The training export now has 941,116 rows, a median 1,096 days per series, 51%
non-zero observations and no calendar gaps. Series end on the last synchronized
day, never today, because unsynchronized future days are unknown rather than
zero.

### 10. The dashboard is now a management briefing

The headline 30-day view includes revenue, units, orders and average order
value, each with trends and comparison. Stock tiles show retail stock value,
days of cover, lines out of stock and pending recommendation alerts.

Charts explain weekly units and revenue, revenue by category and warehouse,
top movers and cover health. Forecast health shows the latest run, coverage and
accuracy status.

Important interpretation rules:

- Every window ends on the last synchronized demand date.
- Missing values render as “—”, not zero.
- Stock value is labelled **retail** because cost exists for only 149 of 10,892
  SKUs.
- Forecast accuracy stays absent until a forecast horizon has elapsed and the
  actual outcome has been scored.
- The page was reduced from 70.4 seconds to about 2.5 seconds on current data.

### 11. The forecast page answers “what happens next?”

The latest logged example states:

- expected: 31,365 units over 30 days;
- preceding period: 30,445 units;
- likely range: 19,314–44,287;
- coverage: 533 products;
- confidence: Low.

The wide range is a useful answer for an intermittent-demand catalog. The chart
draws the forecast total as a weekly average because the model produces one
30-day total, not four separate weekly predictions. The table uses business
questions: Expected to sell, Over, How sure, Based on and How it turned out.

These are generated-data figures, not production demand.

### 12. How one forecast is produced and governed

1. Read the configured source and one location grain.
2. Build a dense 180-day daily series.
3. Classify maturity and choose own history or the most specific peer fallback.
4. Select a scored winner only when comparable accuracy history exists;
   otherwise use configuration.
5. Run EWMA, seasonal naive, TFT or DeepAR.
6. Persist quantity, lower/upper range, confidence, source and the algorithm
   that actually ran.

Once the horizon ends, the system compares actual demand and calculates WAPE,
MAE and bias. A neural refusal is persisted as the baseline that actually ran,
so a model never receives accuracy credit it did not earn.

### 13. EWMA remains the evidence-based default

In the latest saved 30-day cross-model evaluation, lower WAPE was:

| Algorithm | WAPE |
| --- | ---: |
| EWMA | 19.6% |
| Seasonal naive | 27.6% |
| DeepAR | 29.2% |
| TFT | 111.9% |

Actual demand was 26,592 units; EWMA predicted 28,753.

The final neural training artifacts were regenerated with a 365-day holdout.
That training summary records TFT at 98.3% WAPE with -2.4% bias and DeepAR at
102.0% WAPE with +12.6% bias.

The datasets are generated. These results prove the training and serving path,
not real-world accuracy. `ML_DEFAULT_ALGORITHM=ewma` is therefore the correct
current policy.

### 14. The widened dataset is usable at operating scale

Current measured evidence includes:

- 1.826 million synchronized units at each reconciled grain;
- 2,300 forecasts completed in 2m22s from 2,259 request pairs;
- Dashboard: 70.4s to 2.5s;
- Forecast page: 19.5s to 0.9s;
- distinct-SKU query: 8.92s to 0.44s.

The important operational discovery was the queue timeout. A 60-second
`queue:listen` child limit killed a full forecast silently and left the run at
Processing. The job and local listener now allow 1,800 seconds. Production
still needs a stale-run alarm.

### 15. Recommendation workflow exists; its stock input is not current

The fresh forecast run produced 2,300 predictions. The recommendation engine,
however, iterates `inventories`, a legacy table with 441 rows across 149 SKUs
and six warehouses. Only four current forecast pairs overlap it.

BuyAbans current stock is stored by inventory source, not by warehouse. There
is no honest automatic mapping between the two, so mapping is a business design
decision rather than a technical rename.

Safe claims:

- Purchase / reduce / stop / transfer / clearance logic exists.
- Accept, Modify and Reject are implemented and auditable.
- Nothing automatically creates a purchase order or transfer.

Unsafe claim: the current 197 recommendations or reorder-alert count represent
current BuyAbans stock decisions. They do not.

### 16. Not every screen is on the same freshness path yet

**Current BuyAbans path:** dashboard trade metrics and charts, forecast training
and serving, maturity and peer profiles, catalog, variants, attributes and
current stock.

**Legacy or frozen input path:** inventory analytics, recommendations, lost
sales, anomalies, supplier performance, product relationships, price
elasticity and promotion impact.

Those latter screens are functionally implemented, but their source tables are
not replenished by the BuyAbans sync. Demonstrate their capability and state
their lineage; do not imply all figures share live synchronized data.

### 17. The daily and monthly operating cycle

- 00:05 — synchronize the latest 14 days from BuyAbans.
- 00:15 — capture the previous day's inventory snapshot.
- 00:30 — score forecasts whose horizon has ended.
- 00:45 — refresh recommendations.
- First day, 01:00 — capture supplier performance.
- First day, 02:00 — retrain neural models in the background.

Production needs host cron, a supervised queue worker, a supervised Python
service and alerts. Snapshot and recommendation jobs still follow the legacy
inventory path until stock mapping is resolved.

### 18. Two applications, two runtimes, one analytical experience

- React 19 + Inertia v3 provide the browser experience.
- Laravel 13 owns authentication, orchestration, persistence, queue and
  scheduling through Controller → Facade → Service → Model.
- MySQL stores synchronized data, forecasts, scores, runs and decisions.
- The BuyAbans Laravel/Bagisto application exposes the Passport-protected API.
- Python FastAPI serves EWMA, seasonal naive, TFT and DeepAR on port 8090.

Production must secure, supervise and monitor both external connections.

### 19. Strong boundaries are delivered; authorization is not

Delivered account controls: email verification, reset and confirmation,
TOTP 2FA, passkeys and throttling.

Delivered data controls: read-only business routes, OAuth machine identity,
idempotent sync with run history, grain isolation and reconciliation, named
neural fallback and human decision records.

Open: role-based permissions, controlled account provisioning, real mail,
production cookie policy, secret rotation, API token protection, backups,
monitoring and release validation. Today, every authenticated and verified user
has broad visibility and can run operational triggers.

### 20. What has been verified

- Latest logged Pest suite: 253/253.
- Last fully logged Python suite: 62/62.
- Current source: 36 models, 43 migrations, 34 services, 33 facades and 45
  React pages.
- All three demand grains reconcile exactly with zero unmatched demand SKUs.
- A widened 2,300-forecast run completed in 2m22s.
- Dashboard and forecast-page performance were measured after indexing.

Counts show breadth, not adoption. Generated history is not production demand;
training orchestration is not a CI test; recommendation inputs are legacy; and
production roles and operations remain unverified.

### 21. Five decisions turn the demo into a production service

1. **Connect:** production BuyAbans URL, Passport client, network access and an
   agreed synchronization load.
2. **Map stock:** define inventory-source-to-location truth, then rebuild the
   recommendation inputs.
3. **Control access:** roles, module permissions, operational triggers and
   account provisioning.
4. **Validate models:** use real history and completed scoring horizons, with a
   formal rule for changing the default.
5. **Operate:** hosting, database/cache, backups, mail, workers, cron, Python,
   monitoring and release validation.

Current position: ready for a management demo or controlled pilot, not
production decision automation.

### 22. Eight-minute live demo route

1. **Dashboard** — show management coverage and date anchoring.
2. **BuyAbans Sync** — prove provenance, stage order and run history.
3. **Products + Variants** — show the reconstructed tree and normalized axes.
4. **Forecasts** — show headline, comparison, range, confidence and basis.
5. **Forecast Runs** — show the queued/completed batch and evidence.
6. **Recommendations** — demonstrate Accept/Modify/Reject, then state the stock
   mapping gap clearly.

Close with:

> BuyAbans remains operational truth. This application turns that truth into
> visible performance, explainable forecasts and governed decisions.

## Likely management questions

### Is this a stock-management system?

No. It retains read-only historical modules, but the current product is a
prediction and analytics layer. Catalog, current stock and orders are maintained
in BuyAbans and pulled through a read-only API.

### Does it use real company sales?

The integration and full catalog are real, but the four-year order history used
for the current model evidence was generated in the staging back office because
the actual SCM history is unavailable. Generated rows carry a recognizable
prefix and are removable. Current accuracy figures are not production evidence.

### Why is EWMA still the default when neural models exist?

Because the latest saved cross-model evaluation favors EWMA, and all available
evaluation data is generated. The system can serve TFT and DeepAR, but changing
the default needs real completed-horizon evidence.

### Are recommendations ready to use?

The decision workflow and formulas exist, but the current engine reads a legacy
warehouse inventory table. BuyAbans stock is grouped by inventory source, so a
mapping or redesign is required before recommendation quantities represent the
current business.

### Does the app change BuyAbans data?

No. Its forecasting integration uses `GET` endpoints only. Business-data create,
edit and delete routes were removed from this application.

### Can it be deployed now?

It can support a controlled demo or pilot. Production needs source connectivity,
stock mapping for recommendations, role-based authorization, real-data model
validation, and supervised infrastructure with backups and monitoring.

### What happens if the ML service cannot serve a neural model?

It returns a safe baseline and names the fallback. Laravel stores the algorithm
that actually ran, so the neural model never receives false accuracy credit.

### Why are there three demand grains?

Warehouse, channel and national views support different business questions.
They describe the same sales, so the app isolates one grain at a time. Exact
unit reconciliation detects missing or duplicated data.

## Pre-presentation checklist

- Regenerate the deck if the system changes:
  `powershell -ExecutionPolicy Bypass -File docs/presentations/build-management-presentation.ps1`.
- Open the PowerPoint once and confirm fonts and animations are not needed.
- Confirm the app, queue worker and Python service are running.
- Log in with a verified demonstration user.
- Open Dashboard, BuyAbans Sync, Products, Variants, Forecasts, Forecast Runs
  and Recommendations in separate tabs.
- Run no full-history synchronization or model training during the meeting.
- Label every current number as generated-data evidence.
- Do not claim current recommendations are fed by BuyAbans stock.
- End with the five decisions on slide 21.
