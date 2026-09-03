---
name: browser-qa
description: "Use when a change needs checking in a real browser rather than by unit test — end-to-end walkthroughs of a user flow (login, register, 2FA, passkeys, profile, security, a module's create/edit/delete path), visual and responsive checks, dark-mode verification, screenshots, or hunting a bug that only reproduces in the browser. Trigger on 'check it works in the browser', 'take a screenshot', 'does this look right', 'test the flow end to end', 'check dark mode', 'check mobile', or any report of a JavaScript/console error. Drives the Playwright MCP server against the local dev server."
---

# Browser QA — Playwright MCP

For anything that only shows up in a real browser: rendering, responsive
behaviour, dark mode, client-side JS, and multi-step flows across pages.

Unit and feature tests remain the primary safety net. Use this **in addition**,
not instead — a browser walkthrough proves the flow works today; a Pest test
stops it breaking tomorrow.

## Prerequisites — check before driving

The app must be served and built, or every page is a Vite manifest error.

```bash
export PATH="/c/laragon/bin/php/php-8.3.33-Win32-vs16-x64:$PATH"   # 8.3, not the 8.2 on PATH
composer dev      # serve + queue listener + vite, all at once
```

`composer dev` runs `php artisan serve`, `queue:listen` and `npm run dev`
concurrently. The app answers on **http://localhost:8000** (`APP_URL` in `.env`).
Start it in the background and confirm it is up before navigating — a connection
error mid-flow wastes a whole session.

Chromium is already installed for Playwright. If it ever goes missing:
`npx playwright install chromium`.

## The tools

The `playwright` MCP server (`.mcp.json`) exposes browser control as
`mcp__playwright__*` tools. It typically provides navigate, click, type,
fill_form, hover, press_key, select_option, wait_for, resize, snapshot,
take_screenshot, console_messages, network_requests and evaluate. Check the
tool list at runtime rather than assuming a name — the server updates
independently of this file.

Two habits that matter:

- **Prefer the accessibility snapshot over screenshots for driving.** The
  snapshot is structured text with stable element refs; it is what you act on.
  Screenshots are for *judging appearance*, and for showing the user.
- **Read console messages after every meaningful step.** A React error or failed
  request often leaves the page looking fine while the behaviour is broken.
  Boost's `browser-logs` tool covers the Laravel side of the same question.

## What to actually check

### A user flow

Walk it as a user would, not as a URL list. Register → verify email → log in →
land on the dashboard. Assert what the user sees at each step, and read the
console before moving on. The flows this app ships today are in
[`/docs/app_guide.md`](../../../docs/app_guide.md) — read it first so you test
the flow that exists rather than one you assumed.

Auth flows need real state: a registered user, and for the 2FA challenge a
confirmed TOTP secret. Do not fabricate a user in the browser when a factory
would do it precisely — set the state up first, then drive the UI.

### Appearance

- Both themes. Dark mode is `data-bs-theme` on `<html>`, resolved pre-paint in
  `app.blade.php`. Toggle it and re-screenshot — a token that only reads right
  in light mode is the most common visual bug here.
- Widths that matter: 375 (mobile), 768 (the `md` breakpoint where `DataTable`
  stacks into labelled rows), 1440 (desktop).
- Empty and loading states, not just the populated happy path.

### Regressions

When changing shared UI — a component in `resources/js/components/`, a layout,
or an SCSS partial — the blast radius is every page using it. Screenshot the
affected pages **before** the change, make the change, screenshot again, and
compare. Without the before-shot there is nothing to compare against, so take it
first.

## Reporting

Say what you drove, what you saw, and what you did not check. Attach or describe
the screenshots. A console error found and not fixed still gets reported — never
quietly drop a finding because it was outside the task.

## Pest browser tests — not yet available

Repeatable browser tests belong in the suite via `pestphp/pest-plugin-browser`.
It is **not installed**, and cannot be until PHP gains `ext-sockets`:

- `pest-plugin-browser` v5 needs PHP ^8.4; this project is on 8.3, so v4.3 is
  the usable line.
- Both v4.3 and v5 require `ext-sockets`, which is missing. The DLL ships with
  the Laragon build — it is commented out at line 960 of
  `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.ini` as `;extension=sockets`.

To enable it, uncomment that line, restart PHP, then:

```bash
composer require pestphp/pest-plugin-browser:^4.3 --dev
```

That `php.ini` is shared with the other projects on this machine, so the change
is the user's call — ask before editing it. Until then, Playwright MCP is the
browser-QA path, and browser coverage is interactive rather than committed to
CI. Update this section and
[`/docs/app_architecture.md`](../../../docs/app_architecture.md) if that changes.
