# Workflow: release

1. Everything for the release is merged to `main` and CI is green.
2. Run `/deploy <version>`. It runs the release-mode department review, bumps the version, tags, and lets the Release workflow build and publish the zip.
3. After the release: update staging, run the ops smoke test, then let live sites update (or update them by hand).
4. Record the release in `.claude/memory/progress.md` and close the PLAN.md items it shipped.
5. If a release goes bad: ship a patch release rather than deleting the tag, unless Odell decides otherwise.
