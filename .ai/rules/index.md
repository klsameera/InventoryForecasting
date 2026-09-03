# Project rules index

Committed, shared rules for this application. Read every rule file whose globs
cover the paths you are about to touch, **before** you write code.

These rules are project law. Where they disagree with a framework default, a
Boost guideline, or a bundled skill, **these win** — call out the conflict and
follow this file.

| Rule file | Applies to |
| --- | --- |
| [`workflow.md`](workflow.md) | `**` — the standing per-task cycle, no exceptions |
| [`architecture.md`](architecture.md) | `app/**`, `domain/**`, `routes/**`, `database/migrations/**` |
| [`frontend.md`](frontend.md) | `resources/js/**`, `resources/scss/**`, `resources/views/**` |
| [`no-tailwind.md`](no-tailwind.md) | `**` — standing constraint, no exceptions |
| [`module-checklist.md`](module-checklist.md) | Any new or changed CRUD module |

## Start here, every task

Read [`workflow.md`](workflow.md) first. Before any edit you must have read the
four documents in [`/docs`](../../docs/):

| Doc | Answers |
| --- | --- |
| [`app_guide.md`](../../docs/app_guide.md) | What the app does, its flows, how to run it |
| [`app_architecture.md`](../../docs/app_architecture.md) | Modules, models, services, data flow, conventions |
| [`server_architecture.md`](../../docs/server_architecture.md) | Infra, deploy, env, cron, queues |
| [`task_log.md`](../../docs/task_log.md) | What was already done and decided |

Finishing a task means updating the docs the change invalidated and appending a
`task_log.md` entry — not just passing the checks.

## The three things people get wrong here

1. **Skipping the docs and the task log.** They record decisions the code does
   not explain, including approaches already tried and rejected. See
   `workflow.md`.
2. **Controllers call Facades, never Services.** A controller that injects a
   service, or holds a query, is wrong. See `architecture.md`.
3. **There is no Tailwind in this project.** Bootstrap 5.3 + the global SCSS
   theme only. See `no-tailwind.md`.
