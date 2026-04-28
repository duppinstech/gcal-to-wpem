=== Event Manager Gcal Importer ===
Contributors: oduppinsjr
Tags: events, calendar, ical, wp-event-manager, import
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import Google Calendar iCal feeds into Event Listings managed by WP Event Manager.

== Description ==

Event Manager Gcal Importer syncs events from a Google Calendar iCal URL into WP Event Manager listings.

Features:

* Manual sync and dry-run preview
* WP-Cron auto-sync (daily at chosen hour, or every N hours)
* Recurring event handling with optional approval queue
* Imported-series banner tools and basic import logging

== Installation ==

1. Install and activate **WP Event Manager**.
2. Upload this plugin folder to `/wp-content/plugins/`.
3. Activate **Event Manager Gcal Importer**.
4. Go to **Event Listings → Import** and configure your iCal URL.

== Frequently Asked Questions ==

= Does this plugin require WP Event Manager? =

Yes. The plugin only runs when WP Event Manager is active.

= Does WP-Cron run exactly on time? =

Not always. WP-Cron depends on site traffic. For precise timing, use a real server cron that calls `wp-cron.php`.

== Changelog ==

= 0.1.0 =
* Initial release.
