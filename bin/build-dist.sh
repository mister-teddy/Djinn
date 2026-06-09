#!/usr/bin/env bash
#
# Build an installable plugin ZIP: the plugin code + production Composer deps only. Excludes the
# proxy service, dev tooling, and build artifacts. Output: dist/djinn-<edition>-<version>.zip
#
# Usage: build-dist.sh [free|pro]   (default free)
#   Pro builds stamp DJINN_EDITION='pro'. Set PROXY_URL=https://…, POLAR_ORG_ID=…, and/or
#   PRO_URL=https://buy.polar.sh/… to bake those constants (define-if-not-set, so wp-config.php
#   can still override).
#
set -euo pipefail
cd "$( dirname "$0" )/.."

EDITION="${1:-free}"
[ -z "$EDITION" ] && EDITION="free"
case "$EDITION" in
	free|pro) ;;
	*) echo "Unknown edition '$EDITION' (use: free|pro)." >&2; exit 1 ;;
esac

VERSION="$( grep -oE "DJINN_VERSION', '[^']+" djinn.php | cut -d"'" -f3 )"
STAGE="build/dist/djinn"
ZIP="dist/djinn-$EDITION-$VERSION.zip"

rm -rf build/dist "$ZIP"
mkdir -p "$STAGE" dist

# Copy the plugin, leaving out everything that isn't part of the installable artifact. vendor/ is
# regenerated fresh (prod-only) in the stage so dev packages (phpunit, …) never ship; the compiled
# front-end in build/ DOES ship (CI runs `npm run build` first), but its TS source + tooling don't.
rsync -a --exclude-from=- ./ "$STAGE/" <<'EXCL'
/proxy/
/bin/
/app/
/schema/
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
/CLAUDE.md
/package.json
/package-lock.json
/webpack.config.js
/tsconfig.json
/tailwind.config.js
/postcss.config.js
*.pdf
*.zip
.DS_Store
EXCL

# Stamp the edition into the built plugin (the default build stays 'free').
if [ "$EDITION" = "pro" ]; then
	sed -i.bak "s/define( 'DJINN_EDITION', 'free' )/define( 'DJINN_EDITION', 'pro' )/" "$STAGE/djinn.php"
	rm -f "$STAGE/djinn.php.bak"
fi

# Optionally bake constants after the edition line (define-if-not-set, so wp-config.php can override).
inject=""
[ -n "${PROXY_URL:-}" ]    && inject="${inject}if ( ! defined( 'DJINN_PROXY_URL' ) ) { define( 'DJINN_PROXY_URL', '${PROXY_URL}' ); }\\n"
[ -n "${POLAR_ORG_ID:-}" ] && inject="${inject}if ( ! defined( 'DJINN_POLAR_ORG_ID' ) ) { define( 'DJINN_POLAR_ORG_ID', '${POLAR_ORG_ID}' ); }\\n"
[ -n "${PRO_URL:-}" ]      && inject="${inject}if ( ! defined( 'DJINN_PRO_URL' ) ) { define( 'DJINN_PRO_URL', '${PRO_URL}' ); }\\n"
if [ -n "$inject" ]; then
	sed -i.bak "s#define( 'DJINN_EDITION', '${EDITION}' );#define( 'DJINN_EDITION', '${EDITION}' );\\n${inject}#" "$STAGE/djinn.php"
	rm -f "$STAGE/djinn.php.bak"
fi

echo "→ Installing production dependencies…"
( cd "$STAGE" && composer install --no-dev --optimize-autoloader --no-interaction --quiet )

echo "→ Zipping…"
( cd build/dist && zip -qr "$OLDPWD/$ZIP" djinn -x '*.DS_Store' )

echo "✓ Wrote $ZIP ($( du -h "$ZIP" | cut -f1 )) — edition: $EDITION"
echo "  Proxy excluded: $( unzip -Z1 "$ZIP" | grep -c '^djinn/proxy/' ) proxy files."
