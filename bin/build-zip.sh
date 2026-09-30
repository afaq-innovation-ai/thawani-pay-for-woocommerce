#!/usr/bin/env bash
# Builds an installable plugin zip in ./dist, excluding everything listed in .distignore.
# Usage: bin/build-zip.sh
set -euo pipefail

SLUG="thawani-pay-for-woocommerce"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(grep -m1 -E '^\s*\*\s*Version:' "$ROOT/$SLUG.php" | awk '{print $NF}')"
BUILD="$ROOT/build/$SLUG"

rm -rf "$ROOT/build" && mkdir -p "$BUILD" "$ROOT/dist"

rsync -a "$ROOT/" "$BUILD/" --exclude-from="$ROOT/.distignore"

( cd "$ROOT/build" && zip -rq "$ROOT/dist/$SLUG-$VERSION.zip" "$SLUG" )
rm -rf "$ROOT/build"

echo "Built dist/$SLUG-$VERSION.zip"
