# No Tailwind — standing constraint

**Globs:** `**`

Tailwind CSS, shadcn/ui and the Radix primitives were removed from this project
on 2026-08-15. They are not coming back. The UI layer is **Bootstrap 5.3 plus
the global SCSS theme in `resources/scss/`**.

## Never

- Tailwind utility classes in JSX/TSX/Blade — `flex`, `gap-4`, `px-6`, `text-sm`,
  `bg-white`, `dark:*`, `md:*`, `space-y-*`, `min-w-0`, `grow`, `size-*`, …
- `tailwindcss`, `@tailwindcss/vite`, `tailwind-merge`, `tw-animate-css`,
  `class-variance-authority`, `prettier-plugin-tailwindcss` in `package.json`
- A `tailwind.config.js`, `@tailwind` directives, `@apply`, or `@theme` blocks
- `components.json` / re-adding `resources/js/components/ui/*` (shadcn)
- `@radix-ui/*` packages — Bootstrap's own components cover this ground

## Instead

| You want | Use |
| --- | --- |
| Layout, spacing, grid | Bootstrap utilities (`d-flex`, `gap-3`, `row`, `col-lg-6`, `mb-4`) |
| A styled surface, table, form, badge | The project classes in `resources/scss/` (`app-card`, `app-table`, `app-field`, `app-badge`) |
| A new visual pattern | Add a partial under `resources/scss/components/` and import it in `app.scss` |
| Dark mode | `data-bs-theme` + the tokens in `base/_tokens.scss` — never a `dark:` variant |
| Conditional classes | `cn()` from `@/lib/utils` (clsx only — there is no class merging) |

**Why:** the whole theme is token-driven so light/dark, brand colour and radius
change in one place. Utility classes sprayed inline defeat that and produce two
competing design systems in one codebase.

If an editor plugin suggests a Tailwind class (`flex-grow-1` → `grow`), ignore
it — that is stale Tailwind IntelliSense, not a project signal.
