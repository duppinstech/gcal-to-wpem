# Agent notes

WordPress plugin (`duppins/gcal-to-wpem`). PHP 7.4+ and Composer. Production dependencies are declared in `composer.json`.

## Commands

Install:

```bash
composer install
```

Lint: no lint or format command is configured. Do not invent one.

Test:

```bash
composer test
```

Build the production tree (zip this folder, including `vendor/`):

```bash
composer install --no-dev --optimize-autoloader
```

## Conventions

- Indent PHP with 4 spaces. Match the style already in the plugin.
- Branch from `main` as `type/short-description` (`feat/`, `fix/`, `docs/`, `chore/`).
- Commit as `type: summary` (Conventional Commits).
- Admin-facing changes need the same escaping, sanitizing, nonces, and capability checks the plugin already uses.

## Do not touch

- Plugin behavior in `gcal-to-wpem.php` and `includes/` unless the task is a bug fix or feature.
- `LICENSE` and `SECURITY.md`.
- `.env`, `.env.*`, `auth.json`, and anything under `secrets/` or `certs/`.
- Do not commit `vendor/`. Install it with Composer.
