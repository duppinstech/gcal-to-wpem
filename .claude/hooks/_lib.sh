#!/usr/bin/env bash
# Shared helpers for hooks. Reads hook JSON from stdin once.
HOOK_INPUT="$(cat)"

# Extract tool_input.file_path; PHP if available, else sed.
hook_file_path() {
  if command -v php >/dev/null 2>&1; then
    printf '%s' "$HOOK_INPUT" | php -r '$j = json_decode(stream_get_contents(STDIN), true); echo isset($j["tool_input"]["file_path"]) ? $j["tool_input"]["file_path"] : "";'
  else
    printf '%s' "$HOOK_INPUT" | sed -n 's/.*"file_path"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p'
  fi
}

# Normalize to forward slashes for matching.
hook_norm() { local p="$1"; printf '%s' "${p//\\//}"; }
