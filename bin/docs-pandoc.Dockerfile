ARG BASE_IMAGE=pandoc/latex:latest
FROM ${BASE_IMAGE}

USER root

RUN set -eux; \
	if kpsewhich fvextra.sty >/dev/null 2>&1; then \
		exit 0; \
	fi; \
	if command -v tlmgr >/dev/null 2>&1 && tlmgr install fvextra; then \
		:; \
	elif command -v apk >/dev/null 2>&1; then \
		apk add --no-cache texlive-latexextra; \
	elif command -v apt-get >/dev/null 2>&1; then \
		apt-get update; \
		apt-get install -y --no-install-recommends texlive-latex-extra; \
		rm -rf /var/lib/apt/lists/*; \
	else \
		echo "No supported TeX package manager could install fvextra." >&2; \
		exit 1; \
	fi; \
	kpsewhich fvextra.sty >/dev/null
