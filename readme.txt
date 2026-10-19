=== WP Event Manager Gcal Importer ===
Contributors: oduppinsjr
Tags: events, calendar, ical, wp-event-manager, import
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import a Google Calendar iCal feed into WP Event Manager event listings.

== Description ==

WP Event Manager Gcal Importer brings events from a Google Calendar iCal feed into WP Event Manager listings. Use it when the calendar already holds the schedule and the website should show those events.

Paste the calendar address, choose whether new listings are published, pending review, or draft, and sync now or on a schedule. Repeating events can import with everything else, be skipped, or wait for approval. When the feed leaves out a place or an organizer, saved defaults can fill them in.

= Features =

* Manual sync and a dry-run preview that does not save listings
* Automatic sync once a day at a chosen hour, or every few hours
* Repeating events imported, skipped, or held for approval
* Default location, venue, and organizer when the feed omits them
* Optional year prefix on one-time event titles
* An import log
* A daily email when a series or a listing is waiting for approval

= Support =

Support: [support@duppinstech.com](mailto:support@duppinstech.com). Made by [Duppins Technology](https://duppinstech.com).

== Installation ==

1. Install and activate **WP Event Manager**.
2. Download `gcal-to-wpem.zip` from the latest release.
3. In wp-admin, go to Plugins → Add New → Upload Plugin, choose the zip, and click Install Now.
4. Activate **WP Event Manager Gcal Importer**.
5. Go to **Event Listings → Import**, paste the Google Calendar iCal address, and save.

= Updates =

New releases appear under Dashboard → Updates.

== Frequently Asked Questions ==

= Does this plugin require WP Event Manager? =

Yes. Events import only when WP Event Manager is active.

= Where do I find the Google Calendar address? =

In Google Calendar, open that calendar's settings and copy the secret address in iCal format. Paste it on **Event Listings → Import**.

= Does automatic sync run at the exact minute I choose? =

Not always. WordPress runs scheduled tasks when someone visits the site. For a fixed clock time, ask your host to call `wp-cron.php` on a schedule.

= Can this plugin change events in Google Calendar? =

No. It reads the iCal feed and creates or updates event listings on the site.

= What happens to repeating events? =

You can import every event, skip repeating events, or hold each repeating series until you approve it. One-time events can still import immediately when approval is required for series.

= Who receives the approval email? =

The site administrator email address. The message is sent once a day at 4:00 p.m. site time, and only when a repeating series or an event listing is waiting for approval.

== Screenshots ==

1. Import settings: the iCal address, listing status, title option, and automatic sync schedule.
2. Advanced defaults used when the feed leaves out a location, postal code, country, venue, or organizer.
3. Repeating events can be imported, skipped, or held for approval, with a default event image.
4. The import log before any sync has run.

== Changelog ==

= 0.1.1 =
* No change to how events are imported. This release records the project scaffold in the repository.

= 0.1.0 =
* Initial release: import Google Calendar iCal feeds into WP Event Manager listings.

== Upgrade Notice ==

= 0.1.1 =
No change to how events are imported.

= 0.1.0 =
Initial release.
