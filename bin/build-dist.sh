#!/usr/bin/env bash
#
# Build an installable plugin ZIP: the plugin code + production Composer deps only. Excludes the
# proxy service, dev tooling, and build artifacts. Output: dist/djinn-<version>.zip
#
set -euo pipefail
cd "$( dirname "$0" )/.."

VERSION="$( grep -oE "DJINN_VERSION', '[^']+" djinn.php | cut -d"'" -f3 )"
STAGE="build/dist/djinn"
ZIP="dist/djinn-$VERSION.zip"

rm -rf build/dist "$ZIP"
mkdir -p "$STAGE" dist

# Copy the plugin, leaving out everything that isn't part of the installable artifact. vendor/ is
# regenerated fresh (prod-only) in the stage so dev packages (phpunit, …) never ship.
rsync -a --exclude-from=- ./ "$STAGE/" <<'EXCL'
/proxy/
/bin/
/build/
/dist/
/node_modules/
/vendor/
/.git/
/.github/
/.claude/
/tests/
/.wp-env.json
/Makefile
/.env
/.env.example
/.gitignore
/.djinn-seed.json
/djinn-docs.pdf
*.zip
.DS_Store
EXCL

echo "→ Installing production dependencies…"
( cd "$STAGE" && composer install --no-dev --optimize-autoloader --no-interaction --quiet )

echo "→ Zipping…"
( cd build/dist && zip -qr "$OLDPWD/$ZIP" djinn -x '*.DS_Store' )

echo "✓ Wrote $ZIP ($( du -h "$ZIP" | cut -f1 ))"
echo "  Contents (top level):"
unzip -Z1 "$ZIP" | sed 's#^djinn/##' | awk -F/ 'NF<=2' | sort -u | sed 's/^/    /'
echo "  Proxy excluded: $( unzip -Z1 "$ZIP" | grep -c '^djinn/proxy/' ) proxy files."
