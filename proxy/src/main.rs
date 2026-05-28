//! Djinn proxy — a metered, OpenAI-compatible LLM gateway for the plugin's ORG edition.
//!
//! Flow: the ORG plugin sends OpenAI-shaped requests with its per-site token as the bearer. We
//! authenticate the site against Postgres (Supabase), enforce the free-wish / prepaid-credit
//! limits, forward to our upstream provider with *our* key (overriding the model and capping
//! output), meter the response, debit the balance, and auto-recharge from Stripe when low.
//!
//! Money is held as f64 USD here for brevity; a production build should use integer cents.
//! Compile-time DB checking is avoided (runtime sqlx queries) so this builds without a database.

use std::sync::Arc;

use axum::{
    extract::State,
    http::{HeaderMap, StatusCode},
    response::IntoResponse,
    routing::{get, post},
    Json, Router,
};
use hmac::{Hmac, Mac};
use serde_json::{json, Value};
use sha2::Sha256;
use sqlx::{postgres::PgPoolOptions, PgPool, Row};

struct Config {
    upstream_base: String,
    upstream_key: String,
    chat_model: String,
    embed_model: String,
    max_output_tokens: i64,
    free_trial_wishes: i32,
    free_trial_usd: f64,
    stripe_secret: String,
    stripe_webhook_secret: String,
    admin_token: String,
}

struct AppState {
    pool: PgPool,
    http: reqwest::Client,
    cfg: Config,
}

fn env_or(key: &str, default: &str) -> String {
    std::env::var(key).unwrap_or_else(|_| default.to_string())
}

#[tokio::main]
async fn main() {
    let cfg = Config {
        upstream_base: env_or("UPSTREAM_BASE", "https://api.openai.com/v1"),
        upstream_key: env_or("UPSTREAM_KEY", ""),
        chat_model: env_or("ORG_CHAT_MODEL", "gpt-4o-mini"),
        embed_model: env_or("ORG_EMBED_MODEL", "text-embedding-3-small"),
        max_output_tokens: env_or("MAX_OUTPUT_TOKENS", "2000").parse().unwrap_or(2000),
        free_trial_wishes: env_or("FREE_TRIAL_WISHES", "3").parse().unwrap_or(3),
        free_trial_usd: env_or("FREE_TRIAL_USD", "0.05").parse().unwrap_or(0.05),
        stripe_secret: env_or("STRIPE_SECRET_KEY", ""),
        stripe_webhook_secret: env_or("STRIPE_WEBHOOK_SECRET", ""),
        admin_token: env_or("ADMIN_TOKEN", ""),
    };

    let pool = PgPoolOptions::new()
        .max_connections(5)
        .connect(&env_or("DATABASE_URL", ""))
        .await
        .expect("connect to Postgres (DATABASE_URL)");

    sqlx::query(SCHEMA).execute(&pool).await.expect("init schema");

    let state = Arc::new(AppState { pool, http: reqwest::Client::new(), cfg });

    let app = Router::new()
        .route("/v1/chat/completions", post(chat_completions))
        .route("/v1/embeddings", post(embeddings))
        .route("/v1/account", get(account))
        .route("/admin/provision", post(provision))
        .route("/stripe/webhook", post(stripe_webhook))
        .route("/healthz", get(|| async { Json(json!({"ok": true})) }))
        .with_state(state);

    let port = env_or("PORT", "8080");
    let listener = tokio::net::TcpListener::bind(format!("0.0.0.0:{port}"))
        .await
        .expect("bind");
    println!("Djinn proxy listening on :{port}");
    axum::serve(listener, app).await.expect("serve");
}

const SCHEMA: &str = "
CREATE TABLE IF NOT EXISTS accounts (
    token TEXT PRIMARY KEY,
    stripe_customer_id TEXT,
    balance_usd DOUBLE PRECISION NOT NULL DEFAULT 0,
    spent_usd DOUBLE PRECISION NOT NULL DEFAULT 0,
    wishes_left INT NOT NULL DEFAULT 3,
    auto_recharge BOOLEAN NOT NULL DEFAULT FALSE,
    recharge_threshold_usd DOUBLE PRECISION NOT NULL DEFAULT 2,
    recharge_amount_usd DOUBLE PRECISION NOT NULL DEFAULT 10,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);";

struct Account {
    token: String,
    stripe_customer_id: Option<String>,
    balance_usd: f64,
    spent_usd: f64,
    wishes_left: i32,
    auto_recharge: bool,
    recharge_threshold_usd: f64,
    recharge_amount_usd: f64,
}

fn bearer(headers: &HeaderMap) -> Option<String> {
    headers
        .get("authorization")?
        .to_str()
        .ok()?
        .strip_prefix("Bearer ")
        .map(|s| s.to_string())
}

async fn load_account(pool: &PgPool, token: &str) -> Option<Account> {
    let row = sqlx::query(
        "SELECT token, stripe_customer_id, balance_usd, spent_usd, wishes_left, auto_recharge, \
         recharge_threshold_usd, recharge_amount_usd FROM accounts WHERE token = $1",
    )
    .bind(token)
    .fetch_optional(pool)
    .await
    .ok()??;

    Some(Account {
        token: row.get("token"),
        stripe_customer_id: row.get("stripe_customer_id"),
        balance_usd: row.get("balance_usd"),
        spent_usd: row.get("spent_usd"),
        wishes_left: row.get("wishes_left"),
        auto_recharge: row.get("auto_recharge"),
        recharge_threshold_usd: row.get("recharge_threshold_usd"),
        recharge_amount_usd: row.get("recharge_amount_usd"),
    })
}

fn err(code: StatusCode, msg: &str) -> (StatusCode, Json<Value>) {
    (code, Json(json!({ "error": { "message": msg } })))
}

// USD per 1,000,000 tokens — keep in sync with the upstream you point at.
fn cost(model: &str, input: i64, output: i64) -> f64 {
    let (i, o) = match model {
        "gpt-4o-mini" => (0.15, 0.60),
        "gpt-4o" => (2.50, 10.0),
        "text-embedding-3-small" => (0.02, 0.0),
        "gemini-2.5-flash-lite" => (0.10, 0.40),
        "gemini-embedding-001" => (0.15, 0.0),
        _ => (0.0, 0.0),
    };
    (input as f64 * i + output as f64 * o) / 1_000_000.0
}

/// Forward an OpenAI-shaped request upstream, meter it, and debit the account. `is_chat` controls
/// the model + output cap + the new-wish decrement.
async fn relay(
    st: &AppState,
    mut acct: Account,
    new_wish: bool,
    path: &str,
    mut body: Value,
    model: &str,
) -> (StatusCode, Json<Value>) {
    // Auto-recharge before serving if a card-backed account is low.
    if acct.auto_recharge && acct.balance_usd < acct.recharge_threshold_usd {
        recharge(st, &mut acct).await;
    }

    // Gate.
    if acct.auto_recharge {
        if acct.balance_usd <= 0.0 {
            return err(StatusCode::PAYMENT_REQUIRED, "Out of credit and auto top-up failed. Update your card.");
        }
    } else {
        if acct.wishes_left <= 0 {
            return err(StatusCode::PAYMENT_REQUIRED, "Your three free wishes are used up. Add a card to keep wishing.");
        }
        if acct.balance_usd <= 0.0 {
            return err(StatusCode::PAYMENT_REQUIRED, "Free trial credit used up. Add a card to keep wishing.");
        }
    }

    // We choose the model; cap output on chat.
    body["model"] = json!(model);
    if path == "/chat/completions" {
        let req = body.get("max_tokens").and_then(|v| v.as_i64()).unwrap_or(st.cfg.max_output_tokens);
        body["max_tokens"] = json!(req.min(st.cfg.max_output_tokens));
    }

    let resp = st
        .http
        .post(format!("{}{}", st.cfg.upstream_base, path))
        .bearer_auth(&st.cfg.upstream_key)
        .json(&body)
        .send()
        .await;

    let resp = match resp {
        Ok(r) => r,
        Err(e) => return err(StatusCode::BAD_GATEWAY, &format!("Upstream error: {e}")),
    };
    let status = StatusCode::from_u16(resp.status().as_u16()).unwrap_or(StatusCode::BAD_GATEWAY);
    let json: Value = resp.json().await.unwrap_or_else(|_| json!({}));

    if status.is_success() {
        // Count the wish (trial only) and debit the metered cost.
        if new_wish && !acct.auto_recharge {
            let _ = sqlx::query("UPDATE accounts SET wishes_left = GREATEST(0, wishes_left - 1) WHERE token = $1")
                .bind(&acct.token)
                .execute(&st.pool)
                .await;
        }
        if let Some(usage) = json.get("usage") {
            let pin = usage.get("prompt_tokens").and_then(|v| v.as_i64()).unwrap_or(0);
            let pout = usage.get("completion_tokens").and_then(|v| v.as_i64()).unwrap_or(0);
            let charge = cost(model, pin, pout);
            let _ = sqlx::query(
                "UPDATE accounts SET balance_usd = balance_usd - $1, spent_usd = spent_usd + $1 WHERE token = $2",
            )
            .bind(charge)
            .bind(&acct.token)
            .execute(&st.pool)
            .await;
        }
    }

    (status, Json(json))
}

/// Off-session Stripe top-up. Requires the customer to have a default payment method (set when the
/// card was added). On success we credit immediately; the webhook is the durable confirmation.
async fn recharge(st: &AppState, acct: &mut Account) {
    let (Some(customer), true) = (acct.stripe_customer_id.clone(), !st.cfg.stripe_secret.is_empty()) else {
        return;
    };
    let amount_cents = (acct.recharge_amount_usd * 100.0).round() as i64;
    let params = [
        ("amount", amount_cents.to_string()),
        ("currency", "usd".to_string()),
        ("customer", customer),
        ("off_session", "true".to_string()),
        ("confirm", "true".to_string()),
        ("description", "Djinn auto top-up".to_string()),
        ("metadata[token]", acct.token.clone()),
    ];
    let resp = st
        .http
        .post("https://api.stripe.com/v1/payment_intents")
        .bearer_auth(&st.cfg.stripe_secret)
        .form(&params)
        .send()
        .await;

    if let Ok(r) = resp {
        if let Ok(v) = r.json::<Value>().await {
            if v.get("status").and_then(|s| s.as_str()) == Some("succeeded") {
                acct.balance_usd += acct.recharge_amount_usd;
                let _ = sqlx::query("UPDATE accounts SET balance_usd = balance_usd + $1 WHERE token = $2")
                    .bind(acct.recharge_amount_usd)
                    .bind(&acct.token)
                    .execute(&st.pool)
                    .await;
            }
        }
    }
}

async fn chat_completions(
    State(st): State<Arc<AppState>>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> impl IntoResponse {
    let Some(token) = bearer(&headers) else {
        return err(StatusCode::UNAUTHORIZED, "Missing token.");
    };
    let Some(acct) = load_account(&st.pool, &token).await else {
        return err(StatusCode::UNAUTHORIZED, "Unknown Djinn token.");
    };
    let new_wish = headers.contains_key("x-djinn-new-wish");
    let model = st.cfg.chat_model.clone();
    relay(&st, acct, new_wish, "/chat/completions", body, &model).await
}

async fn embeddings(
    State(st): State<Arc<AppState>>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> impl IntoResponse {
    let Some(token) = bearer(&headers) else {
        return err(StatusCode::UNAUTHORIZED, "Missing token.");
    };
    let Some(acct) = load_account(&st.pool, &token).await else {
        return err(StatusCode::UNAUTHORIZED, "Unknown Djinn token.");
    };
    let model = st.cfg.embed_model.clone();
    relay(&st, acct, false, "/embeddings", body, &model).await
}

async fn account(State(st): State<Arc<AppState>>, headers: HeaderMap) -> impl IntoResponse {
    let Some(token) = bearer(&headers) else {
        return err(StatusCode::UNAUTHORIZED, "Missing token.");
    };
    let Some(acct) = load_account(&st.pool, &token).await else {
        return err(StatusCode::UNAUTHORIZED, "Unknown Djinn token.");
    };
    (
        StatusCode::OK,
        Json(json!({
            "balanceUsd": acct.balance_usd,
            "spentUsd": acct.spent_usd,
            "wishesLeft": acct.wishes_left,
            "payg": acct.auto_recharge,
        })),
    )
}

/// Mint a trial account (token + the free wishes / dollar ceiling). Your sign-up service calls
/// this after email verification. Protected by ADMIN_TOKEN. TODO: fold into the sign-up service.
async fn provision(
    State(st): State<Arc<AppState>>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> impl IntoResponse {
    if st.cfg.admin_token.is_empty()
        || headers.get("x-admin-token").and_then(|v| v.to_str().ok()) != Some(st.cfg.admin_token.as_str())
    {
        return err(StatusCode::FORBIDDEN, "Forbidden.");
    }
    let token = body.get("token").and_then(|v| v.as_str()).unwrap_or("");
    if token.is_empty() {
        return err(StatusCode::BAD_REQUEST, "token required");
    }
    let _ = sqlx::query(
        "INSERT INTO accounts (token, balance_usd, wishes_left) VALUES ($1, $2, $3) \
         ON CONFLICT (token) DO NOTHING",
    )
    .bind(token)
    .bind(st.cfg.free_trial_usd)
    .bind(st.cfg.free_trial_wishes)
    .execute(&st.pool)
    .await;
    (
        StatusCode::OK,
        Json(json!({ "token": token, "wishesLeft": st.cfg.free_trial_wishes, "balanceUsd": st.cfg.free_trial_usd })),
    )
}

/// Stripe webhook: verify the signature, then credit the account on a successful payment. This is
/// the durable source of truth for top-ups (the inline recharge is best-effort).
async fn stripe_webhook(
    State(st): State<Arc<AppState>>,
    headers: HeaderMap,
    body: String,
) -> impl IntoResponse {
    if !verify_stripe(&st.cfg.stripe_webhook_secret, &headers, &body) {
        return err(StatusCode::BAD_REQUEST, "Bad signature.");
    }
    let event: Value = serde_json::from_str(&body).unwrap_or_else(|_| json!({}));
    if event.get("type").and_then(|t| t.as_str()) == Some("payment_intent.succeeded") {
        let obj = &event["data"]["object"];
        let token = obj["metadata"]["token"].as_str().unwrap_or("");
        let received = obj["amount_received"].as_i64().unwrap_or(0);
        if !token.is_empty() && received > 0 {
            let credit = received as f64 / 100.0;
            let _ = sqlx::query(
                "UPDATE accounts SET balance_usd = balance_usd + $1, auto_recharge = TRUE WHERE token = $2",
            )
            .bind(credit)
            .bind(token)
            .execute(&st.pool)
            .await;
        }
    }
    (StatusCode::OK, Json(json!({ "received": true })))
}

fn verify_stripe(secret: &str, headers: &HeaderMap, body: &str) -> bool {
    if secret.is_empty() {
        return false;
    }
    let Some(sig_header) = headers.get("stripe-signature").and_then(|v| v.to_str().ok()) else {
        return false;
    };
    // Stripe-Signature: t=timestamp,v1=signature
    let mut ts = "";
    let mut v1 = "";
    for part in sig_header.split(',') {
        match part.split_once('=') {
            Some(("t", v)) => ts = v,
            Some(("v1", v)) => v1 = v,
            _ => {}
        }
    }
    if ts.is_empty() || v1.is_empty() {
        return false;
    }
    let mut mac = match Hmac::<Sha256>::new_from_slice(secret.as_bytes()) {
        Ok(m) => m,
        Err(_) => return false,
    };
    mac.update(format!("{ts}.{body}").as_bytes());
    let expected = hex::encode(mac.finalize().into_bytes());
    // constant-time-ish compare
    expected.as_bytes() == v1.as_bytes()
}
