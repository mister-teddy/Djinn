=== Djinn Admin AI Assistant ===
Contributors: misterteddy
Tags: ai, assistant, automation, content, gemini
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.7.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An admin AI assistant that turns plain-language wishes into capability-gated WordPress actions with one-click approval.

== Description ==

Djinn is an AI assistant for the WordPress admin. Speak plainly — *"create a draft page titled About"*, *"list my five newest posts"*, *"make all headings dark red"* — and Djinn fulfils it by generating GraphQL against an in-house schema of your site. There are no hand-written per-feature tools: Djinn searches your site's schema, composes the operation, and runs it. **Reads run immediately; any change pauses for your one-click approval, showing the exact operation first.**

Every action is capability-gated — Djinn can never exceed the logged-in admin's real rights.

What you can wish for: the WordPress.org plugin reads anything on your site, and writes content — posts & pages, media, categories & tags, comments — all gated by your capabilities and the approval step. **Djinn Pro** is a separate add-on plugin, distributed outside WordPress.org, that adds schema domains for users, settings, appearance & the site editor, options, system management (plugins, themes, core), WooCommerce, and a universal REST escape hatch.

= Free vs. Pro =

The base plugin and Pro add-on differ only in **scope** — every model option works with the base plugin.

* **Base plugin** — read anything; write content (posts, pages, media, categories, comments). Use WordPress AI Client when your site has it configured, your own OpenAI, Google Gemini, or Anthropic key, or the managed Djinn proxy (no key to paste; prepaid credit handled by Polar).
* **Pro add-on** — a separate plugin that requires the base plugin and adds the full schema scope above plus the REST escape hatch. The Pro add-on code is not included in this WordPress.org package.

= How LLM calls reach a provider =

* **WordPress AI Client** — when available, calls go through the site-level AI provider configured in WordPress.
* **Your own key** — calls go directly from your server to the provider you configure (OpenAI, Google Gemini, or Anthropic).
* **Managed proxy** — no key needed; wishes route through Djinn's hosted gateway, which forwards model requests to Google Gemini, meters usage, and bills prepaid credit through Polar (see *External services*).

== External services ==

To fulfil a wish, Djinn sends data to a large language model. This happens **only when you make a wish** — Djinn does not phone home in the background.

**What is sent (per wish):** your typed request; recent messages in the same chat (context); the site's GraphQL **schema** (type/field names); and the **results of read queries** Djinn runs to fulfil it (which may include site content). Djinn does **not** send your password, stored API key, or database wholesale.

**Where it goes:**

* **WordPress AI Client** — used only when you choose WordPress AI Client. Djinn passes the wish payload described above to the site-level AI provider configured in WordPress; the destination and credentials are managed by WordPress and that provider's connector.
* **Djinn hosted gateway** — if you choose the managed proxy, requests go to https://djinn-proxy-351601184057.asia-northeast1.run.app . It is used to forward your wish to Google Gemini, meter token usage, and return the model response. It receives the wish payload described above when you make a wish, plus site/account metadata needed for pairing and metering. Operated by Nguyễn Hồng Phát. Terms: https://djinn-proxy-351601184057.asia-northeast1.run.app/terms — Privacy: https://djinn-proxy-351601184057.asia-northeast1.run.app/privacy
* **Google Gemini** — used when you choose Google Gemini with your own key, and also used behind the managed proxy. It receives the wish payload described above when you make a wish. Gemini API terms: https://ai.google.dev/gemini-api/terms — Google privacy policy: https://policies.google.com/privacy
* **OpenAI** — used only when you choose OpenAI with your own key. It receives the wish payload described above when you make a wish. Terms: https://openai.com/policies/row-terms-of-use/ — Privacy: https://openai.com/policies/row-privacy-policy/
* **Anthropic** — used only when you choose Anthropic with your own key. It receives the wish payload described above when you make a wish. Commercial terms: https://www.anthropic.com/legal/commercial-terms — Privacy center: https://privacy.claude.com/en/
* **Polar** — used only when you add prepaid credit or enable auto top-up for the managed proxy. Checkout opens from the admin screen and sends billing/checkout information needed to process payment; Djinn does not send wish prompt bodies to Polar. Buyer terms: https://polar.sh/legal/checkout-buyer-terms — Privacy: https://polar.sh/legal/privacy

The hosted gateway retains only usage metadata (token counts, model, timestamps, your account) to meter and bill — not your prompt or response bodies. Top-ups, if you add credit, are handled by Polar (the merchant of record).

== Installation ==

1. Install and activate the plugin.
2. In **Djinn → Cave of Wonders** (Account tile), pick how to pay for LLM calls:
   **WordPress AI Client:** if WordPress has a site-level AI provider configured, choose WordPress AI Client and Djinn will use that provider.
   **Your own key:** choose a provider and paste your API key (or define `DJINN_API_KEY` in `wp-config.php`).
   **Managed proxy:** choose *Djinn key* — your site links to the hosted gateway automatically; add prepaid credit via Polar to start. If your site isn't publicly reachable, paste an account token instead.
3. Open **Djinn → Lamp** and make a wish.

== Frequently Asked Questions ==

= Does Djinn require an account or API key? =

Either, your choice. Use WordPress AI Client when your site has a provider configured, bring your own provider key (OpenAI, Google Gemini, or Anthropic) with no account, or use the managed Djinn proxy — your site registers automatically and you add prepaid credit via Polar to start wishing.

= Can Djinn change my site without asking? =

No. Reads run immediately, but every write (create/update/delete, plugin/theme/core actions) pauses and shows you the exact operation to approve or refuse first. Actions are also limited to your user's capabilities.

= What data leaves my site? =

See *External services* above — only what a given wish requires, and only when you make a wish.

= Where is the source code? =

Djinn is developed in the open at https://github.com/mister-teddy/Djinn — including the un-minified JavaScript/TypeScript behind the compiled assets, and the build steps to reproduce them.

== Screenshots ==

1. "List my five newest posts" — Djinn reads your content and reports back.
2. "Import this URL into a new draft post" — Djinn fetches the page and drafts content.
3. "Create an About page" — a content write pauses for approval first.
4. "Reply to this comment" — comment actions use your WordPress capabilities.
5. "Write and publish a welcome post for the site" — content drafted and published on request.

== Changelog ==

= 0.7.9 =
* Added WordPress AI Client as a provider option when the site has a compatible text model with function calling configured.
* Hardened temporary uploads and exports with opaque storage names and removed generated PHP guard files.
* Fixed the Cave of Wonders blank page after the plugin display-name change.

= 0.7.8 =
* Renamed the WordPress-facing plugin display name to Djinn Admin AI Assistant.

= 0.7.7 =
* Improved Lamp tool-result layout so useful result links stay visible when collapsed without interrupting expanded operation details.
* Added right-click message actions for copying messages, links, operations, responses, and deleting individual conversation entries.
* Added a Djinn Pro link fallback and refreshed model-selection handling for retired provider models.

= 0.7.6 =
* Restored release PDF generation by using a fixed Pandoc Ubuntu image with the required TeX packages.
* Made documentation generation a required release step so incomplete releases are not published silently.

= 0.7.5 =
* Split paid capabilities into a separate Pro add-on so the WordPress.org package contains only fully available base functionality.
* Hardened uploads, widget writes, user-role assignment, prompt templates, and external-service disclosures for plugin review.

= 0.7.2 =
* Broader compatibility: now runs on PHP 7.4+ and WordPress 5.9+.

= 0.7.1 =
* Corrected the plugin header URIs for the plugin directory.

= 0.7.0 =
* New: import a web page's content into a post — say "import <url> into a new post" and Djinn fetches, cleans, and drafts it. Available in Free.
* Polar top-ups now open in an in-admin modal, bundled with the plugin.
* Switching to the managed Djinn proxy links the site as soon as you save.
* Fixed the gold/ivory highlight opacity across the admin UI.
* Hardened for the WordPress.org directory: escaped output, sanitized uploads, locally bundled fonts, all assets served from the plugin.

= 0.6.0 =
* Base/Pro split: the base plugin writes content (posts, pages, media, taxonomies, comments); the separate Pro add-on adds the full schema scope and the REST escape hatch.
* Every model option — your own key or the managed proxy — works with the base plugin; the proxy is prepaid via Polar (the free-wishes trial is retired).

= 0.5.2 =
* Site-bound onboarding via the hosted gateway; usage metering; capability-gated, approval-gated actions.
