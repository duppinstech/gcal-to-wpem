# Contributing

Thanks for helping improve this plugin.

## Workflow

1. Open an issue first for larger changes (behavior, architecture) so we can align on direction.
2. Fork the repository and create a branch from `main` (or the default branch).
3. Make focused commits with clear messages.
4. Open a pull request describing **what** changed and **why**.

## Local setup

```bash
composer install
```

Develop inside a WordPress install with **WP Event Manager** enabled; place or symlink this repo under `wp-content/plugins/gcal-to-wpem/`.

## Standards

- Match existing PHP style (namespaces, early returns, WordPress APIs).
- Escape output and sanitize input; use nonces and capability checks for admin actions.
- Avoid unrelated refactors in the same PR as a bugfix or feature.

## Branch naming

Branch from `main` using `type/short-description`:

- `feat/` for a new capability
- `fix/` for a bug fix
- `docs/` for documentation
- `chore/` for maintenance that does not change plugin behavior

## Commit style

Use [Conventional Commits](https://www.conventionalcommits.org/): `type: summary`.

Types: `feat`, `fix`, `docs`, `test`, `ci`, `chore`. Keep each commit focused on one change.

## License

By contributing, you agree your contributions are licensed under the same terms as the project ([GPL-2.0-or-later](LICENSE)).
