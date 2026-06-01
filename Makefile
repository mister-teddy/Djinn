# Djinn local environment. `make up` takes you from clone to a running, configured site.
#
#   make up      composer install + start wp-env + activate Djinn + seed settings from .env
#   make lamp    build the RAG schema index ("Awaken the lamp") — needs a valid API key
#   make cli ...  run an arbitrary WP-CLI command, e.g. `make cli "plugin list"`
#   make logs    tail the WordPress debug log
#   make down    stop the environment (keeps data)
#   make destroy stop and wipe all data/volumes
#   make open    open the site and wp-admin in a browser

# Load .env if present so DJINN_* vars are available to the seed step.
ifneq (,$(wildcard .env))
include .env
export
endif

DJINN_PROVIDER ?= openai
WPENV := npx @wordpress/env
WP := $(WPENV) run cli wp
# wp-env mounts this directory as a plugin under its folder name (case-sensitive).
SLUG := $(notdir $(CURDIR))

.PHONY: up start restart check activate seed lamp cli logs down destroy open docs dist release

up: start activate seed
	@echo ""
	@echo "Djinn is awake → http://localhost:8888/wp-admin (admin / password)"
	@echo "Next: 'make lamp' to build the schema index, then visit Djinn → Lamp and make a wish."

# Factory reset: wipe the local WordPress (database + uploads + volumes) and rebuild from scratch —
# fresh core, composer install, re-activate, re-seed, and re-apply .wp-env config (incl. the ORG
# override). Use when the dev site is in a bad state or to start clean. You DON'T need this for code
# changes: the plugin is bind-mounted, so edited PHP/JS is live on a browser refresh.
restart:
	@echo "⚠  Factory reset — destroying the local WordPress (all data) and rebuilding."
	$(WPENV) destroy
	@$(MAKE) up

# Static checks: lint every PHP file, syntax-check the front-end, refresh the autoloader.
check:
	@echo "→ Linting PHP…"
	@err=0; for f in djinn.php $$(find src -name '*.php'); do \
		php -l "$$f" >/dev/null 2>&1 || { echo "  ✗ $$f"; php -l "$$f"; err=1; }; \
	done; [ $$err -eq 0 ] || exit 1; echo "  ✓ PHP OK"
	@echo "→ Checking front-end JS…"
	@node --check assets/admin.js && echo "  ✓ assets/admin.js OK"
	@echo "→ Regenerating Composer autoloader…"
	@composer dump-autoload -o 2>&1 | tail -1

start:
	composer install --no-interaction
	$(WPENV) start

# wp-env auto-activates plugins from .wp-env.json; this is idempotent and fires the
# activation hook (which creates Djinn's custom tables) if it hasn't run yet.
activate:
	$(WP) plugin activate $(SLUG)

# Seed provider + API key into the djinn_settings option, mirroring what the Settings UI saves.
# The key is written to a short-lived, gitignored file (mounted into the container) and loaded
# via its path — so it never appears in the command line, process list, or wp-env's echo.
seed:
	@printf '{"provider":"%s","api_key":"%s","chat_model":"%s","embedding_model":"%s"}' \
		'$(DJINN_PROVIDER)' '$(DJINN_API_KEY)' '$(DJINN_CHAT_MODEL)' '$(DJINN_EMBEDDING_MODEL)' > .djinn-seed.json
	@$(WP) eval 'update_option("djinn_settings", json_decode(file_get_contents(ABSPATH."wp-content/plugins/$(SLUG)/.djinn-seed.json"), true));' >/dev/null
	@rm -f .djinn-seed.json
	@if [ -z "$(strip $(DJINN_API_KEY))" ]; then \
		echo "⚠  DJINN_API_KEY is empty — set it in .env and re-run 'make seed', or use Djinn → Settings."; \
	else \
		echo "✔  Seeded provider=$(DJINN_PROVIDER) and API key into djinn_settings (key not echoed)."; \
	fi

# "Awaken the lamp": build the RAG index. Makes real embedding API calls, so it needs a key.
lamp:
	$(WP) eval 'echo \Djinn\Rag\Indexer::reindex(), " chunks indexed\n";'

cli:
	$(WP) $(filter-out $@,$(MAKECMDGOALS))

logs:
	$(WPENV) run cli tail -f /var/www/html/wp-content/debug.log

down:
	$(WPENV) stop || true

destroy:
	$(WPENV) destroy

open:
	open http://localhost:8888/wp-admin || true

# Generate djinn-docs.pdf: README + docs/ + proxy docs + full syntax-highlighted source. Always
# current. `make docs compact` (or `make docs SIZE=compact`) exports a compact page for foldable
# phones (→ djinn-docs-compact.pdf).
docs:
	bash bin/build-docs.sh "$(or $(SIZE),$(filter-out docs,$(MAKECMDGOALS)))"

# Build an installable plugin ZIP (excludes the proxy, dev tooling, build artifacts).
# `make dist org` or `make dist EDITION=org` for the WordPress.org build; default is byo.
dist:
	bash bin/build-dist.sh "$(or $(EDITION),$(filter-out dist,$(MAKECMDGOALS)))"

# Cut a release: verify the version is identical in all three spots, then tag it and push the tag,
# which fires the GitHub "Release" workflow (builds the BYO + ORG zips + docs, publishes a Release).
# Bump DJINN_VERSION + the plugin header + readme.txt's Stable tag together, commit, then `make release`.
release:
	@VER=$$(grep -oE "DJINN_VERSION', '[^']+" djinn.php | cut -d"'" -f3); \
		HDR=$$(grep -oE "Version:[[:space:]]+[^[:space:]]+" djinn.php | head -1 | awk '{print $$2}'); \
		TAG=$$(grep -i "Stable tag:" readme.txt | head -1 | awk '{print $$NF}'); \
		test -n "$$VER" || { echo "✗ Could not read DJINN_VERSION from djinn.php."; exit 1; }; \
		{ [ "$$VER" = "$$HDR" ] && [ "$$VER" = "$$TAG" ]; } || { echo "✗ Version drift — DJINN_VERSION=$$VER · plugin header=$$HDR · readme.txt Stable tag=$$TAG. Make all three match first."; exit 1; }; \
		test -z "$$(git status --porcelain)" || { echo "✗ Working tree not clean — commit first; the release builds the tagged commit."; exit 1; }; \
		if git rev-parse "v$$VER" >/dev/null 2>&1; then echo "✗ Tag v$$VER already exists — bump the version first."; exit 1; fi; \
		echo "→ Releasing v$$VER (header, DJINN_VERSION, Stable tag all match)…"; \
		git push origin HEAD && git tag "v$$VER" && git push origin "v$$VER" && \
		echo "✔  Pushed tag v$$VER — the Release workflow is now building it on GitHub."

# Swallow extra goals so `make cli "plugin list"` doesn't error on the trailing words.
%:
	@:
