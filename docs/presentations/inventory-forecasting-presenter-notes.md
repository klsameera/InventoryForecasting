# Inventory Forecasting presentation notes

Use these notes with
[`inventory-forecasting-management-presentation.pptx`](inventory-forecasting-management-presentation.pptx).
The same notes are embedded in the PowerPoint file. A PDF handout and reusable
infographic PNGs are in this folder.

## Recommended delivery

- **Management version (20-25 minutes):** slides 1-5, 8, 10-21, then Q&A.
- **Team version (30-40 minutes):** present all slides and run the live demo on
  slide 22.
- **Short executive version (10 minutes):** slides 1-4, 11-13, 18-21.
- Keep repeating the central message: the application converts real operations
  into trusted history, then forecasts, then controlled human decisions.
- Say “generated” or “seeded” whenever showing the current model results or UI
  figures. They validate the pipeline, not production business performance.

## Slide-by-slide talk track

### 1. Inventory Forecasting

“This is more than a forecast screen. It is an end-to-end inventory operating
system: transactions create an audited history, that history supports analytics
and forecasts, and the forecast becomes a controlled action. Ten planned phases
are functionally delivered. People still retain approval, and production
controls remain a separate go-live decision.”

Transition: “First, let me summarize the business value in three verbs.”

### 2. A single operating system for stock decisions

“The system helps us observe, understand, and act. Observe means current stock,
incoming stock, FIFO cost, and every movement are visible by warehouse.
Understand means analytics, daily history, forecasts, and seven intelligence
views explain what is happening. Act means the engine recommends buying less or
more, stopping reorder, moving stock, or clearing it—but a person accepts,
changes, or rejects the proposal.”

Call out the amber banner: this is a functional-completeness statement, not a
production-readiness statement.

### 3. Full system overview

Read the diagram left to right:

1. Master data defines products, SKUs, suppliers, and warehouses.
2. Purchases, receipts, transfers, sales, returns, and adjustments create real
   transactions.
3. The append-only ledger and nightly snapshots create trusted history.
4. Analytics, forecasting, and intelligence interpret the history.
5. The decision engine proposes one of five inventory actions.
6. Human review remains mandatory.

### 4. Daily operations to management action

“Setup happens first and is maintained as the business changes. The daily loop
then moves through buying, receiving, warehouse control, selling, and returns.
Those actions build the history used by forecasting and recommendations. An
accepted recommendation is executed through the normal purchase-order or
transfer workflow; the engine never bypasses operational controls.”

### 5. What the application contains

Use this as the module map:

- **Catalog:** categories, brands, attributes, products, variants, and SKUs.
- **Inventory:** warehouses, balances, movement ledger, adjustments, FIFO
  batches, transfers, analytics, and daily snapshots.
- **Purchasing:** suppliers, supplier-SKU terms, purchase orders, and receipts.
- **Sales:** draft/confirmed sales orders and sellable/damaged returns.
- **Forecasting:** runs, forecasts, scoring, recommendations, and central
  allocation.
- **Advanced intelligence:** supplier performance, lost sales, anomalies,
  product relationships, elasticity, and promotions.

Important: all business pages require an authenticated, verified user, but the
application does not yet restrict modules or actions by role.

### 6. The decision workspace users see

Point to the columns: SKU, warehouse, recommendation type, current stock,
incoming stock, 30-day forecast, recommended quantity, reason/risk, status, and
actions. Users can filter the queue and then Accept, Modify, or Reject. A
modification requires a reason so the decision remains explainable.

The screenshot contains seeded local data. Do not present the figures as live
company results.

### 7. Intelligence remains traceable

“These are examples of the intelligence screens. Supplier performance derives
actual lead time, on-time percentage, and fill rate from purchase-order and
receipt dates. Promotion impact compares demand during the promotion with an
equal window immediately before it. Every intelligence view exposes evidence
at row level rather than returning a hidden score.”

### 8. Every stock change follows one controlled path

“Users never edit the inventory balance directly. Opening stock, adjustments,
receipts, transfer dispatch/receipt, confirmed sales, and returns create ledger
events. The same database transaction updates the balance and the FIFO batch
record. Outbound movement is rejected if it would make stock negative. The
nightly snapshot converts the audited history into the daily data forecasting
needs.”

Useful distinction:

- The current balance uses weighted average cost for valuation.
- Confirmed sales consume FIFO batches and store the actual cost sold.

### 9. Operational workflows protect stock integrity

“Status is a business control. A purchase order is editable only while Draft;
receipt is posted separately and is immutable. A transfer removes stock at
Dispatch and adds it at Receipt. A confirmed sale consumes FIFO and cannot be
cancelled as if nothing happened; a return must record the reversal. Damaged
returns are logged in and immediately out, preserving the audit trail without
increasing available stock.”

### 10. Analytics turns inventory into management questions

- Velocity: how quickly the SKU sells.
- Days of stock: how long availability should last.
- Turnover: how productively inventory is moving.
- FIFO age: how long cash has been tied up.
- ABC class: relative revenue importance.
- Mover class: fast, slow, or dead.
- Reorder point: expected demand during supplier lead time plus safety days.

“This page is deterministic and read-only. It recalculates from current history
and does not depend on the ML service.”

### 11. How the forecast is produced today

“The system first chooses the right demand history. A mature SKU uses its own
history. A cold-start SKU uses the most specific peer group available—category
plus size, brand plus category, or category. An early SKU blends peer and own
history. The selector then chooses EWMA, seasonal naive, TFT, or DeepAR per SKU.
The row records the algorithm that actually ran, the quantity, uncertainty
range, confidence, and source.”

“Accuracy is scored only after the forecast horizon has elapsed. Until enough
scored history exists, `ML_DEFAULT_ALGORITHM` controls the default and currently
remains EWMA. A neural model that lacks sufficient history, a known SKU, the
required covariates, or a supported horizon returns a safe baseline rather than
a misleading neural result.”

### 12. The model is operational; accuracy is not yet proven

This is the slide to handle carefully.

“The neural path works end to end: a seeded run processed 441 warehouse/SKU
pairs in about 20 seconds when warm, producing 404 TFT forecasts and 37 safe
fallbacks. That proves integration and serving performance.”

“It does not prove business accuracy. On the current checkpoint’s only honest
30-day scoring window, EWMA had the best WAPE at 32.14%, followed by seasonal
naive at 32.43%, TFT at 35.35%, and DeepAR at 37.49%. A broader rolling
experiment found a freshly trained TFT about 7.8% ahead of the best baseline in
the first three windows, but all of this history was generated by the project
seeder. Therefore EWMA remains the default until real sales history supports a
different decision.”

If asked, WAPE means total absolute forecast error divided by total actual
demand. Lower is better. Bias is tracked separately to expose systematic over-
or under-forecasting.

### 13. Five controlled actions

Inputs include forecast, current and incoming stock, supplier lead time,
dynamic safety stock, MOQ/order multiple, FIFO age, overstock risk, and stock in
other warehouses.

Outputs:

- Purchase
- Reduce purchase
- Do not reorder
- Transfer stock
- Clearance

“The action is a recommendation, not an accounting entry. Accepting it records
the decision. The team still creates and approves the purchase order or transfer
through the normal workflow.”

### 14. Optimize across warehouses before buying

“The system checks owned stock before committing more capital. A transfer is
proposed when one source warehouse can cover the receiving warehouse’s full
need. Remaining purchase recommendations for the same SKU across two or more
warehouses are consolidated in Central Allocation with the warehouse breakdown
preserved.”

Current boundary: it does not split one need across multiple source warehouses
and does not optimize transport cost, route, capacity, or service level.

### 15. Seven advanced signals

Describe each as a prompt for investigation:

- Supplier performance: actual lead time, on-time delivery, and fill rate.
- Lost sales: estimated demand missed during observed stockouts.
- Demand anomalies: dates far from a SKU’s normal demand distribution.
- Product similarity: comparable items derived from product characteristics.
- Cannibalization/successor: products moving against or replacing one another.
- Price elasticity: demand movement across observed price points.
- Promotion impact: before-versus-during demand change.

“Availability of a metric is not the same as statistical certainty. Small
sample counts—especially price points—must be interpreted cautiously.”

### 16. Automation timeline

“At 00:15 the scheduler captures the previous day’s snapshot. At 00:30 it scores
forecasts whose horizons have elapsed. At 00:45 it refreshes recommendations.
On the first day of each month, supplier performance is captured at 01:00 and
the neural models retrain at 02:00 in the background.”

Operational consequence: production needs a host cron, a supervised queue
worker, and a supervised Python service. Manual actions remain available for
backfill and controlled testing.

### 17. One product across two runtimes

“React and Inertia provide the browser experience. Laravel owns validation,
business rules, transactions, and persistence through Form Request, thin
Controller, Facade, Service, and Model layers. MySQL stores operational truth.
The database queue handles forecast jobs. The scheduler maintains the data and
model cadence. Python FastAPI serves the four algorithms through a narrow HTTP
contract.”

Python receives compact daily series and covariates, not raw transaction tables.
It runs separately from `composer dev`. Before any network exposure it must be
privately hosted and protected with matching bearer tokens.

### 18. Controls delivered and still required

Delivered account controls include email verification, password reset,
password confirmation, TOTP 2FA, passkeys, and throttling. Delivered data
controls include immutable posted records, transactional stock writes,
negative-stock protection, server-calculated PO totals, and FIFO sale cost.

The primary production blocker is authorization. Management must define and
the application must enforce who can maintain master data, post operations,
approve recommendations, and administer accounts. Production also needs real
mail, HTTPS/cookie policy, backups, monitoring, supervised processes, and
Python token protection.

### 19. Delivery evidence

“The current source contains 33 models, 38 migrations, 31 Facade/Service module
pairs, and 71 React pages. The latest fully logged suites passed 254 Pest tests
and 62 Python tests. The integrated seeded run processed 441 pairs and generated
197 recommendations.”

These counts demonstrate coverage and integration, not user adoption or model
accuracy. The dashboard KPI shell is still unconnected and deliberately shows
empty states; use detailed module pages in the demo.

### 20. What is delivered versus go-live work

“The left side is functional delivery: authenticated access, immutable stock
history, negative-stock protection, FIFO costing, transactional updates, daily
snapshots, forecast scoring, and human decisions. The right side is go-live
work: roles, controlled registration, hosting/backups, mail, worker/scheduler
supervision, private Python hosting, monitoring, and release validation.”

Ask management to treat every unchecked item as either a required action or an
explicitly accepted risk.

### 21. Operating rhythm and management decisions

Daily reviews should be exception-led. Weekly reviews should cover decisions,
open purchase orders, lost sales, and anomalies. Monthly reviews should cover
suppliers, model accuracy, promotions, and data quality.

The five decisions required now are:

1. Name data owners, transaction operators, and recommendation approvers.
2. Implement roles and decide how accounts are provisioned.
3. Choose production hosting, database/cache/queue, backups, mail, and
   monitoring.
4. Operate the queue, scheduler, Python service, and retraining cadence.
5. Validate forecasts on real sales history before promoting a neural default.

### 22. Eight-minute live demo route

Use this sequence if the app is running:

1. **Products** — show the catalog hierarchy and SKU identity.
2. **Inventory** — show warehouse balance, incoming stock, and average cost.
3. **Stock movements** — prove the append-only audit trail.
4. **Forecasts** — show source, range, confidence, and actual algorithm.
5. **Recommendations** — show risks and Accept/Modify/Reject.
6. **Supplier performance or Promotion impact** — close with a management
   signal.

Avoid opening the dashboard because its KPI props are not connected. End with:

> Transactions become trusted history; history becomes forecasts; forecasts
> become controlled action.

## Likely management questions

### “Is this really AI or machine learning?”

It supports four algorithms: two statistical baselines and two trained neural
models, TFT and DeepAR. The app can serve all four, but EWMA remains the default
until real sales history proves a neural model is more accurate. Advanced
intelligence also includes transparent statistical and rule-based signals.

### “Can we deploy it now?”

The business functionality is substantially complete, but production should
wait for role-based permissions, controlled registration, hosting and backups,
real mail, supervised queue/scheduler/Python processes, monitoring, token
protection, and release validation.

### “Does it order stock automatically?”

No. It generates recommendations and records Accept/Modify/Reject decisions.
The accepted action is then executed through a normal purchase-order or stock-
transfer workflow. This is deliberate human governance.

### “What happens for a new product with no history?”

The system uses the most specific peer group with real history—category plus
size, brand plus category, then category. Early products blend peer and own
history. If no history exists anywhere relevant, it returns an honest zero.

### “Can stock go negative?”

Outbound movement that would make stock negative is rejected, and the entire
transaction is rolled back. Users cannot edit the balance directly.

### “Why is the forecast default still EWMA after building TFT?”

Because model availability and model accuracy are separate decisions. The TFT
serving path works, but the available evidence comes from generated history and
the most recent honest window favors EWMA. Promotion should be based on scored
real-company demand.

### “What data quality matters most?”

Correct SKU and warehouse setup, supplier lead times/MOQs/order multiples,
timely goods receipts, confirmed sales, returns, adjustments, and uninterrupted
daily snapshots. Forecast quality cannot exceed transaction-history quality.

### “What is not included?”

The major gaps are RBAC, production deployment/operations, connected dashboard
KPIs, automated alerts/exports, customer master data, purchase returns, stock
reservation workflow, supplier-quality capture, and advanced transport-cost
optimization.

## Pre-presentation checklist

- Open the PowerPoint in Slide Show mode and confirm the local Segoe UI font
  renders correctly.
- If doing a live demo, start Laravel, the queue worker, Vite, and the Python
  service before the meeting.
- Use a verified demo account; do not expose a production or personal password.
- Pre-open the six demo pages from slide 22.
- Confirm that the current database contains the records you plan to show.
- Keep the PDF available in case the meeting-room computer changes formatting.
- Do not quote seeded quantities, WAPE, supplier figures, or promotion results
  as company performance.
