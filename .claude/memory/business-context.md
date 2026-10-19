# Business context

_Committed. If the repo is public, keep only public-safe facts here: published tiers and list prices, positioning, cost drivers. Private numbers go in the "Business (private)" section of the gitignored `CLAUDE.local.md`._

Read by `finance-reviewer`, `marketing-reviewer`, `legal-reviewer` and `product-reviewer`. Items marked _TBD_ are open questions for Odell. Agents must not fill them in by guessing.

## Product and positioning
- **Product:** WP Event Manager Gcal Importer, by Duppins Technology (https://duppinstech.com).
- **Audience:** A site that already keeps its schedule in Google Calendar and publishes those events with WP Event Manager.
- **Positioning:** Imports a Google Calendar iCal feed into `event_listing` posts, including recurring events, an optional approval step, and a scheduled sync. It does not replace WP Event Manager and it does not edit Google Calendar.
- **Built for (client):** Product work. Not work-for-hire for a single client (`OWNERSHIP.md`).

## Tiers
| Tier | What's included | Price | Distribution |
|---|---|---|---|
| Core | iCal import, recurring-series handling, optional approval, WP-Cron sync, import log, daily approval email | _TBD (Odell)_ | GitHub Releases zip (`gcal-to-wpem.zip`), shown under Dashboard → Updates |

## Cost drivers
- The feed request is an HTTP GET of the iCal URL saved in WordPress. The plugin does not call a metered Google API and does not store an API key.
- The updater calls the GitHub latest-release API. A token is optional through `gcal_wpem_github_updater_config`. Who pays for GitHub, hosting, and support time is _TBD (Odell)_.
- Published support address (`readme.txt`): support@duppinstech.com.
