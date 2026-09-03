# Frontend — React 19 + Inertia + Bootstrap 5.3 + global SCSS

**Globs:** `resources/js/**`, `resources/scss/**`, `resources/views/**`

Read [`no-tailwind.md`](no-tailwind.md) first. It is the hard constraint; this
file is the positive spec.

## Stack

React 19 · TypeScript · Inertia v3 · Bootstrap 5.3.8 · Sass · lucide-react icons.
Bootstrap's JS bundle is imported once in `resources/js/app.tsx` for its
data-API components (dropdown, collapse, offcanvas). Modals are **React-owned** —
use `@/components/modal`, not Bootstrap's modal JS, so the DOM has one owner.

## Where things live

```
resources/js/
├── pages/{Module}/{index,create,edit}.tsx   one folder per module
├── components/                              shared UI kit (reuse before adding)
├── components/charts/                       SVG charts, no chart library
├── layouts/                                 app / auth / settings shells
├── hooks/  lib/  types/
└── actions/ routes/ wayfinder/              generated — never hand-edit
resources/scss/
├── app.scss                 the only entry point
├── abstracts/_variables     Bootstrap overrides (must precede the bootstrap import)
├── abstracts/_mixins
├── base/_tokens             CSS custom properties + dark overrides
├── layout/  components/  utilities/
```

## The UI kit — check here before writing a component

| Need | Component |
| --- | --- |
| Page title + actions | `PageHeader` |
| Content surface | `SectionCard` |
| Listing table | `DataTable` (+ `TableFilters`, `Pagination`) |
| KPI tile | `StatCard` (optional `Sparkline`) |
| Quick action tile | `ShortcutCard` |
| Dialog | `Modal`, `ConfirmDialog` |
| Field + label + error | `FormField`, `InputError`, `PasswordInput`, `OtpInput` |
| State pill | `StatusBadge` · empty state: `EmptyState` · busy: `Spinner` |
| Inline notice | `Alert` · transient: `toast` from `@/lib/toast` |
| Charts | `TrendChart`, `BarChart`, `Sparkline`, `Meter` |

Adding a new shared component is allowed only when nothing above fits. Style it
with a partial in `resources/scss/components/`, imported from `app.scss`.

## Listing pages — required behaviour

Every `pages/{Module}/index.tsx`:

- filters relevant to the module (search + status + date range where meaningful)
- pagination, **20 per page by default** (`DEFAULT_PER_PAGE`)
- sortable columns where sorting is useful
- loading state, empty state, and per-row actions
- responsive: `DataTable` stacks to labelled rows under `md`

Drive server round-trips with `router.get(url, params, { preserveState: true, replace: true })`
and debounce the search box.

## Forms

`FormField` wraps every control. Surface server errors from Inertia's `errors`
object — never hand-roll client validation that contradicts the FormRequest.
Disable the submit button while `processing` and show a `Spinner` inside it.

## Charts

Hand-rolled SVG only — no chart library. The categorical palette is the fixed
six-slot ramp in `base/_tokens.scss`, validated for colour-vision separation in
both themes. Rules that are not negotiable:

- assign colours **by slot index, never by rank**, so filtering never repaints
- **one y-axis, ever** — two measures of different scale means two charts
- a legend whenever there are ≥ 2 series; direct-label selectively (the endpoint
  or the extreme), never every point
- thin marks: 2px lines, ≤ 24px bars with a 4px rounded data-end square at the
  baseline, ≥ 8px markers with a 2px surface ring, hairline solid gridlines
- text uses text tokens, never the series colour
- never a 7th generated hue — fold into "Other" or facet

## Theming

Light/dark is `data-bs-theme` on `<html>`, set pre-paint in `app.blade.php` and
switched by `useAppearance()`. Components read CSS custom properties
(`var(--app-surface)`, `var(--app-border)`, `var(--app-shadow-md)`) so both
themes stay in sync. Never hard-code a hex in a component.

## Checks before you finish

```
npm run types:check && npm run lint:check && npm run format:check
```
