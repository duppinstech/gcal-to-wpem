#!/usr/bin/env bash
# Duppins Technology GitHub updater standard: writes the GitHub release body for a version.
# Usage: bash bin/release-notes.sh <version> <main-plugin-file> [output-file]
#
# The body starts with the "## Requirements" block the updater reads (R6), then the version's
# changelog section (R7): CHANGELOG.md "## [x.y.z]" if that file exists, otherwise readme.txt
# "= x.y.z =" under "== Changelog ==". Fails if a requirement is missing or not a version number,
# or if the section is missing, empty, or marked Unreleased.
set -euo pipefail

version="${1:?version required}"
main_file="${2:?main plugin file required}"
out="${3:-release-notes.md}"

cd "$(dirname "$0")/.."

requires=$(sed -n 's/^ \* Requires at least:[[:space:]]*//p' "$main_file" | tr -d '\r')
requires_php=$(sed -n 's/^ \* Requires PHP:[[:space:]]*//p' "$main_file" | tr -d '\r')
tested=$(sed -n 's/^Tested up to:[[:space:]]*//p' readme.txt | tr -d '\r')

if [ -z "$requires" ] || [ -z "$requires_php" ] || [ -z "$tested" ]; then
    echo "::error::Missing requirement: 'Requires at least' and 'Requires PHP' in $main_file, 'Tested up to' in readme.txt (got '$requires' / '$requires_php' / '$tested')." >&2
    exit 1
fi
# The updater only reads plain version numbers; anything else would let a changelog line win instead.
for value in "$requires" "$requires_php" "$tested"; do
    if ! printf '%s' "$value" | grep -Eq '^[0-9]+(\.[0-9]+){0,2}$'; then
        echo "::error::Requirement '$value' is not a plain version number (for example 6.2 or 7.4)." >&2
        exit 1
    fi
done

if [ -f CHANGELOG.md ]; then
    source_name="CHANGELOG.md"
    heading=$(tr -d '\r' < CHANGELOG.md | awk -v v="$version" 'index($0, "## [" v "]") == 1 { print; exit }')
    notes=$(tr -d '\r' < CHANGELOG.md | awk -v v="$version" '
        index($0, "## [" v "]") == 1 { found = 1; next }
        found && /^## \[/ { exit }
        found { print }')
else
    source_name="readme.txt"
    # Only headings under "== Changelog ==" count (not "== Upgrade Notice ==" and the like).
    # Accepts "= x.y.z =" and "= x.y.z (anything) =" so an Unreleased marker is reported as such.
    heading=$(tr -d '\r' < readme.txt | awk -v v="$version" '
        /^== / { in_log = ( $0 == "== Changelog ==" ); next }
        in_log && ( $0 == "= " v " =" || ( index($0, "= " v " (") == 1 && / =$/ ) ) { print; exit }')
    notes=$(tr -d '\r' < readme.txt | awk -v v="$version" '
        /^== / { if ( found ) exit; in_log = ( $0 == "== Changelog ==" ); next }
        in_log && !found && ( $0 == "= " v " =" || ( index($0, "= " v " (") == 1 && / =$/ ) ) { found = 1; next }
        found && /^= .* =$/ { exit }
        found { print }')
fi

if [ -z "$heading" ]; then
    echo "::error::No changelog section for $version in $source_name." >&2
    exit 1
fi
if printf '%s' "$heading" | grep -qi 'unreleased'; then
    echo "::error::The $version changelog section in $source_name is still marked Unreleased; finalize it before tagging." >&2
    exit 1
fi
if [ -z "$(printf '%s' "$notes" | tr -d '[:space:]')" ]; then
    echo "::error::The $version changelog section in $source_name is empty." >&2
    exit 1
fi

{
    printf '## Requirements\n\n- Requires at least: %s\n- Requires PHP: %s\n- Tested up to: %s\n\n' "$requires" "$requires_php" "$tested"
    printf '## Changes in %s\n' "$version"
    printf '%s\n' "$notes"
} > "$out"

# Show the result. In GitHub Actions, stop workflow-command parsing so changelog text is printed as plain text.
if [ -n "${GITHUB_ACTIONS:-}" ]; then
    token="release-notes-$(date +%s)-$$-${RANDOM}"
    echo "::stop-commands::${token}"
    cat "$out"
    echo "::${token}::"
else
    cat "$out"
fi
