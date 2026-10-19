#!/usr/bin/env bash
# PostToolUse (Edit|Write|MultiEdit): lint edited PHP and flag hardcoded secrets.
# Exit 2 feeds stderr back to the AI so it fixes the problem.
source "$(dirname "$0")/_lib.sh"
f="$(hook_file_path)"
{ [ -z "$f" ] || [ ! -f "$f" ]; } && exit 0

status=0
case "$f" in
  *.php)
    php_bin="$(command -v php 2>/dev/null)"
    [ -z "$php_bin" ] && [ -x /c/xampp/php/php.exe ] && php_bin=/c/xampp/php/php.exe
    if [ -n "$php_bin" ]; then
      out="$("$php_bin" -l "$f" 2>&1)" || { echo "PHP lint failed: $out" >&2; status=2; }
    fi
    ;;
esac

# Common key and token shapes must never be hardcoded.
case "$f" in
  *.php|*.js|*.json|*.html|*.yml|*.yaml)
    if grep -nE 'AIza[0-9A-Za-z_-]{35}|gh[pousr]_[0-9A-Za-z]{36}|github_pat_[0-9A-Za-z_]{40,}|sk-[A-Za-z0-9_-]{32,}|AKIA[0-9A-Z]{16}|xox[baprs]-[0-9A-Za-z-]{10,}' "$f" >/dev/null 2>&1; then
      echo "Possible hardcoded secret in $f. Move it to plugin settings or an environment variable." >&2
      status=2
    fi
    ;;
esac
exit $status
