#!/usr/bin/env bash
#
# Build an installable plugin ZIP: the plugin code + production Composer deps only. Excludes the
# proxy service, dev tooling, and build artifacts. Output: dist/djinn-<edition>-<version>.zip
#
# Usage: build-dist.sh [byo|org]   (default byo)
#   ORG builds stamp DJINN_EDITION='org'. Set PROXY_URL=https://… to also bake the proxy URL.
#
set -euo pipefail
cd "$( dirname "$0" )/.."

EDITION="${1:-byo}"
[ -z "$EDITION" ] && EDITION="byo"
case "$EDITION" in
	byo|org) ;;
	*) echo "Unknown edition '$EDITION' (use: byo|org)." >&2; exit 1 ;;
esac

VERSION="$( grep -oE "DJINN_VERSION', '[^']+" djinn.php | cut -d"'" -f3 )"
STAGE="build/dist/djinn"
ZIP="dist/djinn-$EDITION-$VERSION.zip"

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

# Stamp the edition (and optionally the proxy URL) into the built plugin.
if [ "$EDITION" = "org" ]; then
	sed -i.bak "s/define( 'DJINN_EDITION', 'byo' )/define( 'DJINN_EDITION', 'org' )/" "$STAGE/djinn.php"
	if [ -n "${PROXY_URL:-}" ]; then
		sed -i.bak "s#define( 'DJINN_EDITION', 'org' );#define( 'DJINN_EDITION', 'org' );\\nif ( ! defined( 'DJINN_PROXY_URL' ) ) { define( 'DJINN_PROXY_URL', '${PROXY_URL}' ); }#" "$STAGE/djinn.php"
	fi
	rm -f "$STAGE/djinn.php.bak"
fi

echo "→ Installing production dependencies…"
( cd "$STAGE" && composer install --no-dev --optimize-autoloader --no-interaction --quiet )

echo "→ Zipping…"
( cd build/dist && zip -qr "$OLDPWD/$ZIP" djinn -x '*.DS_Store' )

echo "✓ Wrote $ZIP ($( du -h "$ZIP" | cut -f1 )) — edition: $EDITION"
echo "  Proxy excluded: $( unzip -Z1 "$ZIP" | grep -c '^djinn/proxy/' ) proxy files."
