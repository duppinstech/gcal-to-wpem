---
description: Cut a plugin release (version bump, changelog, tag, GitHub Release, zip)
argument-hint: "<version e.g. 1.2.0>"
---

Release version: $ARGUMENTS

Read the **Plugin facts** in `AGENTS.md` first: main file, slug, and how releases reach sites. If sites self-update from GitHub Releases, this ships to live sites. **Confirm with Odell before every step that pushes or tags.**

1. **Preflight**
   - `git status` shows a clean tree on `main`, up to date with `origin/main`. Stop if not.
   - No open P0 items in PLAN.md, or Odell explicitly waives them.
   - Run `/department-review v$ARGUMENTS` on everything since the last tag. Any `block` stops the release. Use the marketing release-note draft and the ops smoke test below.
   - Lint every plugin PHP file outside `vendor/`.
2. **Bump**
   - Set ` * Version: $ARGUMENTS` in the main file, and any version constant it defines. Update `Stable tag:` in `readme.txt` if present.
   - In `CHANGELOG.md`, move [Unreleased] to `## [$ARGUMENTS] - <today>` and update compare links.
   - Commit: `Release v$ARGUMENTS`.
3. **Dry-run the zip** (optional): `bin/build-zip.sh`, then check every path in `unzip -Z1 <slug>.zip` starts with `<slug>/`. Don't commit the zip.
4. **Publish** (ask first)
   - `git push origin main`
   - `git tag v$ARGUMENTS && git push origin v$ARGUMENTS`
   - The `Release` workflow lints, checks the tag matches `Version:`, builds the zip and creates the release with the zip and changelog notes. Watch it with `gh run watch`.
   - Don't run `gh release create` by hand; a release without its asset makes updaters fall back to the raw zipball.
5. **Verify**
   - `gh release view v$ARGUMENTS` shows the asset.
   - On a staging site, **Dashboard → Updates → Check Again** offers $ARGUMENTS and installs into the `<slug>/` folder. Run the ops smoke test.
6. Update `.claude/memory/progress.md` and PLAN.md.
