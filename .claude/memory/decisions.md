# Architectural decisions

Append-only log. Format: `## YYYY-MM-DD — Title`, then **Context** / **Decision** / **Consequences**. Owner waivers of High findings are recorded here too.

## 2026-04-16 — Do not commit vendor/

**Context:** sabre/vobject is required at runtime. The initial commit ignored `vendor/`.

**Decision:** Composer installs production dependencies on the machine that builds the zip. Git does not track `vendor/`.

**Consequences:** `bin/build-zip.sh` runs `composer install --no-dev` inside the build tree. A checkout without that step has no autoload.

## 2026-04-16 — Require WP Event Manager

**Context:** Listings are `event_listing` posts owned by WP Event Manager.

**Decision:** The plugin header declares `Requires Plugins: wp-event-manager`. `gcal-to-wpem.php` loads the importer only when `WP_Event_Manager` exists. Otherwise it shows an admin notice.

**Consequences:** The plugin does not replace WP Event Manager and does not import when that plugin is inactive. `INTENT.md` (2026-10-17) records the same boundary.

## 2026-10-17 — Settings stay in WordPress

**Context:** `PLAN.md` lists reading secrets from `.env` files as not planned.

**Decision:** The iCal URL and import defaults live in the `gcal_wpem_settings` option.

**Consequences:** No `.env` loader. Production config is the Import screen.

## 2026-10-18 — Updates come from GitHub Releases

**Context:** Sites need a zip that includes `vendor/`, and Dashboard → Updates should see new tags.

**Decision:** `GCAL_WPEM_GitHub_Updater` checks `duppinstech/gcal-to-wpem` releases. `release.yml` builds `gcal-to-wpem.zip` on `v*` tags. Plugin info for the Plugins screen is `GCAL_WPEM_Plugin_Info`.

**Consequences:** A `v*` tag is a production update. `GCAL_WPEM_DISABLE_GITHUB_UPDATER` turns the updater off.

## 2026-10-18 — Uninstall deletes only the updater cache

**Context:** Import settings and logs are site data.

**Decision:** `uninstall.php` deletes site option `gcal_wpem_github_release` and leaves settings, logs, and imported listings in place.

**Consequences:** Deleting the plugin does not remove `event_listing` posts or `gcal_wpem_settings`.
