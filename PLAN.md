# Plan

Current version: **0.1.1** (see `composer.json`).

## Now

- Keep the importer installable from Git for development and from a zip that includes `vendor/` for production.
- Run `composer test` before merging pull requests.
- Cut a release by tagging `v*` so the release workflow builds `gcal-to-wpem.zip`.

## Next

- Extend the PHPUnit suite beyond the manifest smoke test when behavior changes.
- Leave lint unconfigured until a formatter or linter is chosen. Do not add one only to satisfy the workflow.

## Not planned

- Publishing `vendor/` in Git.
- Reading secrets from `.env` files. Settings stay in WordPress.
