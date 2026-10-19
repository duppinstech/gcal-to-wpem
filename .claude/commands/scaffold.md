---
description: Scaffold new code for this plugin
argument-hint: "module <dir>/<Class_Name> | settings-tab <slug>"
---

Request: $ARGUMENTS

## `module <dir>/<Class_Name>`
- Create the class in `includes/class-<slug>.php` under namespace `GCAL_WPEM`, following `.claude/rules/code-style.md`. `init()` only registers hooks.
- `require_once` it from `gcal-to-wpem.php` next to the other includes, and call `init()` from `GCAL_WPEM\Plugin::init()` unless it must run when WP Event Manager is inactive.
- This plugin does not Composer-autoload its own classes. Do not add a new autoload entry for one class.

## `settings-tab <slug>`
- Edit `includes/class-settings.php` only. Tabs are not separate files.
- In `register_settings()`, add `add_settings_section('gcal_wpem_tab_<slug>', ...)` on page `gcal-wpem`, then `add_settings_field()` rows in that section. Sanitize new keys in `sanitize_settings()`.
- In `render_page()`, add a `nav-tab` with `data-tab="<slug>"`, a panel that calls `do_settings_fields('gcal-wpem', 'gcal_wpem_tab_<slug>')`, and add `<slug>` to the allowed `gcal_tab` list.
- Logging is markup in `render_page()`, not a Settings API section. Copy that only for a tab that does not save `gcal_wpem_settings`.
- Prefix ids, option keys, and nonces with `gcal_wpem`. Text domain `gcal-to-wpem`.

After scaffolding, run `php -l` on the new files and list what's left for a human to fill in.
