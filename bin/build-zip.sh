#!/usr/bin/env bash
# Build gcal-to-wpem.zip from tracked files only, excluding development files.
# Used by .github/workflows/release.yml; run locally with `bash bin/build-zip.sh` to inspect the package.
#
# sabre/vobject is a runtime dependency and vendor/ is gitignored, so this script
# installs production Composer packages into the build tree and then drops
# composer.json and composer.lock from the zip.
set -euo pipefail

cd "$(dirname "$0")/.."
rm -rf build gcal-to-wpem.zip
mkdir -p build/gcal-to-wpem

# Tracked files only (never local settings, vendor/, or untracked scratch files), minus dev-only paths.
git ls-files -z \
  | grep -zvE '^(\.github/|\.claude/|tests/|bin/|docs/|\.gitignore$|\.gitattributes$|AGENTS\.md$|CLAUDE\.md$|OWNERSHIP\.md$|CONTRIBUTING\.md$|intent\.md$|plan\.md$|composer\.(json|lock)$|phpcs\.xml\.dist$|phpunit\.xml\.dist$)' \
  | xargs -0 -I{} cp --parents {} build/gcal-to-wpem/

cp composer.json composer.lock build/gcal-to-wpem/
composer install --no-dev --optimize-autoloader --no-interaction --working-dir=build/gcal-to-wpem
rm -f build/gcal-to-wpem/composer.json build/gcal-to-wpem/composer.lock
test -f build/gcal-to-wpem/vendor/autoload.php

( cd build && zip -qr9 ../gcal-to-wpem.zip gcal-to-wpem )
unzip -l gcal-to-wpem.zip
