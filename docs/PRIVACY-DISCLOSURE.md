# Privacy & external services

Djinn is an AI assistant: to grant a wish it must send parts of your request to a large language
model (LLM). This page is the plain-language disclosure of what leaves your site, where it goes,
and how to control it. It doubles as the **"External services"** section required by the
WordPress.org plugin guidelines — drop it into `readme.txt` and surface a short form of it in the
plugin before the first wish.

## What is sent, and when

Nothing is sent until you make a wish. When you do, Djinn transmits, for that request:

- **Your wish** — the message you type.
- **Conversation context** — earlier messages in the same chat, so the assistant has continuity.
- **The site's schema** — your site's GraphQL schema (type names and fields). This is structure,
  not your content.
- **Tool results** — the data returned by read queries the assistant runs to fulfil the wish
  (e.g. a list of post titles). Depending on your wish, this can include site content.

Djinn does **not** send your WordPress password, the plugin's stored API key, or your database
wholesale. It sends only what a given wish requires.

## Where it goes

**Free (ORG) edition** — requests go to **Djinn's hosted proxy** at
**https://djinn-proxy-351601184057.asia-northeast1.run.app**, which forwards them to our LLM
provider (**Google Gemini**) using our key, and meters usage. The proxy is operated by
**Nguyễn Hồng Phát** (an individual); see the
[Terms](https://djinn-proxy-351601184057.asia-northeast1.run.app/terms) and
[Privacy Policy](https://djinn-proxy-351601184057.asia-northeast1.run.app/privacy).

**Bring-your-own-key (BYO) edition** — requests go **directly from your server** to the provider
you configure (**OpenAI**, **Google Gemini**, or **Anthropic**) using *your* API key. (BYO may
optionally use the Djinn proxy instead.)

In all cases your data is also subject to the chosen provider's terms:

- Google Gemini — https://ai.google.dev/terms
- OpenAI (BYO) — https://openai.com/policies/ (API data is not used to train their models by default).
- Anthropic (BYO) — https://www.anthropic.com/legal/consumer-terms

## Retention

- **Locally:** your conversations and per-call usage totals are stored in your
  own WordPress database (tables prefixed `djinn_`). You control them; deleting a chat or
  uninstalling removes them.
- **Proxy (ORG):** we retain only the **usage metadata** needed to meter and bill — token counts,
  model, timestamps, and your account — tied to your per-site token. We do **not** retain your
  prompt or response bodies.
- **Providers:** per their policies above (typically a short abuse-monitoring window).

## Consent & control

- Djinn only contacts an external service **when you make a wish** — it does not phone home in the
  background.
- On the free edition the plugin shows a data-use notice in the admin, linking this disclosure.
- To stop all external calls, don't make wishes (or deactivate the plugin). On BYO you can also
  remove your API key.

## Summary for `readme.txt`

> Djinn sends your typed requests, recent chat context, the site's GraphQL schema, and the
> results of any read queries it runs to an LLM in order to fulfil your request. Free edition: via
> Djinn's hosted proxy (https://djinn-proxy-351601184057.asia-northeast1.run.app — terms: /terms,
> privacy: /privacy) to Google Gemini. BYO edition: directly to the provider you configure (OpenAI,
> Google Gemini, or Anthropic) with your own key. Data is sent only when you make a wish. Provider
> policies: Google https://ai.google.dev/terms , OpenAI https://openai.com/policies/ , Anthropic
> https://www.anthropic.com/legal/consumer-terms .
