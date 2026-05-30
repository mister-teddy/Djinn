=== Djinn ===
Contributors: misterteddy
Tags: ai, assistant, automation, content, gemini
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.5.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Whisper a wish in plain language and the Djinn grants it — an admin AI assistant that acts on your site by generating GraphQL on the fly.

== Description ==

Djinn is an AI assistant for the WordPress admin. Speak plainly — *"create a draft page titled About"*, *"list my five newest posts"*, *"make all headings dark red"* — and Djinn fulfils it by generating GraphQL against an in-house schema of your site. There are no hand-written per-feature tools: Djinn searches your site's schema, composes the operation, and runs it. **Reads run immediately; any change pauses for your one-click approval, showing the exact operation first.**

Every action is capability-gated — Djinn can never exceed the logged-in admin's real rights.

What you can wish for: posts & pages, taxonomies, comments, users, media, appearance & the site editor, options, and system management (plugins, themes, core) — all gated by your capabilities and the approval step.

= Free vs. bring-your-own-key =

* **Free edition** — no API key needed. New sites get **three free wishes**; to keep going, add a card (prepaid, auto top-up). Wishes are routed through Djinn's hosted gateway (see *External services*).
* **Bring-your-own-key** — use your own OpenAI, Google Gemini, or Anthropic key; calls go directly from your server to that provider.

== External services ==

To fulfil a wish, Djinn sends data to a large language model. This happens **only when you make a wish** — Djinn does not phone home in the background.

**What is sent (per wish):** your typed request; recent messages in the same chat (context); small GraphQL **schema fragments** (type/field names) selected for the wish; and the **results of read queries** Djinn runs to fulfil it (which may include site content). Djinn does **not** send your password, stored API key, or database wholesale.

**Where it goes:**

* **Free edition** — to **Djinn's hosted gateway** at https://djinn-proxy-351601184057.asia-northeast1.run.app , which forwards the request to **Google Gemini** and meters usage. Operated by Nguyễn Hồng Phát. Terms: https://djinn-proxy-351601184057.asia-northeast1.run.app/terms — Privacy: https://djinn-proxy-351601184057.asia-northeast1.run.app/privacy
* **Bring-your-own-key edition** — directly from your server to the provider you configure (**OpenAI**, **Google Gemini**, or **Anthropic**) using your own key.

**Provider policies:** Google Gemini https://ai.google.dev/terms · OpenAI https://openai.com/policies/ · Anthropic https://www.anthropic.com/legal/consumer-terms

The hosted gateway retains only usage metadata (token counts, model, timestamps, your account) to meter and bill — not your prompt or response bodies. Payment, if you add a card, is handled by Stripe.

== Installation ==

1. Install and activate the plugin.
2. **Free edition:** open **Djinn** in the admin — your site registers automatically and you can start wishing (3 free wishes). If your site isn't publicly reachable, paste an account token under **Djinn → Settings**.
   **BYO edition:** under **Djinn → Settings**, choose a provider and paste your API key (or define `DJINN_API_KEY` in `wp-config.php`).
3. Open **Djinn → Memory** and build the schema index, then visit **Djinn → Lamp** and make a wish.

== Frequently Asked Questions ==

= Does Djinn require an account or API key? =

The free edition needs neither — your site is registered automatically and gets three free wishes. To continue past the trial, add a card. The bring-your-own-key edition uses your own provider key with no account.

= Can Djinn change my site without asking? =

No. Reads run immediately, but every write (create/update/delete, plugin/theme/core actions) pauses and shows you the exact operation to approve or refuse first. Actions are also limited to your user's capabilities.

= What data leaves my site? =

See *External services* above — only what a given wish requires, and only when you make a wish.

== Changelog ==

= 0.5.2 =
* Site-bound free-edition onboarding via the hosted gateway; usage metering; capability-gated, approval-gated actions.
