# Project context

_Shared, committed memory. Keep it factual and current. If the repo is public, so is this file._

- **Product:** WP Event Manager Gcal Importer, a WordPress plugin. Owner: Odell Duppins Jr / Duppins Technology.
- **Repo:** https://github.com/duppinstech/gcal-to-wpem (public). Git `origin` is `https://github.com/duppinstech/gcal-to-wpem.git`.
- **Current release:** `v0.1.1`, tagged 2026-10-18. `v0.1.0` was tagged the same day; `CHANGELOG.md` dates 0.1.0 as 2026-04-28.
- **Distribution:** GitHub Releases. Sites update through `GCAL_WPEM_GitHub_Updater`. `release.yml` publishes `gcal-to-wpem.zip` on `v*` tags.
- **Key dependencies:** PHP >=7.4, WordPress 6.5, WP Event Manager, `sabre/vobject` ^4.5. Dev: `phpunit/phpunit` ^9.6.
- **Integrations:** HTTP GET of the Google Calendar iCal URL stored in settings. GitHub latest-release API for updates.
- **CI:** Pull requests run `composer test` on PHP 8.2 (`.github/workflows/ci.yml`). No lint job. `release.yml` runs the same tests on PHP 8.2, then builds the zip on `v*` tags.

## Review watchlist
Known items each department re-checks on every review until fixed. Remove lines when they're resolved.
- **Security:** none yet
- **Legal:** none yet
- **Finance:** none yet
- **Product:** none yet
- **Marketing:** none yet
- **Support:** none yet
- **Ops:** none yet
- **UX:** none yet
