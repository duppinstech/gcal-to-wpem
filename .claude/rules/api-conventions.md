---
paths:
  - "gcal-to-wpem.php"
  - "includes/**/*.php"
---

# API conventions (REST, AJAX, admin-post, hooks)

## REST
- This plugin registers no REST routes.
- If a route is added, register it on `rest_api_init` under a `gcal-to-wpem/v1` namespace. Every route has a real `permission_callback`; never `__return_true` on routes that write.
- Validate and sanitize every argument with `args` → `sanitize_callback` / `validate_callback`. Return `WP_REST_Response` or `WP_Error`; never `echo`.

## admin-ajax
- This plugin registers no `wp_ajax_` actions.
- If one is added, prefix it `wp_ajax_gcal_wpem_`. First lines of the handler: `check_ajax_referer()`, then the capability check. Respond with `wp_send_json_success()` / `wp_send_json_error()`.

## admin-post
- Actions are `admin_post_gcal_wpem_*`, registered in `GCAL_WPEM\Plugin::init()`: `gcal_wpem_run_sync`, `gcal_wpem_test_sync`, `gcal_wpem_backfill`, `gcal_wpem_backfill_manual_listings`, `gcal_wpem_series_action`, `gcal_wpem_save_series_media`, `gcal_wpem_imported_series_banner`.
- Each handler checks `current_user_can('manage_options')` and `check_admin_referer()` (manual-listing backfill uses `wp_verify_nonce()` on `$_REQUEST`) before using `$_POST` or `$_REQUEST`, then finishes with `wp_safe_redirect()`.
- Clearing the log is `admin_init` in `Settings::maybe_clear_logs()`, nonce `gcal_wpem_clear_logs`, not an admin-post action.

## Outbound HTTP
- `Fetcher::get_ical_data()` calls `wp_remote_get()` on the iCal URL from settings, timeout 20 seconds, user-agent `GCAL-WPEM/` plus the plugin version. It does not cache the feed.
- The updater calls the GitHub latest-release API with `wp_remote_get()`, timeout 10 seconds. Filter `gcal_wpem_github_release_http_args` to change those args.
- Do not log the iCal URL or an updater token.

## Hooks we expose
- New hooks are prefixed `gcal_wpem`, documented with a docblock, and listed here. Renaming or removing a public hook is a breaking change; deprecate first.
- `gcal_wpem_event_banner_meta_key` — meta key used for the event banner URL. Default `_event_banner`.
- `gcal_wpem_should_fill_event_banner_meta` — return true to fill banner meta the importer would otherwise keep. Default false. Passed the existing meta value.
- `gcal_wpem_is_placeholder_event_banner_url` — return true to treat a banner URL as a placeholder. Default false. Passed the URL.
- `gcal_wpem_pending_digest_recipients` — recipient list for the approval digest. Default is the site admin email.
- `gcal_wpem_github_updater_config` — updater configuration (owner, repo, token, TTLs, icons).
- `gcal_wpem_github_release_http_args` — `wp_remote_get()` args for the latest-release request. Also passed the URL and the updater config.
- The plugin does not call `do_action()` on its own hook names.
