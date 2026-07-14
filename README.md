# Djinn

> Whisper a wish. The Djinn grants it.

An admin AI assistant for WordPress. Speak in plain language — *"create a draft page titled
About"*, *"list my five newest posts"*, *"set the tagline to Built with Djinn"* — and the Djinn
fulfils it.

## How it works

There are no hand-written per-feature tools. The full GraphQL schema rides in the system prompt,
and the Djinn works through a small, fixed toolset:

- `run_graphql` — execute a GraphQL operation. Reads run immediately; **wishes that write pause
  for your blessing**, showing the exact mutation and variables before anything happens.
- `rest_call` — added by the Pro add-on. Calls a WordPress REST route directly, the escape hatch
  for plugins with no native GraphQL field. Reads run immediately; writes pause for your blessing,
  like mutations.

```
Admin SPA (React/Tailwind)  ──POST /wish/stream (SSE)──▶  PHP agent loop (schema in system prompt)
                                                            └─ run_graphql → parse op:
                                                                  query    → execute now
                                                                  mutation → Grant? → execute
                                                                        ▼
                                                      in-house graphql-php schema → WP core functions
```

- **No WPGraphQL dependency.** The schema is our own (`src/GraphQL/`), executed by the bundled
  `webonyx/graphql-php` library. Resolvers wrap `WP_Query`, `wp_insert_post`, `update_option`, …
- **Capabilities are enforced in every resolver** (`current_user_can`) — the Djinn can never
  exceed the logged-in admin's real rights.
- **Extensible schema.** Base capabilities are modular features (`src/GraphQL/Features/`), and the
  Pro add-on keeps paid feature classes under `pro/src/GraphQL/Features/`. Any plugin can register
  its own types/resolvers via the `djinn_register_schema` action — no core edits.
- **Multi-provider.** WordPress AI Client, OpenAI, Google Gemini, and Anthropic adapters ship today
  (`src/Provider/`); the `Provider` interface makes adding others straightforward.
- **The admin UI is a TypeScript/React/Tailwind SPA** in `app/`, built with `@wordpress/scripts`
  into `build/` (React comes from WordPress's bundled `wp-element`). Run `npm run build` (or
  `make watch`) to compile it.
- **The SPA's control plane is its own GraphQL endpoint** — `POST /djinn/v1/graphql`, a small
  hand-built admin schema (`src/GraphQL/Admin/`) on the same `graphql-php`, queried with a typed
  [genql](https://github.com/remorses/genql) client. REST is kept only for what GraphQL can't
  carry: the streaming wish turn (`/wish`, `/wish/stream`), `/grant`, binary `/upload`+`/download`,
  and the public proxy `/claim` pairing callback (its own tiny public `graphql-php` schema,
  `src/GraphQL/PairingSchema.php`).

Two admin screens: **Lamp** (chat) and **Cave of Wonders** — a dashboard of three tiles:
**Account** (provider/key/models or the hosted-proxy account), **Capabilities** (every operation
the Djinn can run), and **Spend** (token + cost usage).

## What you can wish for

The Djinn's reach is whatever the schema exposes. The base WordPress.org plugin includes:

- **Content** — posts, pages, any post type: list, read, create, update, delete.
- **Taxonomies** — categories, tags, custom taxonomies: list/create/delete terms, assign to posts.
- **Comments** — list, approve/spam/trash, reply, delete.
- **Media** — list, import from a URL, set featured image, delete.

The separate Pro add-on adds:

- **Users** — list, create, change role, delete.
- **Appearance** — list/switch themes, edit the site's Additional CSS.
- **Site & options** — title, tagline, and any `wp_options` value.
- **System** — activate/deactivate, install (from WordPress.org), and update plugins & themes;
  update WordPress core. *(Powerful — gated by the user's capabilities and the Grant step.)*

Register more from a plugin without touching core:

```php
add_action( 'djinn_register_schema', function ( $registry ) {
    $registry->addQuery( 'myThing', [ /* a graphql-php field definition */ ] );
} );
```

The full schema rides in the system prompt on every wish, so a schema change is live immediately;
the **Capabilities** tile of **Djinn → Cave of Wonders** lists every operation.

## Install (development)

### Automated (recommended)

Requires Docker and Node (for [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)).

```bash
cp .env.example .env        # optional: set a BYO provider/key; default provider is Djinn
make up                     # composer install + wp-env start + activate + seed settings
npm ci && make build        # install JS deps + compile the admin SPA (or `make watch` to rebuild on change)
make open                   # open wp-admin (admin / password)
```

Then visit **Djinn → Lamp** and make a wish. Other targets: `make restart` (factory reset), `make cli "<wp-cli args>"`, `make logs`, `make down` (stop), `make destroy`
(wipe). `make up` is idempotent — re-run it (or `make seed`) after editing `.env`.

### Manual

1. `composer install` in the plugin directory.
2. `npm ci && npm run build` to compile the admin SPA into `build/` (the admin shows a notice until you do).
3. Activate **Djinn** in wp-admin (creates the custom tables).
// out of date
4. **Djinn → Cave of Wonders**, Account tile: Djinn is selected by default. For a BYO provider,
   choose OpenAI/Gemini/Anthropic, paste an API key (or define `DJINN_API_KEY` in `wp-config.php`);
   models are picked from dropdowns discovered from your key. Save.
5. Open **Djinn → Lamp** and make a wish.

## Try it

- *"List my 5 most recent posts."* → `run_graphql` (query) → answer.
- *"Create a draft page titled 'Hello'."* → "Grant this wish?" card → **Grant** → it exists.
- *"Make all headings dark red."* → Grant → Additional CSS updated.
- *"Install and activate the Classic Editor plugin."* → Grant → installed from WordPress.org.
- *"Are any updates available?"* → query → answer.

The chat keeps a history sidebar (reopened across reloads), shows the exact GraphQL it ran (with
the response), and meters tokens + cost per conversation. The **Spend** tile of **Djinn → Cave of
Wonders** has the running total.

Replies are **rich**: Markdown (headings, lists, tables, code, images), **View/Edit links** to
whatever a wish touched, and **token streaming** (live typing, with step progress). You can
**attach a file** (📎) for the Djinn to import, and ask it to **export** content (WXR) or **back
up the database** (SQL) — both returned as gated download links. Streaming uses Server-Sent Events
on direct OpenAI/Gemini; the hosted proxy edition replies in one shot (no SSE passthrough yet).

## What it costs

Every provider call is metered (**Djinn → Cave of Wonders**, Spend tile). Measured from real local usage on
`gemini-2.5-flash-lite` (14 wishes):

| Per wish (average) | Value |
|---|---|
| Tokens | ~6,900 |
| **Cost** | **~$0.0009** — about a tenth of a cent |

A wish fans out to ~1–2 model calls (GraphQL → reply); the full schema rides in the system prompt,
where providers cache it. Model choice dominates: a flagship like GPT-4o runs roughly 30–50×
pricier per wish. Figures are estimates from public list prices — tune them with the
`djinn_model_pricing` filter.

## Editions

Djinn ships as a base WordPress.org plugin plus a separate Pro add-on. They differ only in
**scope** — every inference provider (your own OpenAI / Gemini / Anthropic / Claude Code key, or
the managed Djinn proxy) works with the base plugin.

| | **Base** (WordPress.org) | **Pro add-on** (separate ZIP) |
|---|---|---|
| Writes | Content: posts, pages, media, categories, comments | Full schema: users, settings, navigation, appearance, plugins/themes/core, WooCommerce |
| `rest_call` escape hatch | — | ✓ (any REST route) |
| Reads | Everything | Everything |
| Providers | All (BYO key or managed proxy) | All (BYO key or managed proxy) |
| Activation | Activate the base plugin | Install and activate `djinn-pro` alongside the base plugin |

The managed proxy is available in the base plugin (prepaid credit via Polar); the add-on extends
capability scope, not provider access. Build installable ZIPs:

```bash
make dist                                  # base -> dist/djinn-free-<ver>.zip
make dist pro                              # add-on -> dist/djinn-pro-<ver>.zip
make dist all PROXY_URL=https://your-proxy # both, with an optional proxy override in base
```

To test Pro locally, install and activate the `pro/djinn-pro.php` add-on next to the base plugin.

### Pro features

Pro unlocks the full schema scope today. On the Pro roadmap:

- **Bulk + auto-grant** — batch wishes ("rewrite all 200 product descriptions") and trusted-op
  auto-grant that skips per-step confirmation for whitelisted mutations.
- **Scheduled / recurring wishes** — cron-driven autonomy ("every Monday, draft a post from my
  newsletter"; "weekly broken-link sweep").
- **Site memory (RAG)** — a full-site semantic index with auto-reindex on publish, so wishes draw on
  your existing content and keep a consistent brand voice.
- **Multi-site control** — manage many sites from one license, with a shared prompt library and seats.
- **History / undo / audit** — full wish history with diffs and one-click revert.

## The hosted proxy

The managed proxy takes no API key — wishes route through a small hosted, OpenAI-compatible gateway
that meters usage and bills prepaid credit (topped up via Polar). It is a separate service (not in
this repo, and excluded from `make dist`). The data-use disclosure for WordPress.org is in
[`docs/PRIVACY-DISCLOSURE.md`](docs/PRIVACY-DISCLOSURE.md).
