---
paths:
  - "gcal-to-wpem.php"
  - "uninstall.php"
  - "includes/**/*.php"
  - "tests/**/*.php"
---

# Code style

## PHP
- Declared floor is **PHP 7.4** (`composer.json`, plugin header). CI runs tests on PHP 8.2. `Fetcher::get_ical_data()` already returns `string|\WP_Error`.
- Indent `includes/` and the main file with 4 spaces. Those files use short array syntax and non-Yoda comparisons. `includes/class-gcal-wpem-github-updater.php` and `includes/class-gcal-to-wpem-plugin-info.php` use tabs. Don't reformat lines you didn't change.
- Prefix every global function, option, meta key, transient, hook, and admin-post action with `gcal_wpem`. Text domain `gcal-to-wpem` on every translatable string.
- Docblock on every public method and every hook the plugin fires.
- Namespace `GCAL_WPEM` for importer classes. There is no Composer autoload for plugin classes. `gcal-to-wpem.php` `require_once`s each file. `vendor/autoload.php` loads sabre/vobject and is required only after WP Event Manager is active.
- Class files are `includes/class-*.php`. Importer classes are `GCAL_WPEM\Plugin`, `Settings`, `Fetcher`, `Parser`, `Importer`, `RecurringQueue`, `PendingDigest`, and `Cron`. `Plugin::init()` registers admin-post actions and calls `Settings::init()`, `PendingDigest::init()`, and `Cron::init()`.
- `GCAL_WPEM_GitHub_Updater` and `GCAL_WPEM_Plugin_Info` are global classes, copied from the Duppins standards and booted from the main file.
- No field library. Settings use the WordPress Settings API in `GCAL_WPEM\Settings`.
- Global functions live in `gcal-to-wpem.php`: `gcal_wpem_wpem_required_notice()` and `gcal_wpem_plugin_action_links_requires_wpem()`.

## JavaScript
- Data from PHP arrives through `wp_localize_script` or `wp_add_inline_script`; never inline secrets.
- No standalone `.js` file and no build step. `Settings::enqueue_admin_assets()` enqueues `jquery` and `wp_enqueue_media()`, then adds an inline jQuery script with `wp_add_inline_script()`. The hook is `event_listing_page_gcal-wpem`.

## CSS
- This plugin ships no CSS file. On the Import screen it enqueues WP Event Manager's `assets/css/backend.min.css` when `EVENT_MANAGER_PLUGIN_URL` is defined.
- Don't edit vendored libraries (`*.min.*`, third-party bundles).

## Comments
- Explain *why*, not *what*.
