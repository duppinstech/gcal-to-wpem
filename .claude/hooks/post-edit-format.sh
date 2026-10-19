#!/usr/bin/env bash
# PostToolUse (Edit|Write|MultiEdit): format PHP with phpcbf (WordPress standard) when installed.
# No-op otherwise. Never blocks.
source "$(dirname "$0")/_lib.sh"
f="$(hook_file_path)"
case "$f" in *.php) ;; *) exit 0 ;; esac
case "$(hook_norm "$f")" in */vendor/*) exit 0 ;; esac

root="${CLAUDE_PROJECT_DIR:-.}"
standard="WordPress"
for cfg in "$root/phpcs.xml" "$root/phpcs.xml.dist" "$root/.phpcs.xml.dist"; do
  [ -f "$cfg" ] && standard="$cfg" && break
done
for bin in "$root/vendor/bin/phpcbf" "$(command -v phpcbf 2>/dev/null)"; do
  if [ -n "$bin" ] && [ -x "$bin" ]; then
    "$bin" --standard="$standard" -q "$f" >/dev/null 2>&1
    break
  fi
done
exit 0
