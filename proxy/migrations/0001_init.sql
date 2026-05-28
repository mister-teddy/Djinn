-- Account ledger for the Djinn proxy. The app also runs this (idempotently) on startup, so you
-- only need to apply it manually if you prefer migration-driven schema management.
--
-- Money is DOUBLE PRECISION here for brevity; a production build should use integer cents.

CREATE TABLE IF NOT EXISTS accounts (
    token                   TEXT PRIMARY KEY,
    stripe_customer_id      TEXT,
    balance_usd             DOUBLE PRECISION NOT NULL DEFAULT 0,
    spent_usd               DOUBLE PRECISION NOT NULL DEFAULT 0,
    wishes_left             INT NOT NULL DEFAULT 3,
    auto_recharge           BOOLEAN NOT NULL DEFAULT FALSE,
    recharge_threshold_usd  DOUBLE PRECISION NOT NULL DEFAULT 2,
    recharge_amount_usd     DOUBLE PRECISION NOT NULL DEFAULT 10,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Example: provision a trial account (your sign-up flow does this after email verification).
-- INSERT INTO accounts (token, balance_usd, wishes_left) VALUES ('site-abc', 0.05, 3);
