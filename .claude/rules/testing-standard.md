---
paths:
  - "tests/**"
  - "includes/**/*.php"
  - "gcal-to-wpem.php"
---

# Testing standard

## Minimum (every change)
1. `php -l` passes on every changed PHP file (the PostToolUse hook does this).
2. Manual smoke test on a WordPress site with `WP_DEBUG` on:
   - The plugin activates without notices or errors.
   - With WP Event Manager inactive, an admin sees the requirement notice and the importer does not load.
   - **Event Listings → Import** (`edit.php?post_type=event_listing&page=gcal-wpem`): Import, Advanced, Recurring events, and Logging.
   - **Test Import (Dry Run)** does not save listings. **Run Sync Now** creates or updates `event_listing` posts.
   - **Plugins** screen: View details shows the `readme.txt` tabs, and Settings opens the Import screen.
   - This plugin has no front-end template of its own. Listings render through WP Event Manager.
3. If you touched the updater, test the Check Again flow against a draft release.

## Automated tests
- `composer test` runs PHPUnit 9 (`phpunit.xml`, bootstrap `vendor/autoload.php`) on the `tests/` directory.
- The suite is `tests/SmokeTest.php`: `composer.json` exists and its name is `duppins/gcal-to-wpem`. There is no `tests/unit/`, `tests/integration/`, Brain Monkey, or wp-phpunit install.
- `PLAN.md` says to extend this suite beyond the manifest smoke test when behavior changes, and to leave lint unconfigured until a linter is chosen.
- Every bug fix ships with a regression test that fails before the fix. Unit tests never hit the network.
- CI (`.github/workflows/ci.yml`) runs `composer validate` and `composer test` on PHP 8.2 for pull requests. It does not run PHP 7.4 and it does not lint. `release.yml` runs the same test command on PHP 8.2 before packaging.

## Priority test targets
- `GCAL_WPEM\Importer` — creates and updates listings, including banner meta.
- `GCAL_WPEM\Fetcher` — downloads the iCal URL from settings.
- `GCAL_WPEM\Parser` — expands ICS, including recurring occurrences.
- `GCAL_WPEM\RecurringQueue` — pending, approved, and blocked series.
- `GCAL_WPEM_GitHub_Updater` — release checks and the package URL.
- `GCAL_WPEM\Plugin` admin-post handlers — `manage_options` and nonce before any write.
