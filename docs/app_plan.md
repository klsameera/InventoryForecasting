Below is the architecture I would use for a **production inventory management + ML stock intelligence platform**.

The main principle is:

> Laravel owns inventory and business decisions. Python owns forecasting and ML. MySQL remains the main business database. ML must work even when a new SKU has no direct relationship to an older SKU.

---

# 1. Technology architecture

```text
┌───────────────────────────────────────────────────────┐
│                    React 19                           │
│                    + Inertia                          │
│                                                       │
│ Dashboards / Inventory / Purchasing / ML / Reports   │
└──────────────────────┬────────────────────────────────┘
                       │
                       ▼
┌───────────────────────────────────────────────────────┐
│                    Laravel 13                         │
│                                                       │
│ Authentication         Product Management             │
│ Inventory              Sales                          │
│ Purchasing             Suppliers                      │
│ Warehouses             Transfers                      │
│ Recommendations        Reports                        │
│ ML Integration         Permissions                    │
│ Audit Logs             Notifications                  │
└───────────────┬───────────────────────┬───────────────┘
                │                       │
                ▼                       ▼
        ┌──────────────┐       ┌──────────────────┐
        │    MySQL     │       │ Queue / Cache    │
        │              │       │ Redis recommended│
        └───────┬──────┘       └──────────────────┘
                │
                ▼
┌───────────────────────────────────────────────────────┐
│                   Python ML Service                   │
│                                                       │
│ Feature Engineering                                   │
│ Demand Classification                                 │
│ Category / Size Profiles                              │
│ Forecasting                                           │
│ Cold Start Prediction                                 │
│ Stockout Prediction                                   │
│ Ageing Prediction                                     │
│ Overstock Detection                                   │
│ Purchase Recommendation Intelligence                  │
│ Model Evaluation                                      │
└───────────────────────────────────────────────────────┘
```

---

# 2. Responsibility separation

## Laravel

Laravel should remain the **source of truth**.

It manages:

```text
Products
SKUs
Variants
Categories
Attributes
Stock
Warehouses
Sales
Purchases
Suppliers
Stock transfers
Stock adjustments
Returns
Purchase recommendations
Users
Permissions
Audit logs
Notifications
Reports
```

Laravel should never depend on Python to complete a normal transaction.

For example, this must still work if ML is temporarily unavailable:

```text
Sale
↓
Stock deduction
↓
Stock movement
↓
Accounting/inventory record
```

---

# 3. Python ML service

Python handles intelligence:

```text
Demand forecasting
SKU forecasting
Category forecasting
Size demand profiles
Cold-start forecasting
Seasonality
Demand trends
Stock-out probability
Ageing probability
Overstock probability
Safety-stock recommendations
Forecast confidence
Model comparison
Model training
```

Python writes calculated results back to Laravel/MySQL.

---

# 4. Laravel architecture pattern

I recommend:

```text
Route
 ↓
Form Request
 ↓
Controller
 ↓
Facade
 ↓
Service
 ↓
Repository / Model
 ↓
Resource
 ↓
React / Inertia
```

Example:

```text
POST /purchase-orders

CreatePurchaseOrderRequest
        ↓
PurchaseOrderController
        ↓
PurchaseOrderFacade
        ↓
PurchaseOrderService
        ↓
PurchaseOrderRepository
        ↓
PurchaseOrder Models
```

This prevents controllers from becoming large.

---

# 5. Laravel domains/modules

Organize the application around business domains.

```text
app/
├── Domains/
│   ├── Product/
│   ├── Inventory/
│   ├── Sales/
│   ├── Purchasing/
│   ├── Supplier/
│   ├── Warehouse/
│   ├── Forecasting/
│   ├── Recommendation/
│   ├── Reporting/
│   └── ML/
│
├── Models/
├── Jobs/
├── Events/
├── Listeners/
├── Notifications/
├── Policies/
└── Console/
```

---

# 6. Product architecture

Your product architecture should support both simple and configurable items.

```text
Product
    │
    ├── SIMPLE
    │       │
    │       └── Default Variant
    │                 │
    │                 └── SKU
    │
    └── CONFIGURABLE
            │
            ├── Variant
            │      └── SKU
            │
            ├── Variant
            │      └── SKU
            │
            └── Variant
                   └── SKU
```

Example:

```text
Product:
iPhone 16 Pro

Variants:

Black / 128GB
Black / 256GB
White / 128GB
White / 256GB
```

Each gets its own SKU.

---

# 7. Core product tables

## categories

```text
id
parent_id
name
code
status
created_at
updated_at
```

Supports:

```text
Footwear
 ├── Running
 ├── Walking
 ├── Casual
 └── Formal
```

---

## brands

```text
id
name
code
status
```

---

## products

```text
id
category_id
brand_id nullable

name
product_type

model_number nullable
model_year nullable

launch_date nullable
end_of_life_date nullable

status

created_at
updated_at
```

`product_type`:

```text
simple
configurable
```

---

# 8. Attributes

## attributes

```text
id
name
code
data_type
forecast_relevant
```

Examples:

```text
Size
Color
Capacity
Gender
Material
Width
```

---

## attribute_values

```text
id
attribute_id
value
sort_order
```

---

# 9. Variants

## product_variants

```text
id
product_id

name
status

created_at
updated_at
```

---

## variant_attribute_values

```text
variant_id
attribute_id
attribute_value_id
```

Example:

```text
Variant #181

Size = 42
Color = Black
```

---

# 10. SKU table

## skus

```text
id
product_id
product_variant_id nullable

sku
barcode nullable

cost_price
selling_price

status

first_stock_date nullable
last_stock_date nullable

created_at
updated_at
```

SKU remains unique.

---

# 11. Do not require SKU relationships

Because of your footwear example:

```text
2024
ABC-001

2025
XZ-532

2026
SK-881
```

They may effectively represent similar demand but have no usable explicit relationship.

Therefore SKU relationships should be **optional**, not part of the forecasting requirement.

ML should instead understand:

```text
Category
Size
Brand
Gender
Price
Product age
Model year
Season
Current sales
```

Whatever attributes exist become features.

---

# 12. Warehouse architecture

## warehouses

```text
id
name
code
address
status
```

If you eventually have branches:

```text
Colombo
Kandy
Galle
Warehouse Central
```

---

# 13. Inventory balances

## inventories

```text
id
warehouse_id
sku_id

on_hand_qty
reserved_qty
available_qty

incoming_qty

average_cost

updated_at
```

Formula:

```text
available_qty
=
on_hand_qty
-
reserved_qty
```

---

# 14. Stock movement ledger

Never calculate historical inventory using only the current quantity.

Create an immutable movement table.

## stock_movements

```text
id

warehouse_id
sku_id

movement_type

quantity
unit_cost

reference_type
reference_id

occurred_at
user_id nullable

notes nullable

created_at
```

Types:

```text
PURCHASE_RECEIPT
SALE
SALE_RETURN
PURCHASE_RETURN
TRANSFER_IN
TRANSFER_OUT
ADJUSTMENT_IN
ADJUSTMENT_OUT
DAMAGE
WRITE_OFF
OPENING_STOCK
```

---

# 15. Inventory daily snapshots

Extremely important for ML.

## inventory_daily_snapshots

```text
id

snapshot_date
warehouse_id
sku_id

opening_qty

received_qty
sold_qty
returned_qty

transfer_in_qty
transfer_out_qty

adjustment_qty

closing_qty
available_qty

stockout_minutes nullable
stockout_flag

inventory_value
```

Why?

Because:

```text
Sold = 0
```

could mean:

```text
Demand = 0
```

OR:

```text
Stock = 0
```

Without inventory availability history, ML will confuse them.

---

# 16. Sales architecture

## sales_orders

```text
id
order_number

warehouse_id
customer_id nullable

order_date
status

subtotal
discount
tax
total

created_at
```

---

## sales_order_items

```text
id
sales_order_id

sku_id

quantity
unit_price
discount
net_amount
cost

created_at
```

---

# 17. Returns

```text
sales_returns
sales_return_items
```

ML should generally calculate:

```text
Net Demand
=
Sales
-
Valid Returns
```

while separately tracking abnormal return rates.

---

# 18. Supplier architecture

## suppliers

```text
id
name

status

default_lead_time_days
minimum_order_value nullable

created_at
```

---

# 19. Supplier SKU details

## supplier_skus

```text
id
supplier_id
sku_id

supplier_sku nullable

unit_cost

minimum_order_qty
order_multiple

expected_lead_time_days

is_primary
status
```

ML/reorder logic needs:

```text
Lead time
MOQ
Pack size
Supplier reliability
```

---

# 20. Purchase orders

```text
purchase_orders
purchase_order_items
goods_receipts
goods_receipt_items
```

Purchase order status:

```text
DRAFT
APPROVED
ORDERED
PARTIALLY_RECEIVED
RECEIVED
CANCELLED
```

---

# 21. Supplier performance

Create:

## supplier_performance_metrics

```text
supplier_id

period

average_lead_time
lead_time_std_dev

on_time_percentage

ordered_qty
received_qty

fill_rate
quality_issue_rate
```

Eventually the system can predict actual lead time instead of trusting:

```text
Supplier says 15 days
```

when historically they actually take:

```text
20–25 days
```

---

# 22. Stock transfers

Tables:

```text
stock_transfers
stock_transfer_items
```

Workflow:

```text
Draft
↓
Approved
↓
Dispatched
↓
Received
```

This becomes very important later for inventory optimization.

Before buying more stock:

```text
Check other warehouse stock
↓
Transfer if possible
↓
Purchase only remaining requirement
```

---

# 23. Inventory batches

For proper ageing analysis:

## inventory_batches

```text
id

warehouse_id
sku_id

source_type
source_id

received_date

received_qty
remaining_qty

unit_cost

expiry_date nullable
```

You can use FIFO allocation.

Ageing is calculated against actual stock batches.

---

# 24. ML database area

Keep ML-generated information separate.

Tables:

```text
ml_model_versions
ml_training_runs
ml_forecast_runs

demand_daily_features
demand_profiles

forecasts
forecast_accuracy

stockout_predictions
ageing_predictions
overstock_predictions

inventory_recommendations
recommendation_actions
```

---

# 25. Demand feature dataset

Python should create records conceptually like:

```text
date
sku_id
warehouse_id

category_id
brand_id

size
gender

model_year

selling_price
discount_percentage

units_sold

stock_available
stockout_flag

days_since_launch

day_of_week
week_of_year
month
quarter

sales_lag_1
sales_lag_7
sales_lag_14
sales_lag_28

rolling_sales_7
rolling_sales_14
rolling_sales_30
rolling_sales_60
rolling_sales_90

rolling_std_30
```

---

# 26. Category-size demand profile

This becomes one of the most important ML concepts for you.

Example:

```text
Footwear
Men
Size 42
```

Historical demand across changing SKUs.

ML doesn't need:

```text
2025 SKU → 2026 SKU
```

It can learn:

```text
Category + Size + Time
```

---

# 27. Demand hierarchy

Forecast hierarchy:

```text
SKU
↓
Product
↓
Brand + Category + Size
↓
Category + Size
↓
Category
↓
Global
```

A new SKU has no history.

So ML falls back.

Example:

```text
New SKU history:
0 days

Category:
Men's Footwear

Size:
42

Brand:
Skechers
```

Use:

```text
Skechers + Men's Footwear + Size 42
```

If insufficient data:

```text
Men's Footwear + Size 42
```

If still insufficient:

```text
Men's Footwear
```

---

# 28. Forecast maturity

Every SKU should have a forecast maturity state.

```text
COLD_START
EARLY
ESTABLISHED
MATURE
DECLINING
END_OF_LIFE
```

### COLD_START

No SKU history.

Prediction relies strongly on:

```text
Category
Size
Brand
Price
Season
New item patterns
```

### EARLY

Maybe 7–30 days history.

Blend:

```text
Category intelligence
+
Early SKU sales
```

### ESTABLISHED

Enough SKU history.

SKU's own behavior becomes dominant.

---

# 29. Example adaptive weighting

Conceptually:

### New SKU

```text
SKU data                 10%
Category + Size data     55%
Brand/category data      20%
Seasonality              15%
```

After several months:

```text
SKU data                 70%
Category + Size data     15%
Brand/category data      10%
Seasonality               5%
```

You shouldn't necessarily hard-code these exact percentages; models can learn them.

---

# 30. Footwear forecasting approach

For footwear I would make two forecasts.

## Forecast A — Total category/model demand

Example:

```text
Skechers
Men
Walking

30-day demand
=
1,000 pairs
```

## Forecast B — Size profile

```text
Size 39      4%
Size 40     10%
Size 41     17%
Size 42     29%
Size 43     23%
Size 44     12%
Size 45      5%
```

Then:

```text
Size 42 predicted demand
=
1,000 × 29%

=
290
```

Current active SKUs compete for that demand.

---

# 31. New SKU demand allocation

Suppose three new models exist:

```text
Model A
Model B
Model C
```

all size 42.

Initially allocate based on:

```text
Historical new-model behavior
Brand
Price
Category
Launch date
Current stock
```

Then actual sales arrive.

After 1–2 weeks:

```text
Model A velocity strong
Model B medium
Model C weak
```

Future allocation adjusts automatically.

---

# 32. ML Service architecture

I recommend a standalone Python service.

```text
ml-service/
├── app/
│   ├── api/
│   ├── config/
│   ├── datasets/
│   ├── features/
│   ├── forecasting/
│   ├── inventory/
│   ├── models/
│   ├── training/
│   ├── evaluation/
│   ├── repositories/
│   └── workers/
│
├── models/
├── tests/
├── requirements/
└── main.py
```

---

# 33. Python service API

Useful internal endpoints:

```text
POST /forecast/run
POST /forecast/sku/{sku}
POST /forecast/category/{category}

POST /training/run

POST /inventory/stockout
POST /inventory/ageing
POST /inventory/overstock

POST /recommendations/generate

GET /models
GET /models/{model}/performance

GET /health
```

But scheduled bulk forecasting should preferably run through background workers rather than synchronous HTTP calls.

---

# 34. Laravel → ML communication

Laravel sends:

```json
{
  "forecast_run_id": 10052,
  "warehouse_ids": [1, 2, 3],
  "forecast_horizon": 90
}
```

Python processes it.

Then Python returns or persists:

```text
forecast_run_id
status
started_at
finished_at
```

Laravel should track:

```text
QUEUED
PROCESSING
COMPLETED
FAILED
```

---

# 35. Don't send huge datasets through HTTP

Avoid:

```text
Laravel
↓
10 million sales rows JSON
↓
Python
```

Instead Python should read prepared data from:

```text
MySQL read-only user
```

or eventually an analytical database.

Laravel starts the job; Python retrieves the data it needs.

---

# 36. Demand classification

Before forecasting, classify inventory.

### Fast moving

Consistent sales.

### Slow moving

Low but regular sales.

### Intermittent

Example:

```text
0
0
0
2
0
0
1
0
```

### Seasonal

Examples:

```text
school shoes
rainwear
holiday items
```

### New

Insufficient historical sales.

### Declining

Demand dropping.

### End-of-life

Should generally not reorder.

Different forecasting strategies can then be used.

---

# 37. Forecast horizons

Store:

```text
7 days
14 days
30 days
60 days
90 days
180 days
```

Result:

```text
expected_qty
lower_bound
upper_bound
confidence_score
```

Example:

```text
SKU SK-881

Next 30 days:

Expected     82
Low          69
High        101

Confidence   81%
```

---

# 38. Forecast table

## forecasts

```text
id
forecast_run_id

warehouse_id
sku_id

forecast_date

horizon_days

predicted_qty

lower_qty
upper_qty

confidence_score

model_version_id

forecast_source
```

`forecast_source`:

```text
SKU_HISTORY
CATEGORY_SIZE
CATEGORY
BRAND_CATEGORY
HYBRID
COLD_START
```

This lets users understand why a forecast exists.

---

# 39. Forecast accuracy

Once actual sales happen:

```text
forecast = 100
actual = 92
```

store the error.

## forecast_accuracy

```text
forecast_id

actual_qty
absolute_error
percentage_error

calculated_at
```

Then compare performance by:

```text
SKU
Category
Brand
Warehouse
Forecast horizon
ML model
```

---

# 40. Inventory decision engine

The ML forecast should feed another layer:

```text
Forecast
+
Current stock
+
Reserved stock
+
Incoming stock
+
Supplier lead time
+
Safety stock
+
MOQ
+
Order multiple
=
Recommendation
```

---

# 41. Inventory position

Use:

```text
Inventory Position
=
On Hand
+
On Order
-
Reserved
```

Then calculate expected future inventory.

---

# 42. Stock-out prediction

Example:

```text
Current available stock = 110

Predicted demand:
5/day

Incoming:
0

Expected stockout:
22 days
```

Supplier needs:

```text
30 days
```

System:

```text
CRITICAL STOCKOUT RISK
```

---

# 43. Reorder point

Concept:

```text
Reorder Point
=
Expected Lead Time Demand
+
Safety Stock
```

Example:

```text
Lead time = 20 days

Expected demand during lead time
= 80

Safety stock
= 30

ROP
= 110
```

---

# 44. Dynamic safety stock

Avoid:

```text
Everyone gets 30-day stock
```

Use:

```text
Demand variability
Forecast error
Supplier variability
Product importance
Stockout cost
Desired service level
```

---

# 45. Service levels

Products may have classifications such as:

```text
A
B
C
```

Example:

```text
A:
98% availability

B:
95%

C:
90%
```

The business can prioritize stock investment where it matters.

---

# 46. ABC classification

Calculate based on revenue/profit/demand.

Example:

```text
A:
top 70–80% inventory revenue

B:
next 15–20%

C:
remaining
```

Can also add XYZ based on predictability.

Example:

```text
AX
AY
AZ
BX
...
```

Where:

```text
X = stable demand
Y = moderate variation
Z = highly unpredictable
```

Very useful for inventory strategy.

---

# 47. Ageing calculation

Age from actual batches.

Example buckets:

```text
0–30
31–60
61–90
91–180
181–365
365+
```

But ML risk should additionally consider future demand.

---

# 48. Ageing prediction

Example:

```text
Current stock:
500

Predicted next 90-day demand:
90

Expected remaining:
410
```

System can warn:

```text
91% probability stock becomes ageing inventory
```

before the stock is already old.

---

# 49. Ageing risk score

Consider:

```text
Current batch age
Stock quantity
Forecast demand
Sales velocity
Product lifecycle
Replacement pressure
Season
Recent price changes
```

Output:

```text
0–100
```

---

# 50. Overstock prediction

Calculate something like:

```text
Months of stock
=
Current Available Qty
/
Average Predicted Monthly Demand
```

Example:

```text
Stock = 400

Forecast = 30/month

Months of stock
= 13.3
```

System:

```text
HIGH OVERSTOCK RISK
```

---

# 51. Inventory recommendation types

The recommendation engine should return actions, not just alerts.

```text
PURCHASE
REDUCE_PURCHASE
DO_NOT_REORDER

TRANSFER_STOCK

PROMOTE
DISCOUNT
CLEARANCE

RETURN_TO_SUPPLIER

REVIEW_PRODUCT
```

---

# 52. Recommendation table

## inventory_recommendations

```text
id

warehouse_id
sku_id

recommendation_type

current_qty
incoming_qty

forecast_30d
forecast_60d
forecast_90d

recommended_qty

recommended_action_date

stockout_risk
overstock_risk
ageing_risk

confidence_score

reason

status

created_at
```

Statuses:

```text
NEW
REVIEWED
ACCEPTED
MODIFIED
REJECTED
COMPLETED
```

---

# 53. Human override

Example:

ML:

```text
Purchase:
300
```

Manager chooses:

```text
Purchase:
180
```

Save:

```text
original_recommendation = 300
manager_decision = 180
```

Optional reason:

```text
Supplier issue
Model being replaced
Promotion cancelled
Budget limitation
Market knowledge
```

This becomes valuable learning data.

---

# 54. Product lifecycle detection

ML/business rules calculate:

```text
NEW
GROWTH
MATURE
DECLINING
END_OF_LIFE
```

Inputs:

```text
days since first sale
sales trend
recent velocity
historical peak
model year
stock level
```

This prevents old models from being reordered based only on historical success.

---

# 55. Main application navigation

```text
Dashboard

Catalog
 ├── Products
 ├── Categories
 ├── Brands
 ├── Attributes
 ├── Variants
 └── SKUs

Inventory
 ├── Inventory Overview
 ├── Stock by Warehouse
 ├── Stock Movements
 ├── Adjustments
 ├── Stock Transfers
 └── Inventory Batches

Sales
 ├── Orders
 ├── Returns
 └── Sales Analysis

Purchasing
 ├── Purchase Orders
 ├── Suggested Purchases
 ├── Goods Receipts
 └── Purchase History

Suppliers
 ├── Suppliers
 └── Supplier Performance

Intelligence
 ├── Demand Forecast
 ├── Stockout Risk
 ├── Overstock Risk
 ├── Ageing Risk
 ├── Recommendations
 └── Product Lifecycle

ML
 ├── Forecast Runs
 ├── Model Performance
 ├── Forecast Accuracy
 └── Training Runs

Reports

Settings

Audit Logs
```

---

# 56. Main dashboard

Top cards:

```text
Inventory Value

Available Units

30-Day Forecast Sales

Recommended Purchase Value

Potential Stockout SKUs

Overstock Value

Ageing Value
```

Then:

```text
Inventory Health
```

Example:

```text
Healthy          Rs. 92M
Stockout Risk    Rs. 11M
Overstock        Rs. 18M
Ageing           Rs. 7M
```

---

# 57. Urgent recommendations

Dashboard section:

```text
CRITICAL

Skechers XYZ Size 42
Stockout expected in 8 days

Recommended:
Purchase 75

────────────────────

Skechers AAA Size 45

Current: 130
30-day forecast: 6

Overstock Risk: 96%

Recommended:
Do not reorder
```

---

# 58. Forecast page

Filters:

```text
Date range
Warehouse
Category
Brand
Product
SKU
Size
Demand class
```

Chart:

```text
Historical Sales
        +
Forecast
        +
Confidence range
```

---

# 59. SKU intelligence page

Example:

```text
SKU
SK-2026-881

Category
Men Footwear

Size
42

Lifecycle
EARLY

────────────────────

Current Stock     85

30d Forecast      73
60d Forecast     151
90d Forecast     221

Confidence        76%

Forecast Source
Category + Size + Early SKU Data

────────────────────

Stockout Risk
64%

Overstock Risk
8%

Ageing Risk
5%
```

---

# 60. Size intelligence dashboard

This should be a dedicated feature for your use case.

Example:

```text
MEN'S FOOTWEAR SIZE DEMAND

Size      Demand Share

39          3%
40          9%
41         17%
42         30%
43         24%
44         12%
45          5%
```

Filters:

```text
Category
Brand
Warehouse
Period
Gender
```

This will be extremely useful during purchasing.

---

# 61. Purchase recommendation screen

Example:

```text
SKU       Current  Incoming  30d Forecast  Recommended

A         20       0         90            100
B         300      0         25              0
C         50       100       80              0
D         10       0         55             75
```

Actions:

```text
Accept
Modify
Reject
Create PO
Bulk Create PO
```

---

# 62. Scheduler

Laravel scheduler:

### Every hour

```text
Synchronize inventory metrics
Check critical stockouts
```

### Nightly

```text
Generate inventory snapshots

Aggregate sales

Prepare ML features

Run short-term forecast

Calculate:
stockout
overstock
ageing

Generate recommendations
```

### Weekly

```text
Retrain forecasting models

Recalculate demand classification

Update product lifecycle

Update size demand profiles
```

### Monthly

```text
Deep model evaluation

Model comparison

Long-range forecasting
```

---

# 63. Event architecture

Example:

```text
GoodsReceived
↓
UpdateInventory
↓
CreateStockMovement
↓
UpdateInventoryMetrics
```

Sale:

```text
SaleCompleted
↓
DeductInventory
↓
CreateStockMovement
↓
QueueDemandMetricsRefresh
```

Transfer:

```text
TransferReceived
↓
UpdateDestinationStock
↓
CreateStockMovement
```

---

# 64. Laravel queues

Jobs such as:

```text
GenerateDailyInventorySnapshotJob

CalculateInventoryMetricsJob

StartForecastRunJob

GeneratePurchaseRecommendationsJob

CalculateAgeingRiskJob

CalculateStockoutRiskJob

CalculateOverstockRiskJob

SendInventoryAlertsJob
```

---

# 65. ML model lifecycle

```text
Collect Data
 ↓
Clean
 ↓
Feature Engineering
 ↓
Train
 ↓
Validate
 ↓
Compare Models
 ↓
Register Best Model
 ↓
Deploy
 ↓
Predict
 ↓
Capture Actual Results
 ↓
Measure Accuracy
 ↓
Retrain
```

---

# 66. Model versioning

## ml_model_versions

```text
id

name
version

model_type

training_start_date
training_end_date

training_rows

accuracy_metrics

feature_schema_version

model_path

status

created_at
```

Never overwrite models.

Use:

```text
v1
v2
v3
```

This allows rollback.

---

# 67. Forecast reproducibility

Each forecast run should know:

```text
model_version
training_data_period
feature_version
forecast_date
parameters
```

If management asks:

> Why did the system recommend 500 units on August 10?

you should be able to reproduce the answer.

---

# 68. Forecast confidence

Confidence should consider:

```text
Historical volume
Data completeness
Forecast error
New SKU status
Demand volatility
Stockout contamination
```

Example:

```text
Prediction: 100

Confidence: 92%
```

versus:

```text
New SKU

Prediction: 100
Confidence: 53%
```

For low confidence:

```text
Recommended range:
60–140
```

rather than pretending `100` is exact.

---

# 69. Data quality module

ML quality depends on business data quality.

Create validations for:

```text
Missing category

Missing size

Invalid stock

Negative quantity

Missing warehouse

Duplicate SKU

Invalid model year

Sales without SKU

Missing selling price
```

Dashboard:

```text
ML DATA QUALITY

Complete:
96%

Missing Size:
122 SKUs

Missing Category:
3 SKUs
```

---

# 70. Permissions

Roles could include:

```text
Super Admin
Inventory Manager
Purchasing Manager
Warehouse Manager
Store Manager
Sales User
Analyst
Viewer
```

Permissions:

```text
inventory.view
inventory.adjust

purchases.create
purchases.approve

forecasts.view

recommendations.approve

ml.train

settings.manage
```

---

# 71. Audit logs

Important actions should record:

```text
user
action
entity
old values
new values
date/time
IP
```

Especially:

```text
stock adjustments
PO approvals
recommendation overrides
supplier changes
product changes
manual ML runs
```

---

# 72. Alerts

Laravel notifications:

```text
Stockout expected

Ageing risk

Overstock

Supplier delay

Unexpected demand spike

Forecast confidence low

Unusual stock movement
```

Channels can later include:

```text
App
Email
WhatsApp
Push
```

---

# 73. Reporting

Core reports:

```text
Inventory valuation

Inventory ageing

Dead stock

Slow-moving items

Fast-moving items

Stockout history

Lost-sales estimate

Forecast accuracy

Purchase recommendation accuracy

Size demand analysis

Category demand analysis

Warehouse demand

Supplier lead-time performance

Stock transfer opportunities

Inventory turnover

Days inventory outstanding
```

---

# 74. API security between Laravel and Python

Use private networking plus service authentication.

Conceptually:

```text
Laravel
    │
Internal authenticated request
    ▼
ML Service
```

Python service should not be publicly accessible if unnecessary.

---

# 75. Deployment architecture

Recommended production structure:

```text
                    Internet
                       │
                       ▼
                    Nginx
                       │
                       ▼
             Laravel + React App
               │       │
               │       └──── Redis
               │
               ├──── MySQL
               │
               └──── Laravel Workers


Private Network
      │
      ▼
Python ML Service
      │
      ├── ML Worker
      ├── Model Storage
      │
      └── Read-only MySQL access
```

---

# 76. Infrastructure separation later

Initially:

```text
Server 1
Laravel
Nginx
Queue
Python
MySQL
Redis
```

is acceptable for development/small production.

Later:

```text
App Server
Database Server
ML Server
Redis
Object Storage
```

can be separated.

---

# 77. Development phases

## Phase 1 — Foundation

Build:

```text
Authentication

Users
Roles
Permissions

Categories
Brands
Attributes

Products
Variants
SKUs

Warehouses
Inventory

Stock movements
Stock adjustments
```

---

# 78. Phase 2 — Sales and purchasing

Build:

```text
Sales

Returns

Suppliers

Purchase Orders

Goods Receiving

Stock Transfers

Inventory Batches
```

---

# 79. Phase 3 — Inventory analytics

Before ML, calculate deterministic metrics.

```text
Daily sales velocity

Days of stock

Inventory turnover

Stock age

ABC classification

Fast movers

Slow movers

Dead stock

Basic reorder point
```

This gives immediate business value.

---

# 80. Phase 4 — Data pipeline

Build:

```text
Daily snapshots

Demand aggregation

Stockout history

ML feature dataset

Data quality validation
```

Without this phase, ML will eventually become unreliable.

---

# 81. Phase 5 — ML Forecast V1

Build:

```text
Python ML service

Historical SKU forecasting

Category forecasting

Category + size forecasting

7/30/60/90-day predictions

Confidence scores

Forecast accuracy tracking
```

---

# 82. Phase 6 — Cold-start intelligence

This is one of the most important phases for you.

Build:

```text
New SKU recognition

Category-size profiles

Brand-category profiles

Model-year features

Launch-age features

Automatic fallback forecasting
```

No SKU relationship required.

---

# 83. Phase 7 — Inventory optimization

Add:

```text
Stockout prediction

Dynamic safety stock

Reorder point

Recommended quantity

Recommended purchase date

MOQ support

Pack-size support

Supplier lead-time support
```

---

# 84. Phase 8 — Ageing prevention

Add:

```text
Future ageing prediction

Overstock prediction

Sell-through estimates

Do-not-reorder recommendations

Clearance recommendations

Purchase reduction recommendations
```

---

# 85. Phase 9 — Multi-location optimization

Add:

```text
Warehouse demand forecasting

Branch-specific size demand

Transfer recommendation

Central allocation optimization
```

Example:

```text
Colombo has 120
Needs 30

Kandy has 5
Needs 50

Recommendation:

Transfer 35
Colombo → Kandy
```

instead of buying another 35.

---

# 86. Phase 10 — Advanced intelligence

Later:

```text
Price elasticity

Promotion impact

Product cannibalization

Successor detection

Automatic product similarity

Supplier lead-time prediction

Lost-sales estimation

Demand anomaly detection

Automatic model selection
```

---

# 87. Testing strategy

Laravel:

```text
Unit tests
Feature tests
Integration tests
Permission tests
Inventory transaction tests
Concurrency tests
```

Especially test:

```text
sale stock deduction

purchase receiving

stock transfer

returns

negative stock prevention

duplicate processing

PO approval
```

ML:

```text
Dataset validation
Feature validation
Backtesting
Forecast accuracy tests
Model regression tests
Cold-start tests
```

---

# 88. Critical inventory rules

Never allow inventory to be modified directly.

Bad:

```php
$inventory->quantity -= 10;
```

Business flow should always generate:

```text
Business Transaction
+
Stock Movement
+
Inventory Balance Update
```

This creates an auditable ledger.

---

# 89. Critical ML rule

Don't ask ML:

> How many units should we buy?

directly.

Separate:

```text
ML
↓
Predict Demand
```

from:

```text
Business Optimization Engine
↓
Decide Purchase Quantity
```

Because purchasing depends on:

```text
money
supplier MOQ
lead time
stock
warehouse capacity
company policy
```

not only ML.

---

# 90. Final intelligence architecture

Your complete decision pipeline becomes:

```text
                         SALES
                           │
                           ▼
                     DEMAND HISTORY
                           │
          ┌────────────────┼────────────────┐
          │                │                │
          ▼                ▼                ▼
      SKU HISTORY     CATEGORY HISTORY    SIZE HISTORY
          │                │                │
          └────────────────┼────────────────┘
                           │
                           ▼
                    PYTHON FORECAST
                           │
                           ▼
             Expected Future Demand
                           │
                           ▼
                INVENTORY SIMULATOR
                           │
             ┌─────────────┼──────────────┐
             │             │              │
             ▼             ▼              ▼
          CURRENT       INCOMING       LEAD TIME
           STOCK          STOCK
             │             │              │
             └─────────────┼──────────────┘
                           │
                           ▼
                 INVENTORY OPTIMIZER
                           │
            ┌──────────────┼───────────────┐
            ▼              ▼               ▼
        PURCHASE        TRANSFER        HOLD
            │
            ├──────────────┐
            ▼              ▼
        DISCOUNT       DO NOT REORDER
```

---

# 91. The final system should answer this

Instead of:

```text
SKU ABC

Quantity:
75
```

the system should show:

```text
SKU
SK-2026-881

Category
Men's Walking Footwear

Size
42

Current Stock
75

Incoming
20

───────────────────────

Forecast

7 days        20
30 days       84
60 days      167
90 days      238

Confidence:
82%

Forecast Source:
Category + Size + SKU History

───────────────────────

Supplier Lead Time:
21 days

Expected Lead-Time Demand:
61

Safety Stock:
24

Recommended Inventory:
85

───────────────────────

Recommended Purchase:
0

Because:
Current + Incoming = 95
Required = 85

───────────────────────

Stockout Risk:
11%

Overstock Risk:
8%

Ageing Risk:
4%
```

And another item:

```text
Size 45

Current Stock:
185

30-day Forecast:
7

90-day Forecast:
21

Ageing Risk:
94%

Recommendation:

DO NOT REORDER

Transfer:
35 units

Remaining estimated sell-through:
7.1 months

Consider:
10–15% promotion
```

That should be the **actual final goal** of the platform.

The most important foundation to get right from day one is the combination of **immutable stock movements + daily stock snapshots + clean category/size attributes + SKU-level sales history**. Once those four are reliable, the Python models can improve continuously even when yearly product models and SKUs keep changing.
