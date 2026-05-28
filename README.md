# Djinn

> Whisper a wish. The Djinn grants it.

An admin AI assistant for WordPress. Speak in plain language — *"create a draft page titled
About"*, *"list my five newest posts"*, *"set the tagline to Built with Djinn"* — and the Djinn
fulfils it by **generating GraphQL on the fly** against an in-house schema of your site.

There are no hand-written per-feature tools. The Djinn has exactly two:

- `search_schema` — semantic (RAG) search over the site's GraphQL schema.
- `run_graphql` — execute a GraphQL operation. Reads run immediately; **wishes that write pause
  for your blessing**, showing the exact mutation and variables before anything happens.

## How it works

```
Admin SPA (bundled React)  ──fetch──▶  REST /djinn/v1/*  ──▶  PHP agent loop
                                                                ├─ search_schema → RAG retriever (cosine)
                                                                └─ run_graphql   → parse op:
                                                                      query    → execute now
                                                                      mutation → Grant? → execute
                                                                            ▼
                                                          in-house graphql-php schema → WP core functions
```

- **No WPGraphQL dependency.** The schema is our own (`src/GraphQL/`), executed by the bundled
  `webonyx/graphql-php` library. Resolvers wrap `WP_Query`, `wp_insert_post`, `update_option`, …
- **Capabilities are enforced in every resolver** (`current_user_can`) — the Djinn can never
  exceed the logged-in admin's real rights.
- **Extensible schema.** Capabilities are modular features (`src/GraphQL/Features/`); a plugin can
  register its own types/resolvers via the `djinn_register_schema` action — no core edits.
- **Multi-provider.** OpenAI and Google Gemini adapters ship today (`src/Provider/`); the
  `Provider` interface makes adding others straightforward.
- **No build step for the front-end** — it uses WordPress's bundled `wp-element` (React).

Four admin screens: **Lamp** (chat), **Settings** (provider/key/models), **Memory** (build &
inspect the schema index), **Spend** (token + cost usage).

## What you can wish for

The Djinn's reach is whatever the schema exposes. Today, across modular features:

- **Content** — posts, pages, any post type: list, read, create, update, delete.
- **Taxonomies** — categories, tags, custom taxonomies: list/create/delete terms, assign to posts.
- **Comments** — list, approve/spam/trash, reply, delete.
- **Users** — list, create, change role, delete.
- **Media** — list, import from a URL, set featured image, delete.
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

After any schema change, rebuild the index from **Djinn → Memory** (it shows a diff of what
changed and the estimated embedding cost first).

## Install (development)

### Automated (recommended)

Requires Docker and Node (for [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)).

```bash
cp .env.example .env        # then paste your provider API key into .env
make up                     # composer install + wp-env start + activate + seed settings
make lamp                   # build the schema index (same as Djinn → Memory → Rebuild)
make open                   # open wp-admin (admin / password)
```

Then visit **Djinn → Lamp** and make a wish. Other targets: `make restart` (validate + bounce
after editing), `make cli "<wp-cli args>"`, `make logs`, `make down` (stop), `make destroy`
(wipe). `make up` is idempotent — re-run it (or `make seed`) after editing `.env`.

### Manual

1. `composer install` in the plugin directory.
2. Activate **Djinn** in wp-admin (creates the custom tables).
3. **Djinn → Settings**: choose a provider, paste an API key (or define `DJINN_API_KEY` in
   `wp-config.php`); models are picked from dropdowns discovered from your key. Save.
4. **Djinn → Memory**: **Awaken the lamp** to build the schema index, then open **Djinn → Lamp**
   and make a wish.

## Try it

- *"List my 5 most recent posts."* → `search_schema` → `run_graphql` (query) → answer.
- *"Create a draft page titled 'Hello'."* → "Grant this wish?" card → **Grant** → it exists.
- *"Make all headings dark red."* → Grant → Additional CSS updated.
- *"Install and activate the Classic Editor plugin."* → Grant → installed from WordPress.org.
- *"Are any updates available?"* → query → answer.

The chat keeps a history sidebar (reopened across reloads), shows the exact GraphQL it ran (with
the response), and meters tokens + cost per conversation. **Djinn → Spend** has the running total.

## What it costs

Every provider call is metered (**Djinn → Spend**). Measured from real local usage on
`gemini-2.5-flash-lite` (14 wishes):

| Per wish (average) | Value |
|---|---|
| Tokens | ~6,900 |
| **Cost** | **~$0.0009** — about a tenth of a cent |

A wish fans out to ~1–3 model calls (schema search → GraphQL → reply) plus a tiny embedding;
embeddings (search + the one-time index build) are effectively free on `gemini-embedding-001`.
Model choice dominates: a flagship like GPT-4o runs roughly 30–50× pricier per wish. Figures are
estimates from public list prices — tune them with the `djinn_model_pricing` filter.

## Status

Known next steps:

- Token-by-token streaming (the loop is synchronous; the UI shows step progress).
- API-key encryption at rest.
- A proper `@wordpress/scripts` (JSX/TypeScript) build for the front-end.
