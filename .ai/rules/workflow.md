# Standing workflow — read, preserve, document, log

**Globs:** `**` — every task, every file, no exceptions.

This is project law, not advice. It applies to a one-line fix as much as to a
new module. The `project-workflow` skill is the how-to; this file is the rule.

```
1. READ      /docs/{app_guide,app_architecture,server_architecture,task_log}.md
2. PRESERVE  find what already works; do not regress it
3. BUILD     follow the .ai/rules and skill that match the paths in scope
4. DOCUMENT  update every doc the change invalidates
5. LOG       append one entry to /docs/task_log.md
```

## 1. Read first

Before the first edit, and before entering plan mode, read all four docs in
`/docs/`. Then read the rule files whose globs cover the paths you are about to
touch.

`task_log.md` is the record of past decisions. An approach recorded there as
tried and rejected must not be proposed again without addressing the reason it
was rejected.

## 2. Preserve

Existing working behaviour is the thing most worth protecting in this
repository.

- Read the current implementation end to end before changing it.
- `Grep` for callers before changing any signature.
- Check the task log for prior work in the same area.
- Prefer additive change to rewriting a relied-upon path.
- Never delete a test, widen a type, or remove validation to make a change pass.

Prove no regression before finishing:

```
vendor/bin/pint --dirty --format agent
npm run types:check && npm run lint:check && npm run format:check
php artisan test --compact
```

A previously passing test that now fails is a regression to fix — not a test to
rewrite, unless it encoded exactly the behaviour the task changed. Say so
explicitly when that is the case.

## 3. Document

| Changed | Update |
| --- | --- |
| User-visible feature, page, flow, setup step | `docs/app_guide.md` |
| Module, model, service, facade, route, convention | `docs/app_architecture.md` |
| Env var, queue, cron, deploy, infra, external service | `docs/server_architecture.md` |

Docs state what is true **now**. Never describe planned work as existing. Never
invent sample data, endpoints or table names to fill a section — write "none
yet". This is the same constraint as the output-discipline rule in `CLAUDE.md`.

## 4. Log

Append exactly one entry to the end of `docs/task_log.md`. Append-only — earlier
entries are never rewritten or reordered.

```markdown
## YYYY-MM-DD — Short title

**Requested:** what the user asked for, in their framing.

**Changed:** what now exists or behaves differently.

**Key decisions:** choices made, alternatives rejected, and why.

**Affected files:**
- `path/to/file.php` — what changed in it

**Implementation details:** gotchas, version constraints, ordering
requirements, deliberate omissions, what remains open.
```

## Done means

- [ ] The four docs were read before work started
- [ ] Existing behaviour verified intact
- [ ] Matching rules and skill followed
- [ ] Affected docs updated in this same task
- [ ] One entry appended to `docs/task_log.md`
- [ ] Anything left undone stated plainly to the user
