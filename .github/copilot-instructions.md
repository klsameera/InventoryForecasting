# Copilot instructions

Read [`../AGENTS.md`](../AGENTS.md) first — it is the canonical instruction file
for this repository, and its "Project rules" section overrides every framework
default. The detailed rules live in [`../.ai/rules/`](../.ai/rules/).

The two things most often got wrong here:

1. **Backend is Facade → Service → thin Controller.** Controllers only render
   Inertia pages, accept `FormRequest`s, call `Domain\Facades\{Module}Facade`,
   and return API Resources/JSON. All business logic lives in
   `domain/Services/{Module}Service/`. No Action classes, no repositories, no
   service injected into a controller.

2. **There is no Tailwind.** The UI is Bootstrap 5.3 plus the global SCSS theme
   in `resources/scss/`. Never suggest Tailwind utilities, `@apply`,
   shadcn/ui or `@radix-ui/*`. Reuse the components in
   `resources/js/components/` and the tokens in `resources/scss/base/_tokens.scss`.
