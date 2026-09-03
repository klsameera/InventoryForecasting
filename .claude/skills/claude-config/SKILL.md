---
name: claude-config
description: "Use when changing how Claude Code itself is configured in this repository — .claude/settings.json permissions (allow / ask / deny), the MCP servers in .mcp.json (laravel-boost, playwright), hooks, environment variables, or the skill registry under .claude/skills and .agents/skills. Trigger on 'allow X without asking', 'stop prompting for Y', 'require confirmation before Z', 'add an MCP server', 'add a hook', 'register a skill', or any edit to .claude/settings.json, .claude/settings.local.json, .mcp.json or boost.json. This skill owns the permission policy: auto-allow by default, explicit confirmation for destructive operations."
---

# Claude Code configuration

This skill owns every file that configures the agent harness for this repo:

| File | Owns | Committed |
| --- | --- | --- |
| `.claude/settings.json` | Permissions, MCP enablement, hooks | yes — team-wide |
| `.claude/settings.local.json` | Personal overrides | no — gitignore it |
| `.mcp.json` | MCP server definitions | yes |
| `boost.json` | Which Boost skills + guidelines are generated | yes |
| `.claude/skills/**` | Project skills for Claude Code | yes |
| `.agents/skills/**` | Mirror of the same skills for other agents | yes |

## The permission policy

The standing policy, set in `.claude/settings.json`:

> **Auto-allow everything by default. Require explicit confirmation for
> destructive and irreversible operations.**

It is expressed as three lists plus a default mode:

```jsonc
{
  "permissions": {
    "defaultMode": "acceptEdits",   // file edits apply without prompting
    "allow": ["Read", "Edit", "Write", "Glob", "Grep", "Bash", ...],
    "ask":   ["Bash(git push:*)", "Bash(rm -rf:*)", ...],
    "deny":  []
  }
}
```

**Precedence is `deny` > `ask` > `allow`.** This is what makes the policy work:
a broad `"Bash"` in `allow` auto-runs ordinary commands, while a narrower
`"Bash(git push:*)"` in `ask` still forces a prompt. Adding a blanket allow does
**not** weaken the ask list — but adding a *more specific* allow that overlaps an
ask entry is a mistake, because it is the ask list that must win.

### What must always require confirmation

Never move these out of `ask` without the user saying so explicitly in that task:

- `git push` in every form, force pushes included
- `rm -rf` / `rm -fr` / recursive `Remove-Item`
- History rewrites — `git reset --hard`, `git clean`, `git rebase`,
  `git filter-branch`, `git branch -D`, `git checkout --`
- Destructive artisan — `migrate:fresh`, `migrate:reset`, `migrate:rollback`,
  `db:wipe`
- Dependency removal — `composer remove`, `npm uninstall`

Rule of thumb: if it destroys work that is not recoverable from the working
tree, it belongs in `ask`.

Note this project is **not currently a git repository** (`git rev-parse` fails).
The git entries are deliberate: they arm the guard now so it is already in place
the day the repo is initialised. Do not prune them as dead rules.

### Rule syntax

- Tool-wide: `"Read"`, `"Bash"`
- Exact: `"Bash(npm run build)"`
- Prefix: `"Bash(git push:*)"` — the documented form
- Some versions match `"Bash(git push*)"` instead, so the settings file carries
  **both spellings** for each guarded command. Keep both when you add one; the
  cost is a duplicate line, the cost of missing one is an unprompted force push.

## Editing settings

1. **Read the file first.** Always. Merge into the existing arrays — never
   replace them. A settings file that fails to parse silently disables *every*
   setting in it, including the ask list.
2. Make the edit.
3. Validate before you finish:

```bash
node -e "const s=require('./.claude/settings.json'); console.log('ok', s.permissions.ask.length)"
```

4. New rules apply to *future* tool calls in the session; the harness only
   watches `.claude/` if a settings file was present at session start. If a new
   rule does not seem to take effect, the user needs to open `/hooks` once or
   restart — you cannot do that for them.

Scope decisions: team-wide and safety-relevant → `.claude/settings.json`.
Personal or machine-specific → `.claude/settings.local.json` (and add it to
`.gitignore`). Never put a machine-specific absolute path in the committed file.

## MCP servers

Two are configured in `.mcp.json` and enabled in `enabledMcpjsonServers`:

| Server | Command | Use |
| --- | --- | --- |
| `laravel-boost` | `php artisan boost:mcp` | `search-docs`, `database-query`, `database-schema`, `tinker`, `browser-logs`, `get-absolute-url`, `record-rule` |
| `playwright` | `npx -y @playwright/mcp@latest --browser chromium` | Driving a real browser — see the `browser-qa` skill |

`laravel-boost` runs through `php`, which must resolve to **8.3+**. The `php` on
PATH is 8.2, so a shell that has not prepended Laragon's 8.3 build will fail the
platform check. See CLAUDE.md for the export line.

Adding a server: add it to `.mcp.json`, add its name to `enabledMcpjsonServers`
in `.claude/settings.json` (otherwise the user is prompted to trust it), and add
`"mcp__<server-name>"` to the allow list. Verify it resolves before you claim it
works — e.g. `npx -y @playwright/mcp@latest --version`.

## Skills

Project skills live in `.claude/skills/{name}/SKILL.md` with YAML frontmatter:

```yaml
---
name: kebab-case-name          # must equal the directory name
description: "Use when …"      # third person, trigger-first, names the symptoms
---
```

The description is the only thing loaded until the skill fires, so it must
describe **when to activate**, not what the skill contains. Lead with concrete
triggers — the phrases a user would actually type.

`.agents/skills/` mirrors these for non-Claude agents. When you add or
materially change a project skill, mirror it so the two do not drift.

Boost-generated skills (`fortify-development`, `inertia-react-development`,
`infer-conventions`, `laravel-best-practices`, `pest-testing`,
`wayfinder-development`) are listed in `boost.json` and regenerated by
`php artisan boost:update`. **Do not hand-edit them** — the edit is lost on the
next update. Project-authored skills (`module-generation`, `premium-ui-design`,
`project-workflow`, `claude-config`, `browser-qa`) are not in `boost.json` and
are safe to edit.

## After changing config

Config changes are architecture changes. Update
[`/docs/app_architecture.md`](../../../docs/app_architecture.md) if the tooling
or conventions shifted, and append a `task_log.md` entry either way — per the
`project-workflow` skill.
