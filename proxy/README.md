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

1. **Email + verify** (one click) → your sign-up service calls `/admin/provision` to mint a
   per-site token with 3 free wishes.
2. To continue past the trial, **add a card** (Stripe SetupIntent — no upfront charge, set as the
   customer's default payment method). The proxy then **auto-recharges** prepaid credit: when the
   balance drops below the threshold, it charges a top-up off-session. You never deliver unpaid
   usage, so a user can't run up a bill and vanish.

A card on file means we only extend ongoing usage to people who *could* pay — the real abuse
deterrent, no heavy KYC.

> Both plugin editions have the **same full capabilities** (including system/install/core ops).
> The only difference: the free ORG edition always routes through this proxy and can't enter its
> own API key; BYO can.

## Stack

**Rust (axum) + Postgres (Supabase) via `sqlx`**, deployed on **Google Cloud Run**. Small static
binary, fast cold starts, scales to zero. `rustls` (no OpenSSL) keeps the image minimal.

## Run locally

```bash
cp .env.example .env       # set DATABASE_URL (Supabase) + UPSTREAM_KEY
cargo run                  # :8080 — creates the accounts table on startup
# mint a trial token:
curl -X POST localhost:8080/admin/provision -H "x-admin-token: $ADMIN_TOKEN" \
  -H 'content-type: application/json' -d '{"token":"site-abc"}'
```

## Deploy (Cloud Run)

```bash
gcloud run deploy djinn-proxy --source . --allow-unauthenticated \
  --set-env-vars "DATABASE_URL=…,UPSTREAM_KEY=…,STRIPE_SECRET_KEY=…,STRIPE_WEBHOOK_SECRET=…,ADMIN_TOKEN=…"
```

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| POST | `/v1/chat/completions` | Metered chat (OpenAI shape). |
| POST | `/v1/embeddings` | Metered embeddings. |
| GET | `/v1/account` | Remaining credit, spend, free wishes (the plugin's Spend page reads this). |
| POST | `/admin/provision` | Mint a trial token (sign-up service; `x-admin-token`). |
| POST | `/stripe/webhook` | Credit the balance on `payment_intent.succeeded` (durable source of truth). |

## Before production (TODOs)

- Use **integer cents** for money instead of `f64`.
- Build the sign-up service (email verify → `/admin/provision`; card via Stripe SetupIntent → set
  default payment method) and a billing/top-up page.
- Add per-account rate limits and an upstream-failure circuit breaker.
