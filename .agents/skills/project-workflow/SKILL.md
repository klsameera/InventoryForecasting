---
name: project-workflow
description: "Use at the START of every task in this repository, before writing or changing any code, and again before reporting the task finished. Owns the mandatory read-first / preserve / document / log cycle: read the three docs in /docs plus /docs/task_log.md before starting, protect existing working behaviour against regressions, update the affected docs when behaviour, architecture or server setup changes, and append a task_log.md entry when done. Trigger on any request to build, change, fix, refactor, review or configure anything here — including 'add a module', 'fix this bug', 'update the UI', 'change the deploy'. Not optional and not limited to large tasks."
---

# Project workflow — read, preserve, document, log

This is the standing cycle for **every** task in this repository. It is not a
checklist for big changes only; a one-line fix runs it too.

```
1. READ      /docs/app_guide.md · app_architecture.md · server_architecture.md · task_log.md
2. PRESERVE  find what already works; do not regress it
3. BUILD     follow .ai/rules + the matching skill
4. DOCUMENT  update every doc the change invalidates
5. LOG       append one entry to /docs/task_log.md
```

## 1. Read before you start

Before the first edit — and before entering plan mode — read all four:

| File | Why |
| --- | --- |
| [`/docs/app_guide.md`](../../../docs/app_guide.md) | What the app does today, its flows and setup |
| [`/docs/app_architecture.md`](../../../docs/app_architecture.md) | Modules, models, services, data flow, conventions |
| [`/docs/server_architecture.md`](../../../docs/server_architecture.md) | Infra, deploy, env, cron, queues |
| [`/docs/task_log.md`](../../../docs/task_log.md) | What was already done, decided, and why |

The task log is the memory of past decisions. **Read it before proposing a
change** — an approach that was tried and rejected is recorded there, and
repeating it is a regression, not a fresh idea.

Then read the `.ai/rules/` files whose globs cover the paths you will touch.
Those rules are project law and outrank any framework default or bundled skill.

## 2. Preserve what works

The strongest bias in this repo is **do not break working functionality.**

Before changing existing code:

- Read the current implementation end to end. Understand *why* it is written
  that way before deciding it is wrong.
- Search for callers and dependants (`Grep` the symbol) — a signature change is
  never local.
- Check `task_log.md` for earlier attempts at the same area. A previous entry
  may explain a non-obvious constraint the code does not.
- Prefer additive change. Extend, add an optional parameter, add a new method —
  rather than rewriting a path other code relies on.
- Never delete a test to make a change pass. Never widen a type to silence an
  error. Never remove validation because it is inconvenient.

After changing existing code, prove you did not regress it:

```
vendor/bin/pint --dirty --format agent
npm run types:check && npm run lint:check && npm run format:check
php artisan test --compact
```

If a previously passing test now fails, that is a regression to fix — not a test
to update, unless the test encoded the exact behaviour the task asked to change.
Say so explicitly when you do change one.

## 3. Build

Follow the skill that matches the work:

| Work | Skill |
| --- | --- |
| Controller / service / facade / request / resource / model / migration / module route or page | `module-generation` |
| React, Inertia, component, layout, SCSS | `premium-ui-design` |
| Tests | `pest-testing` |
| Browser E2E / visual check | `browser-qa` |
| `.claude/` settings, permissions, MCP servers | `claude-config` |
| Auth, login, 2FA, passkeys | `fortify-development` |
| Frontend calling backend routes | `wayfinder-development` |

## 4. Update the docs

When the change lands, update every doc it invalidates — in the same task, not
later:

| You changed | Update |
| --- | --- |
| A user-visible feature, page, flow, or setup step | `app_guide.md` |
| A module, model, service, facade, route, or convention | `app_architecture.md` |
| Env vars, queues, cron, deploy, infra, external services | `server_architecture.md` |

Docs describe **what is true now**, not what is planned. If the app has no
inventory module yet, the docs say so — they never describe intended features as
existing ones. Do not invent example data, endpoints, or table names to fill a
section; write "none yet" and move on.

If a change invalidates nothing, say so in the task log entry rather than
touching a doc for the sake of it.

## 5. Append to the task log

Every completed task gets exactly one entry appended to the **end** of
[`/docs/task_log.md`](../../../docs/task_log.md). Never rewrite or reorder
earlier entries — the log is append-only history.

Use this shape:

```markdown
## YYYY-MM-DD — Short title

**Requested:** what the user actually asked for, in their framing.

**Changed:** what now exists or behaves differently.

**Key decisions:** the choices made, the alternatives rejected, and why.
Record constraints discovered along the way — a future task will need them.

**Affected files:**
- `path/to/file.php` — what changed in it

**Implementation details:** anything non-obvious a future reader would
otherwise have to rediscover: gotchas, version constraints, ordering
requirements, deliberate omissions and what still remains open.
```

Write the entry for someone with no memory of this conversation. "Fixed the
bug" is useless; "the sidebar highlighted parent routes because
`isCurrentOrParentUrl` matched on prefix, so `/settings` lit up for
`/settings/security` — switched to exact-segment comparison" is the point.

## Definition of done

A task is finished only when all of these are true:

- [ ] All four `/docs` files were read before work started
- [ ] Existing behaviour verified intact (checks above pass)
- [ ] The matching `.ai/rules` and skill were followed
- [ ] Affected docs updated in this same task
- [ ] One entry appended to `/docs/task_log.md`
- [ ] Anything deliberately left undone is stated plainly to the user
