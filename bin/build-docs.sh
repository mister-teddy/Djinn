#!/usr/bin/env bash
#
# Build a single, offline-readable PDF of the whole project: the README, the proxy docs (if present), and an
# appendix with every source file, syntax-highlighted. One command, always current — it reads the
# files fresh each run. Rendering uses a Dockerized pandoc, so no host LaTeX install is required.
#
set -euo pipefail
cd "$( dirname "$0" )/.."

VERSION="$( grep -oE "DJINN_VERSION', '[^']+" djinn.php | cut -d"'" -f3 )"

# Optional first arg selects a page preset. "compact" (aka phone/foldable) → a compact, near-square
# page with tiny margins, sized to read comfortably on a foldable's inner screen (e.g. OnePlus Open).
SIZE="${1:-}"
OUT="Djinn — Full Documentation.pdf"
case "$SIZE" in
	compact|phone|foldable) SIZE=compact; OUT="Djinn — Full Documentation (Compact).pdf" ;;
	'' ) ;;
	*) echo "Unknown size '$SIZE' (use: compact). Falling back to default." >&2; SIZE='' ;;
esac

BUILD="build"
DOC="$BUILD/docs.md"
HEADER="$BUILD/header.tex"
FONTS="$BUILD/fonts"
mkdir -p "$BUILD" "$FONTS"

# Vendor the Cardo body font (OFL) once into a cached dir; load it by path so we don't depend on
# the font being present in the pandoc image.
CARDO_BASE="https://raw.githubusercontent.com/google/fonts/main/ofl/cardo"
for style in Regular Bold Italic; do
	if [ ! -f "$FONTS/Cardo-${style}.ttf" ]; then
		echo "→ Fetching Cardo-${style}"
		curl -fsSL "$CARDO_BASE/Cardo-${style}.ttf" -o "$FONTS/Cardo-${style}.ttf"
	fi
done

# Map a file path to a pandoc highlight language.
lang_for() {
	case "$1" in
		*.php) echo php ;;
		*.js)  echo javascript ;;
		*.css) echo css ;;
		*.sh)  echo bash ;;
		*.json) echo json ;;
		*.rs)  echo rust ;;
		*.toml) echo toml ;;
		*.sql) echo sql ;;
		*Dockerfile) echo dockerfile ;;
		Makefile) echo makefile ;;
		*) echo text ;;
	esac
}

# --- Assemble the combined Markdown -------------------------------------------------------------
{
	cat <<-YAML
	---
	title: "Djinn — Full Documentation"
	subtitle: "Plugin v$VERSION · generated $( date -u '+%Y-%m-%d %H:%M UTC' )"
	toc: true
	toc-depth: 2
	---
	YAML

	# 1) Prose docs, rendered: the README, anything under docs/, then the proxy docs.
	cat README.md

	# Showcase: embed the captured Lamp screenshots (bin/showcase-shots.mjs), if present, so the
	# manual carries them instead of shipping loose image files. Graceful when none exist.
	if compgen -G "build/screenshots/*.png" > /dev/null; then
		printf '\n\n\\newpage\n\n# Showcase\n\nThe Lamp, granting real wishes — captured from the live UI.\n\n'
		for img in build/screenshots/*.png; do
			cap=$( basename "$img" .png | sed -E 's/^[0-9]+-//; s/-/ /g' )
			printf '![%s](%s){ width=100%% }\n\n' "$cap" "$img"
		done
	fi

	for d in docs/*.md; do
		[ -f "$d" ] || continue
		printf '\n\n\\newpage\n\n'
		cat "$d"
	done
	# The proxy lives in its own repo now; embed its docs only if present (e.g. a local symlink).
	if [ -f proxy/README.md ]; then
		printf '\n\n\\newpage\n\n# Proxy service\n\n'
		cat proxy/README.md
	fi

	# 2) Source appendix. Each file becomes a heading + a highlighted code block. Tilde fences (×5)
	#    avoid colliding with backtick fences that appear inside source/markdown.
	printf '\n\n\\newpage\n\n# Source code\n\n'
	printf 'The full source, current as of this build.\n'

	# Use find (not git ls-files) so untracked proxy sources are included too.
	FILES=$(
		{
			echo djinn.php
			find src -name '*.php' 2>/dev/null | sort
			echo assets/admin.js
			echo assets/admin.css
			find bin -name '*.sh' 2>/dev/null | sort
			echo Makefile
			echo composer.json
			echo .wp-env.json
			if [ -d proxy ]; then
				echo proxy/Cargo.toml
				echo proxy/Dockerfile
				find proxy/src -name '*.rs' 2>/dev/null | sort
				find proxy/migrations -name '*.sql' 2>/dev/null | sort
			fi
		} | awk '!seen[$0]++'
	)
	for f in $FILES; do
		[ -f "$f" ] || continue
		printf '\n## `%s`\n\n~~~~~ {.%s}\n' "$f" "$( lang_for "$f" )"
		cat "$f"
		printf '\n~~~~~\n'
	done
} > "$DOC"

# --- LaTeX header: wrap long code lines so nothing overflows the page ---------------------------
cat > "$HEADER" <<'TEX'
\usepackage{fvextra}
\DefineVerbatimEnvironment{Highlighting}{Verbatim}{breaklines,breakanywhere,fontsize=\small,commandchars=\\\{\}}
\usepackage{microtype}
TEX

# --- Render via Dockerized pandoc ---------------------------------------------------------------
if ! docker info >/dev/null 2>&1; then
	echo "✗ Docker isn't running — start it (OrbStack/Docker Desktop) and retry." >&2
	exit 1
fi

PANDOC_ARGS=(
	"$DOC" -o "$OUT"
	--pdf-engine=xelatex
	--toc --toc-depth=2 --number-sections
	--highlight-style=tango
	-H "$HEADER"
	-V mainfont=Cardo
	-V "mainfontoptions=Path=/data/$FONTS/, Extension=.ttf, UprightFont=*-Regular, BoldFont=*-Bold, ItalicFont=*-Italic"
	-V colorlinks=true -V linkcolor=NavyBlue -V urlcolor=NavyBlue
	-V documentclass=report
)
case "$SIZE" in
	compact)
		# Compact near-square page for foldable inner screens; fit-to-width gives large text.
		PANDOC_ARGS+=( -V geometry:paperwidth=130mm -V geometry:paperheight=150mm -V geometry:margin=6mm -V fontsize=9pt )
		;;
	*)
		PANDOC_ARGS+=( -V geometry:margin=2cm )
		;;
esac

echo "→ Rendering $OUT (pandoc via Docker)…"
# Use a fixed Ubuntu stack so texlive-latex-extra augments the same TeX tree that pandoc uses.
# The wrapper image installs and verifies fvextra, which wraps long code lines in the generated LaTeX.
PANDOC_BASE_IMAGE="${PANDOC_BASE_IMAGE:-pandoc/latex:3.10.0.0-ubuntu}"
PANDOC_IMAGE="${PANDOC_IMAGE:-djinn-docs-pandoc:fvextra}"
docker build --pull --platform=linux/amd64 \
	--build-arg BASE_IMAGE="$PANDOC_BASE_IMAGE" \
	-t "$PANDOC_IMAGE" \
	-f bin/docs-pandoc.Dockerfile bin

docker run --rm --platform=linux/amd64 -v "$PWD":/data -w /data --entrypoint pandoc "$PANDOC_IMAGE" "${PANDOC_ARGS[@]}"

echo "✓ Wrote $OUT ($( du -h "$OUT" | cut -f1 ))"
