# Agent notes

WordPress plugin (`duppins/gcal-to-wpem`). PHP 7.4+ and Composer. Production dependencies are declared in `composer.json`.

## Plugin facts

- **Product:** WP Event Manager Gcal Importer
- **Main file:** `gcal-to-wpem.php`
- **Prefix:** `gcal_wpem` for functions, options, nonces, cron hooks, and admin-post actions. Constants and the two global classes use `GCAL_WPEM`. Importer classes use namespace `GCAL_WPEM`.
- **Text domain:** `gcal-to-wpem`
- **Floor:** WordPress 6.5, PHP 7.4 (`Requires at least`, `Requires PHP`, `composer.json`)
- **Repo:** https://github.com/duppinstech/gcal-to-wpem (public). Git `origin` is `https://github.com/duppinstech/gcal-to-wpem.git`.
- **Updater:** `GCAL_WPEM_GitHub_Updater` in `includes/class-gcal-wpem-github-updater.php`. Booted with owner `duppinstech`, repo `gcal-to-wpem`, prefix `gcal_wpem`. Disable with `GCAL_WPEM_DISABLE_GITHUB_UPDATER`.
- **Distribution:** GitHub Releases. `.github/workflows/release.yml` builds `gcal-to-wpem.zip` on `v*` tags.
- **Requires:** WP Event Manager. The importer does not load when `WP_Event_Manager` is missing.
- **Integrations:** HTTP GET of the iCal URL stored in settings. GitHub latest-release API for updates. No REST routes and no admin-ajax actions.
- **Security-sensitive files:** `includes/class-plugin.php`, `includes/class-settings.php`, `includes/class-fetcher.php`, `includes/class-importer.php`, `includes/class-gcal-wpem-github-updater.php`
- **Departments skipped:** none

## What this is

`gcal-to-wpem` is a WordPress plugin that imports a Google Calendar iCal feed into WP Event Manager listings.

It exists so a site can turn a calendar feed into `event_listing` posts, including recurring events, an optional approval step, and a scheduled sync. The plugin does not replace WP Event Manager. It requires that plugin and WordPress 6.5 or newer.

Out of scope: editing events inside Google Calendar, and any sync that is not driven by the configured iCal URL.

## Architecture

- `gcal-to-wpem.php` — Boots the GitHub updater and Plugins-screen info, then loads the importer on `plugins_loaded` when WP Event Manager is active.
- `includes/class-plugin.php` — Admin-post handlers for sync, dry run, backfill, and recurring-series actions.
- `includes/class-settings.php` — Event Listings → Import (`page=gcal-wpem`) with Import, Advanced, Recurring events, and Logging tabs, plus a Google Cal shortcut on WP Event Manager → Settings.
- `includes/class-fetcher.php` — Downloads the configured iCal URL.
- `includes/class-parser.php` — Parses ICS into occurrences with sabre/vobject.
- `includes/class-importer.php` — Creates and updates `event_listing` posts from those occurrences.
- `includes/class-recurring-queue.php` — Stores pending, approved, and blocked recurring series, and series banners.
- `includes/class-pending-digest.php` — Sends the daily 4:00 p.m. site-time approval email.
- `includes/class-cron.php` — Schedules WP-Cron sync daily at a chosen hour or every N hours.
- `includes/class-gcal-wpem-github-updater.php` — GitHub Releases updater, standard v1.1.0.
- `includes/class-gcal-to-wpem-plugin-info.php` — Plugins-screen details from `readme.txt` and `assets/wporg/`, standard v1.0.0.
- `uninstall.php` — Deletes site option `gcal_wpem_github_release` only. Settings and logs stay.
- `assets/wporg/` — Banner, icon, and screenshot images for the Plugins-screen modal.
- `tests/SmokeTest.php` — PHPUnit check that `composer.json` names `duppins/gcal-to-wpem`.
- `bin/` — `build-zip.sh` and `release-notes.sh` for the release workflow.

## Commands

Install:

```bash
composer install
```

Lint: no lint or format command is configured. Do not invent one.

Test:

```bash
composer test
```

Build the production tree (zip this folder, including `vendor/`):

```bash
composer install --no-dev --optimize-autoloader
```

Release packaging is `bash bin/build-zip.sh` (also used by `release.yml`).

## Conventions

- Indent PHP with 4 spaces. Match the style already in the plugin.
- Branch from `main` as `type/short-description` (`feat/`, `fix/`, `docs/`, `chore/`).
- Commit as `type: summary` (Conventional Commits).
- Admin-facing changes need the same escaping, sanitizing, nonces, and capability checks the plugin already uses.

## Hard rules

- Plugin behavior in `gcal-to-wpem.php` and `includes/` unless the task is a bug fix or feature.
- `LICENSE` and `SECURITY.md`.
- `.env`, `.env.*`, `auth.json`, and anything under `secrets/` or `certs/`.
- Do not commit `vendor/`. Install it with Composer.

## Agents

| File | Role |
|---|---|
| `.claude/agents/security-reviewer.md` | Admin-post, updater, and request-data changes |
| `.claude/agents/legal-reviewer.md` | License, copyright, bundled assets, privacy |
| `.claude/agents/finance-reviewer.md` | Cost, packaging, anything a customer might pay for |
| `.claude/agents/product-reviewer.md` | Scope against `INTENT.md` and `PLAN.md` |
| `.claude/agents/marketing-reviewer.md` | Release copy, naming, screenshots, claims |
| `.claude/agents/support-docs-reviewer.md` | Changelog, readme, admin messages, i18n |
| `.claude/agents/release-ops-reviewer.md` | Compatibility, zip contents, uninstall, rollback |
| `.claude/agents/ux-reviewer.md` | Admin screens and front-end output |
| `.claude/agents/test-writer.md` | PHPUnit and regression tests |
| `.claude/agents/research.md` | WordPress, library, and third-party API questions. Does not edit code |

Commands: `.claude/commands/review.md`, `department-review.md`, `deploy.md`, `scaffold.md`. Workflows: `.claude/workflows/`.
