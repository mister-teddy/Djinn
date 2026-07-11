ARG BASE_IMAGE=pandoc/latex:3.10.0.0-ubuntu
FROM ${BASE_IMAGE}

USER root
ENV DEBIAN_FRONTEND=noninteractive
ENV PATH="/usr/bin:${PATH}"

RUN set -eux; \
	if kpsewhich fvextra.sty >/dev/null 2>&1; then \
		exit 0; \
	fi; \
	if command -v apt-get >/dev/null 2>&1; then \
		apt-get update; \
		apt-get install -y --no-install-recommends texlive-fonts-recommended texlive-latex-extra texlive-xetex; \
		rm -rf /var/lib/apt/lists/*; \
	elif command -v tlmgr >/dev/null 2>&1; then \
		tlmgr update --self; \
		tlmgr install fvextra; \
	elif command -v apk >/dev/null 2>&1; then \
		apk add --no-cache texlive-latexextra; \
	else \
		echo "No supported TeX package manager could install fvextra." >&2; \
		exit 1; \
	fi; \
	kpsewhich fvextra.sty >/dev/null; \
	xelatex --version >/dev/null
