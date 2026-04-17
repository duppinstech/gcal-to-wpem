# WP Event Manager — Gcal Importer

Import events from **Google Calendar** (public iCal/ICS URL) into **[WP Event Manager](https://wordpress.org/plugins/wp-event-manager/)** listings. Includes recurring-series handling, optional approval workflow, WP-Cron scheduling, and logging.

**Requires:** WordPress **6.5+** (for `Requires Plugins` header) and **WP Event Manager** active.

## Features

- Fetch and parse iCal feeds (via [sabre/vobject](https://github.com/sabre-io/vobject))
- Map events to `event_listing` posts with configurable status and defaults (venue, organizer, location, etc.)
- Recurring modes: import all, one-offs only, or queue series for approval
- Automatic sync via **WP-Cron** (daily at a chosen hour, or every *N* hours)
- Optional daily email digest for pending approvals
- Admin UI under **Event Listings → Import**, plus a shortcut tab on **WP Event Manager → Settings**

## Installation

### From a ZIP (production)

1. Install and activate **WP Event Manager**.
2. Build dependencies on a machine with [Composer](https://getcomposer.org/):

   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. Zip the plugin folder **including** the `vendor/` directory (or deploy the whole folder to `wp-content/plugins/gcal-to-wpem/`).

4. In WordPress: **Plugins → Add New → Upload Plugin**, or copy the folder via SFTP/Git.

### From Git (development)

```bash
git clone https://github.com/oduppinsjr/gcal-to-wpem.git
cd gcal-to-wpem
composer install
```

If `vendor/` was ever committed before adding `.gitignore`, drop it from Git once (keep the folder locally):

```bash
git rm -r --cached vendor/
```

Then symlink or copy the folder into `wp-content/plugins/` and activate **WP Event Manager** first, then this plugin.

## Configuration

1. **Event Listings → Import** in the admin menu.
2. Paste your Google Calendar **secret iCal address** (Calendar settings → Integrate calendar).
3. Set import status, defaults, recurring behavior, and **Automatic sync (WP-Cron)** as needed.
4. Save, then use **Run Sync Now** or wait for the next scheduled run.

> **Note:** WP-Cron runs when your site gets visits. For reliable timing, use a system cron hitting `wp-cron.php` on a schedule.

## Requirements

| Requirement        | Notes                                      |
|--------------------|--------------------------------------------|
| PHP                | **7.4+** (match WP Event Manager / host)   |
| WordPress          | **6.5+**                                   |
| WP Event Manager   | Required; plugin will not load without it  |
| Composer (dev/build) | To install `vendor/` from `composer.json` |

## Development

```bash
composer install
```

Main entry: `gcal-to-wpem.php`. Core logic lives under `includes/`.

## Security

See [SECURITY.md](SECURITY.md) for how to report vulnerabilities responsibly.

## License

Copyright © 2026 Duppins Technology.

This project is licensed under the **GNU General Public License v2.0 or later** — see [LICENSE](LICENSE).

Dependencies (e.g. sabre/vobject) remain under their respective licenses.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).
