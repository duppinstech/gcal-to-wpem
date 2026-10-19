---
name: wp-code-review
description: Checklist for reviewing WordPress plugin PHP/JS changes: security (nonces, caps, sanitize/escape), compatibility floor, hook and option prefixes, Pro boundary. Use when reviewing a diff, PR or file in this repo.
---

# WordPress plugin code review

Read the **Plugin facts** in `AGENTS.md` (floor, prefix, text domain, sensitive files) and the rules in `.claude/rules/` first. Work through the diff hunk by hunk. Report only real problems, with `file:line`.

## Security (High)
- [ ] State-changing handlers check a nonce (`check_ajax_referer`, `check_admin_referer`, REST `wp_rest`) **and** `current_user_can()`.
- [ ] Every `$_GET/$_POST/$_REQUEST/$_SERVER` read is `wp_unslash`ed, then sanitized with the right function (`sanitize_text_field`, `absint`, `sanitize_key`, `esc_url_raw`).
- [ ] Every output is escaped for its context (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`, `wp_json_encode`).
- [ ] SQL with variables goes through `$wpdb->prepare()`.
- [ ] File operations: no user-controlled paths, no extraction into web-reachable dirs, cleanup on every exit path.
- [ ] Outbound requests use `wp_remote_*` with a timeout. A user-supplied URL is an SSRF risk.
- [ ] No hardcoded keys or tokens.

## Correctness (Medium)
- [ ] Within the compatibility floor (see `.claude/rules/code-style.md`).
- [ ] Hooks registered at the right time and priority; nothing heavy runs on every request.
- [ ] Rewrite rules changed means a flush on activation or upgrade, never on every request.
- [ ] Options and meta read with sensible defaults; upgrade path for existing sites.
- [ ] Project-specific checks listed in `.claude/rules/code-style.md` and `api-conventions.md`.

## Conventions (Low)
- [ ] The plugin prefix on functions, options, hooks; the plugin text domain on strings.
- [ ] No Pro logic in core (if there is a Pro add-on).
- [ ] Docblocks on public methods. No reformatting of untouched lines.
- [ ] CHANGELOG [Unreleased] is updated.
