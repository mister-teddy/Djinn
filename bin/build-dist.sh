#!/usr/bin/env bash
#
# Build installable ZIPs.
#
# Usage:
#   build-dist.sh [base|free]  # WordPress.org/base plugin, default
#   build-dist.sh pro          # paid add-on plugin
#   build-dist.sh all          # both packages
#
set -euo pipefail
cd "$( dirname "$0" )/.."

TARGET="${1:-base}"
[ -z "$TARGET" ] && TARGET="base"
case "$TARGET" in
	base|free|pro|all) ;;
	*) echo "Unknown target '$TARGET' (use: base, free, pro, all)." >&2; exit 1 ;;
esac

VERSION="$( grep -oE "DJINN_VERSION', '[^']+" djinn.php | cut -d"'" -f3 )"
rm -rf build/dist
mkdir -p build/dist dist

inject_base_constants() {
	local file="$1"
	local inject=""
	[ -n "${PROXY_URL:-}" ] && inject="${inject}if ( ! defined( 'DJINN_PROXY_URL' ) ) { define( 'DJINN_PROXY_URL', '${PROXY_URL}' ); }\\n"
	[ -n "${PRO_URL:-}" ] && inject="${inject}if ( ! defined( 'DJINN_PRO_URL' ) ) { define( 'DJINN_PRO_URL', '${PRO_URL}' ); }\\n"
	if [ -n "$inject" ]; then
		sed -i.bak "s#define( 'DJINN_URL', plugin_dir_url( __FILE__ ) );#define( 'DJINN_URL', plugin_dir_url( __FILE__ ) );\\n${inject}#" "$file"
		rm -f "$file.bak"
	fi
}

build_base() {
	local stage="build/dist/djinn"
	local zip="dist/djinn-free-$VERSION.zip"
	rm -rf "$stage" "$zip"
	mkdir -p "$stage"

	rsync -a --exclude-from=- ./ "$stage/" <<'EXCL'
/proxy/
/pro/
/bin/
/app/
/schema/
/dist/
/build/dist/
/node_modules/
/vendor/
/tests/
/docs/
/Makefile
/README.md
/phpcs.xml
/CLAUDE.md
/package.json
/package-lock.json
/pnpm-lock.yaml
/pnpm-workspace.yaml
/webpack.config.js
/tsconfig.json
/tailwind.config.js
/postcss.config.js
/.*
*.pdf
*.zip
.DS_Store
EXCL

	inject_base_constants "$stage/djinn.php"

	echo "-> Installing production dependencies for base plugin..."
	( cd "$stage" && composer install --no-dev --optimize-autoloader --no-interaction --quiet )

	echo "-> Zipping base plugin..."
	( cd build/dist && zip -qr "$OLDPWD/$zip" djinn -x '*.DS_Store' )
	echo "✓ Wrote $zip ($( du -h "$zip" | cut -f1 ))"
	echo "  Pro add-on files included: $( unzip -Z1 "$zip" | grep -c '^djinn/pro/' )"
}

build_pro() {
	local stage="build/dist/djinn-pro"
	local zip="dist/djinn-pro-$VERSION.zip"
	rm -rf "$stage" "$zip"
	mkdir -p "$stage"

	rsync -a --exclude-from=- pro/ "$stage/" <<'EXCL'
/.*
*.zip
.DS_Store
EXCL

	echo "-> Zipping Pro add-on..."
	( cd build/dist && zip -qr "$OLDPWD/$zip" djinn-pro -x '*.DS_Store' )
	echo "✓ Wrote $zip ($( du -h "$zip" | cut -f1 ))"
}

case "$TARGET" in
	base|free)
		build_base
		;;
	pro)
		build_pro
		;;
	all)
		build_base
		build_pro
		;;
esac
