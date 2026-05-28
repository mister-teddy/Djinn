# Privacy & external services

Djinn is an AI assistant: to grant a wish it must send parts of your request to a large language
model (LLM). This page is the plain-language disclosure of what leaves your site, where it goes,
and how to control it. It doubles as the **"External services"** section required by the
WordPress.org plugin guidelines — drop it into `readme.txt` and surface a short form of it in the
plugin before the first wish.

> Replace the **[bracketed]** placeholders (company name, URLs) before publishing.

## What is sent, and when

Nothing is sent until you make a wish. When you do, Djinn transmits, for that request:

- **Your wish** — the message you type.
- **Conversation context** — earlier messages in the same chat, so the assistant has continuity.
- **Relevant schema fragments** — small slices of your site's GraphQL schema (type names and
  fields) selected for the wish. This is structure, not your content.
- **Tool results** — the data returned by read queries the assistant runs to fulfil the wish
  (e.g. a list of post titles). Depending on your wish, this can include site content.

Djinn does **not** send your WordPress password, the plugin's stored API key, or your database
wholesale. It sends only what a given wish requires.

## Where it goes

**Free (ORG) edition** — requests go to **Djinn's hosted proxy** at **[proxy URL]**, which forwards
them to our LLM provider (**[OpenAI and/or Google Gemini]**) using our key, and meters usage. The
proxy is operated by **[company]**; see **[Djinn Terms]** and **[Djinn Privacy Policy]**.

**Bring-your-own-key (BYO) edition** — requests go **directly from your server** to the provider
you configure (**OpenAI** or **Google Gemini**) using *your* API key. (BYO may optionally use the
Djinn proxy instead.)

In all cases your data is also subject to the chosen provider's terms:

- OpenAI — https://openai.com/policies/ (API data is not used to train their models by default).
- Google Gemini — https://ai.google.dev/terms

## Retention

- **Locally:** your conversations, the schema index, and per-call usage totals are stored in your
  own WordPress database (tables prefixed `djinn_`). You control them; deleting a chat or
  uninstalling removes them.
- **Proxy (ORG):** we retain only what's needed to meter and bill usage (token counts, timestamps,
  your account). We do **[not retain prompt/response bodies beyond what's needed to deliver the
  request / retain them for N days for abuse prevention — pick one and state it truthfully]**.
- **Providers:** per their policies above (typically a short abuse-monitoring window).

## Consent & control

- Djinn only contacts an external service **when you make a wish** — it does not phone home in the
  background.
- Before your first wish, the plugin shows this disclosure and asks you to proceed.
- To stop all external calls, don't make wishes (or deactivate the plugin). On BYO you can also
  remove your API key.

## Summary for `readme.txt`

> Djinn sends your typed requests, recent chat context, selected GraphQL schema fragments, and the
> results of any read queries it runs to an LLM in order to fulfil your request. Free edition: via
> Djinn's hosted proxy ([proxy URL], terms: [Djinn Terms], privacy: [Djinn Privacy Policy]) to
> [OpenAI/Google]. BYO edition: directly to the provider you configure with your own key. Data is
> sent only when you make a wish. See each provider's policy: OpenAI
> https://openai.com/policies/ , Google https://ai.google.dev/terms .
