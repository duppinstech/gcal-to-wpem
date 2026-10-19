#!/usr/bin/env bash
# PreToolUse (Edit|Write|MultiEdit): block writes to files the AI must never touch.
# Exit 0 = allow, exit 2 = block (stderr is shown to the AI).
source "$(dirname "$0")/_lib.sh"
f="$(hook_norm "$(hook_file_path)")"
[ -z "$f" ] && exit 0
root="$(hook_norm "${CLAUDE_PROJECT_DIR:-$PWD}")"

case "$f" in */.env.example) exit 0 ;; esac
case "$f" in
  */vendor/*)              msg="vendor/ is managed by Composer. Edit composer.json instead." ;;
  */node_modules/*)        msg="node_modules/ is managed by npm." ;;
  */.git/*)                msg="Never write inside .git/." ;;
  */.env|*/.env.*|*.pem|*.key|*.pfx) msg="Secrets files are off-limits." ;;
  "$root"/*.zip)           msg="Root-level zips are release artifacts. Rebuild with bin/build-zip.sh." ;;
  *) exit 0 ;;
esac

echo "Blocked by block-sensitive-writes.sh: $f ($msg)" >&2
exit 2
