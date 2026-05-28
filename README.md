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
- **Multi-provider.** OpenAI and Google Gemini adapters ship today (`src/Provider/`); the
  `Provider` interface makes adding others straightforward.
- **No build step for the MVP front-end** — it uses WordPress's bundled `wp-element` (React).

## Install (development)

1. `composer install` in the plugin directory.
2. Activate **Djinn** in wp-admin (creates the custom tables).
3. **Djinn → Settings**: choose a provider, paste an API key (or define `DJINN_API_KEY` in
   `wp-config.php`), optionally set model names. Save.
4. **Djinn → Lamp**: click **Awaken the lamp** to build the schema index, then make a wish.

Recommended local environment: [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
(`npx wp-env start`).

## Try it

- *"List my 5 most recent posts."* → `search_schema` → `run_graphql` (query) → answer.
- *"Create a draft page titled 'Hello'."* → "Grant this wish?" card with the mutation →
  **Grant** → the page exists in wp-admin.
- *"Set the site tagline to 'Built with Djinn'."* → Grant → applied.

## Status

This is an MVP. Known next steps:

- Token-by-token streaming (currently the loop is synchronous; the UI shows step progress).
- Broader schema coverage (custom post types, taxonomies, menus, plugin options) and a filter
  for third-party plugins to register their own types/resolvers.
- API-key encryption at rest.
- A proper `@wordpress/scripts` (JSX/TypeScript) build for the front-end.
- Loading prior conversations (the chat list REST endpoint exists; the UI starts fresh).
