# Djinn Proxy

A small, **OpenAI-compatible** LLM gateway for the plugin's **ORG edition**. It lets the free
WordPress.org plugin work without the user supplying an API key: the plugin talks to this service
with its per-site token, and we forward to our upstream provider, metering spend.

> Not shipped in the plugin. `make dist` excludes this folder; it deploys separately to your own
> infrastructure. It *is* included in the generated `make docs` PDF.

## Why OpenAI-compatible

The plugin's ORG provider is just the existing OpenAI adapter pointed at this base URL, with the
site token as its bearer. So the proxy speaks the OpenAI `/v1/chat/completions` and `/v1/embeddings`
shapes, and the plugin parses responses exactly as it would a direct call — no second mapping to
maintain. Point `UPSTREAM_BASE` at OpenAI or at Google's OpenAI-compatible Gemini endpoint.

## What it does

1. **Authenticates** the site by its bearer token.
2. **Checks the trial / credit** — see below; refuses with `402` when exhausted.
3. **Forwards** the call upstream using *our* key, **overriding the model** (we choose it, so we
   control cost) and **capping `max_tokens`** (`MAX_OUTPUT_TOKENS`).
4. **Meters** from the response's `usage`, charges the account, and passes the response back.

## Free trial & not going bankrupt

A djinn grants **three wishes** — so a new account gets `FREE_TRIAL_WISHES` (3) free wishes. Two
independent ceilings make a single wish unable to drain us:

- **Wish count** — the plugin sets `X-Djinn-New-Wish: 1` on the first call of each wish; the proxy
  decrements `wishesLeft`. At 0 → `402`.
- **Dollar ceiling** — the trial also carries a hard `FREE_TRIAL_USD` (default $0.05) balance,
  decremented per call. Even a pathological multi-round wish can't exceed it.
- **Per-call cap** — `MAX_OUTPUT_TOKENS` bounds any single response.

At ~$0.0009 per typical wish, three wishes cost us well under a cent; the $0.05 ceiling is pure
safety headroom.

## Onboarding (low friction, low abuse)

The intended sign-up — easy enough that most users breeze through it, restrictive enough to stop
free-credit farming:

1. **Email + verify** (one click) → issues a per-site token. → 3 free wishes.
2. To continue past the trial, **add a card** (Stripe SetupIntent — no upfront charge). That flips
   the account to `payg` (metered pay-as-you-go); trial limits no longer apply.

A card on file means we only extend ongoing usage to people who *could* pay, which is the real
abuse deterrent — no heavy KYC needed.

> Both plugin editions have the **same full capabilities** (including system/install/core ops).
> The only difference: the free ORG edition always routes through this proxy and can't enter its
> own API key; BYO can.

## Run locally

```bash
cp .env.example .env      # set UPSTREAM_KEY
npm install
npm start                 # :8787
# grant a site some credit (scaffold admin endpoint):
curl -X POST localhost:8787/admin/credit -H "x-admin-token: $ADMIN_TOKEN" \
  -H 'content-type: application/json' -d '{"token":"site-abc","addUsd":1,"payg":false}'
```

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| POST | `/v1/chat/completions` | Metered chat (OpenAI shape). |
| POST | `/v1/embeddings` | Metered embeddings. |
| GET | `/v1/account` | Remaining credit + spend (the plugin's Spend page reads this). |
| POST | `/admin/credit` | Create/top-up an account (replace with Stripe webhooks). |

## Before production (scaffold TODOs)

- Replace the JSON `accounts.json` store with a real database.
- Issue site tokens on sign-up; bind each to a Stripe customer.
- Drive credit from **Stripe** (one-off top-ups and/or pay-as-you-go metered billing) instead of
  the `/admin/credit` endpoint.
- Add per-account rate limits and an abuse/spend ceiling.
