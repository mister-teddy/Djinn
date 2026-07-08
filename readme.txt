=== Djinn ===
Contributors: misterteddy
Tags: ai, assistant, automation, content, gemini
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Whisper a wish in plain language and the Djinn grants it — an admin AI assistant that acts on your site by generating GraphQL on the fly.

== Description ==

Djinn is an AI assistant for the WordPress admin. Speak plainly — *"create a draft page titled About"*, *"list my five newest posts"*, *"make all headings dark red"* — and Djinn fulfils it by generating GraphQL against an in-house schema of your site. There are no hand-written per-feature tools: Djinn searches your site's schema, composes the operation, and runs it. **Reads run immediately; any change pauses for your one-click approval, showing the exact operation first.**

Every action is capability-gated — Djinn can never exceed the logged-in admin's real rights.

What you can wish for: Djinn reads anything on your site, and the free plugin writes content — posts & pages, media, categories & tags, comments — all gated by your capabilities and the approval step. **Djinn Pro** unlocks the full schema: users, settings, appearance & the site editor, options, system management (plugins, themes, core), WooCommerce, and a universal REST escape hatch.

= Free vs. Pro =

The two editions differ only in **scope** — every model option works in both.

* **Free** — read anything; write content (posts, pages, media, categories, comments). Use your own OpenAI, Google Gemini, or Anthropic key, or the managed Djinn proxy (no key to paste; prepaid credit handled by Polar).
* **Pro** — unlocks the full schema scope above plus the REST escape hatch. A separate download, unlocked by a license key bought through Polar. Same model options as Free.

= How LLM calls reach a provider =

* **Your own key** — calls go directly from your server to the provider you configure (OpenAI, Google Gemini, or Anthropic).
* **Managed proxy** — no key needed; wishes route through Djinn's hosted gateway, which meters usage and bills prepaid credit (see *External services*).

== External services ==

To fulfil a wish, Djinn sends data to a large language model. This happens **only when you make a wish** — Djinn does not phone home in the background.

**What is sent (per wish):** your typed request; recent messages in the same chat (context); the site's GraphQL **schema** (type/field names); and the **results of read queries** Djinn runs to fulfil it (which may include site content). Djinn does **not** send your password, stored API key, or database wholesale.

**Where it goes:**

* **Managed proxy** — to **Djinn's hosted gateway** at https://djinn-proxy-351601184057.asia-northeast1.run.app , which forwards the request to **Google Gemini** and meters usage. Operated by Nguyễn Hồng Phát. Terms: https://djinn-proxy-351601184057.asia-northeast1.run.app/terms — Privacy: https://djinn-proxy-351601184057.asia-northeast1.run.app/privacy
* **Your own key** — directly from your server to the provider you configure (**OpenAI**, **Google Gemini**, or **Anthropic**) using your own key.

**Provider policies:** Google Gemini https://ai.google.dev/terms · OpenAI https://openai.com/policies/ · Anthropic https://www.anthropic.com/legal/consumer-terms

The hosted gateway retains only usage metadata (token counts, model, timestamps, your account) to meter and bill — not your prompt or response bodies. Top-ups, if you add credit, are handled by Polar (the merchant of record).

== Installation ==

1. Install and activate the plugin.
2. In **Djinn → Cave of Wonders** (Account tile), pick how to pay for LLM calls:
   **Your own key:** choose a provider and paste your API key (or define `DJINN_API_KEY` in `wp-config.php`).
   **Managed proxy:** choose *Djinn key* — your site links to the hosted gateway automatically; add prepaid credit via Polar to start. If your site isn't publicly reachable, paste an account token instead.
3. Open **Djinn → Lamp** and make a wish.

== Frequently Asked Questions ==

= Does Djinn require an account or API key? =

Either, your choice. Bring your own provider key (OpenAI, Google Gemini, or Anthropic) with no account, or use the managed Djinn proxy — your site registers automatically and you add prepaid credit via Polar to start wishing.

= Can Djinn change my site without asking? =

No. Reads run immediately, but every write (create/update/delete, plugin/theme/core actions) pauses and shows you the exact operation to approve or refuse first. Actions are also limited to your user's capabilities.

= What data leaves my site? =

See *External services* above — only what a given wish requires, and only when you make a wish.

= Where is the source code? =

Djinn is developed in the open at https://github.com/mister-teddy/Djinn — including the un-minified JavaScript/TypeScript behind the compiled assets, and the build steps to reproduce them.

== Screenshots ==

1. "Is my site healthy and up to date?" — Djinn reads your site and reports back.
2. "Add the product 'Aladdin's Brass Lamp'" — one wish creates a WooCommerce product.
3. "Make About my homepage, and blog at /news" — settings changed, shown for approval first.
4. "Install and activate Yoast SEO" — plugin management from a plain-language request.
5. "Write and publish a welcome post for the site" — content drafted and published on request.

== Changelog ==

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
* Free/Pro editions: Free writes content (posts, pages, media, taxonomies, comments); Pro unlocks the full schema scope and the REST escape hatch, via a Polar license key.
* Every model option — your own key or the managed proxy — works in both editions; the proxy is prepaid via Polar (the free-wishes trial is retired).

= 0.5.2 =
* Site-bound onboarding via the hosted gateway; usage metering; capability-gated, approval-gated actions.
