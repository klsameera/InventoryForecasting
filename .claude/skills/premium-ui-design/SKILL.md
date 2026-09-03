---
name: premium-ui-design
description: "Use whenever writing or changing any UI in this application — React/Inertia pages, shared components, layouts, or SCSS. Triggers on building a listing/create/edit page, a form, a table, a card, a modal, a badge, a chart or a dashboard; on styling, spacing, theming or dark-mode work; and any time you are about to type a CSS class name. This project is Bootstrap 5.3 + a global SCSS theme with NO Tailwind, NO shadcn/ui and NO Radix — use this skill to find the existing component or token instead of inventing markup."
---

# Premium UI design system

Bootstrap 5.3.8 + the global SCSS theme in `resources/scss/`. Tailwind, shadcn/ui
and Radix were removed and must not return — see
[`.ai/rules/no-tailwind.md`](../../../.ai/rules/no-tailwind.md).

The target is a premium SaaS admin panel: clean spacing, rounded cards, soft
shadows, subtle gradients, smooth transitions, considered empty and loading
states. Not a bare CRUD skin.

## Before writing markup

1. **Reuse.** Check the kit table below. Most pages need no new component.
2. **Compose.** Bootstrap utilities for layout (`d-flex`, `gap-3`, `row`,
   `col-lg-6`), project classes for appearance (`app-card`, `app-table`).
3. **Extend last.** A genuinely new pattern gets a partial in
   `resources/scss/components/`, imported from `app.scss`. Never inline a hex.

## The kit — `resources/js/components/`

| Need | Component | Notes |
| --- | --- | --- |
| Page title, eyebrow, actions | `PageHeader` | top of every page |
| Content surface | `SectionCard` | `flush` when the body owns its padding |
| Listing table | `DataTable` | sorting, loading, empty, pagination |
| Filter row | `TableFilters` | search + module-specific controls via children |
| Pager | `Pagination` | `DEFAULT_PER_PAGE = 20` |
| KPI tile | `StatCard` | label, value, delta, optional `trend` sparkline |
| Quick action | `ShortcutCard` | dashboards and section landings |
| Dialog | `Modal` | React-owned; never Bootstrap modal JS |
| Destructive confirm | `ConfirmDialog` | required for every delete |
| Field wrapper | `FormField` | label + control + hint + error |
| Inputs | `PasswordInput`, `OtpInput` | |
| State pill | `StatusBadge` | tones: primary/success/danger/warning/info/secondary |
| Empty state | `EmptyState` | icon + title + description + action |
| Busy | `Spinner`, `.app-skeleton`, `.app-loading-bar` | |
| Inline notice | `Alert` | transient: `toast` from `@/lib/toast` |
| Charts | `TrendChart`, `BarChart`, `Sparkline`, `Meter` | |

## Design tokens

Defined in `resources/scss/base/_tokens.scss`, with a `[data-bs-theme='dark']`
override for every one. Read them; never hard-code.

| Group | Tokens |
| --- | --- |
| Surfaces | `--app-surface`, `--app-surface-muted`, `--app-surface-sunken`, `--app-surface-glass` |
| Borders | `--app-border`, `--app-border-strong`, `--app-border-subtle` |
| Text | `--app-text`, `--app-text-muted`, `--app-text-soft` |
| Brand | `--app-brand`, `--app-brand-strong`, `--app-brand-soft` |
| Gradients | `--app-gradient-brand`, `--app-gradient-surface`, `--app-gradient-aurora`, `--app-gradient-sheen` |
| Elevation | `--app-shadow-xs` … `--app-shadow-xl`, `--app-shadow-brand` |
| Radii | `--app-radius-xs` … `--app-radius-2xl`, `--app-radius-pill` |
| Motion | `--app-ease`, `--app-transition-fast/base/slow` |
| Charts | `--app-chart-1` … `--app-chart-6`, `--app-chart-grid`, `--app-chart-axis` |

Bootstrap's own Sass variables are overridden in
`resources/scss/abstracts/_variables.scss` — that file must stay above the
`bootstrap/scss/bootstrap` import in `app.scss`.

## Buttons

| Class | Use |
| --- | --- |
| `btn btn-gradient` | the one primary action on a view |
| `btn btn-surface` | secondary / cancel |
| `btn btn-quiet` | tertiary, toolbars |
| `btn btn-soft-{tone}` | tonal emphasis |
| `btn btn-danger` / `btn-quiet-danger` | destructive |
| `btn btn-icon` | icon-only (always add `aria-label`) |

One gradient button per view. Everything else steps down.

## Page composition

```tsx
<div className="app-stack">
    <PageHeader eyebrow="Catalogue" title="Products" description="…"
        actions={<Link href={…} className="btn btn-gradient"><Plus />New product</Link>} />

    <div className="row g-3">{/* StatCards in col-sm-6 col-xl-3 */}</div>

    <DataTable columns={columns} rows={rows} rowKey={(r) => r.id}
        toolbar={<TableFilters …/>} pagination={meta}
        onPageChange={…} onSortChange={…} loading={loading} />
</div>
```

`app-stack` gives consistent vertical rhythm — prefer it over ad-hoc margins.

## Charts

Hand-rolled SVG, no chart library. The six-slot categorical ramp is validated
for colour-vision separation, lightness band and chroma against **both** the
light and dark chart surfaces. Do not substitute colours casually; if you must,
re-validate.

Hard rules:

- colour by **slot index, never by rank** — filtering must not repaint survivors
- **one y-axis** — two measures of different scale means two charts or indexing
  both to a common base
- legend whenever ≥ 2 series; direct labels **selectively** (endpoint or extreme)
- 2px lines · ≤ 24px bars with a 4px rounded end square at the baseline ·
  ≥ 8px markers with a 2px surface ring · hairline **solid** gridlines
- area fill is the series hue at ~10% opacity, never a saturated block
- labels/values/legends wear text tokens, never the series colour
- no 7th generated hue, no pie for close values, no one-bar bar chart (use a
  `StatCard`), no dashed gridlines

Light-mode slots 2–4 sit under 3:1 contrast on white, so charts must keep their
visible axis labels or a table view — do not strip them for a cleaner look.

## Accessibility & responsiveness

`aria-label` on icon-only controls · `aria-current` on active nav · visible
`:focus-visible` rings (already themed) · respect `prefers-reduced-motion`
(handled globally in `base/_reset.scss`) · tables stack to labelled rows under
`md` via `app-table--stack` · never let the page scroll horizontally.

## Verify

```
npm run types:check && npm run lint:check && npm run format:check && npm run build
```
