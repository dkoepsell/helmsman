#!/usr/bin/env sh
# Builds dist/helmsman-<version>.zip: the folder you upload to a web host.
# Usage: sh tools/build-release.sh
set -eu
cd "$(dirname "$0")/.."
VERSION=$(sed -n "s/.*define('APP_VERSION', '\([^']*\)').*/\1/p" src/lib/core.php)
[ -n "$VERSION" ] || { echo "Could not read APP_VERSION from src/lib/core.php" >&2; exit 1; }
echo "Running self-test…"
php tests/selftest.php
OUT="dist/helmsman-$VERSION.zip"
STAGE=$(mktemp -d)
mkdir -p dist "$STAGE/helmsman"
# Copy the app, leaving out any local data or settings.
(cd src && tar --exclude=./data --exclude=./config.php -cf - .) | (cd "$STAGE/helmsman" && tar xf -)
cp LICENSE "$STAGE/helmsman/LICENSE.txt"
rm -f "$OUT"
(cd "$STAGE" && zip -qr - helmsman) > "$OUT"
rm -rf "$STAGE"
echo "Built $OUT"
