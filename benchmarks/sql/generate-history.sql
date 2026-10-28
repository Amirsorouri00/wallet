\set ON_ERROR_STOP on

\if :{?wallet_count}
\else
\set wallet_count 10
\endif

\if :{?states_per_wallet}
\else
\set states_per_wallet 100
\endif

BEGIN;

CREATE TEMP TABLE perf_parameters ON COMMIT DROP AS
SELECT :'wallet_count'::integer AS wallet_count,
       :'states_per_wallet'::integer AS states_per_wallet;

DO $$
DECLARE
    parameters record;
BEGIN
    SELECT * INTO parameters FROM perf_parameters;

    IF parameters.wallet_count < 1 OR parameters.wallet_count > 100000 THEN
        RAISE EXCEPTION 'wallet_count must be between 1 and 100000';
    END IF;

    IF parameters.states_per_wallet < 1 OR parameters.states_per_wallet > 1000000 THEN
        RAISE EXCEPTION 'states_per_wallet must be between 1 and 1000000';
    END IF;

    IF EXISTS (SELECT 1 FROM wallets WHERE owner_id LIKE 'perf-user-%') THEN
        RAISE EXCEPTION 'perf-history data already exists; use a freshly migrated disposable database';
    END IF;
END
$$;

INSERT INTO wallets (id, owner_type, owner_id, allows_negative)
SELECT ('10000000-0000-7000-8000-' || lpad(to_hex(wallet_number), 12, '0'))::uuid,
       'user',
       'perf-user-' || wallet_number,
       false
FROM perf_parameters,
     generate_series(1, wallet_count) AS wallet_number;

INSERT INTO wallet_balance_states (
    wallet_id, allows_negative, version, previous_state_id, previous_version,
    ledger_entry_id, delta_minor, balance_before_minor, balance_after_minor
)
SELECT id, false, 0, null, null, null, 0, 0, 0
FROM wallets
WHERE owner_type = 'user' AND owner_id LIKE 'perf-user-%';

CREATE TEMP TABLE perf_events ON COMMIT DROP AS
WITH generated AS (
    SELECT wallet_number,
           state_number,
           ((wallet_number - 1) * parameters.states_per_wallet + state_number)::bigint AS event_number,
           ((wallet_number - 1) % 64)::integer AS bucket
    FROM perf_parameters AS parameters,
         generate_series(1, parameters.wallet_count) AS wallet_number,
         generate_series(1, parameters.states_per_wallet) AS state_number
)
SELECT generated.*,
       row_number() OVER (PARTITION BY bucket ORDER BY event_number)::bigint AS bucket_sequence,
       lag(event_number) OVER (PARTITION BY bucket ORDER BY event_number)::bigint AS previous_bucket_event_number
FROM generated;

CREATE INDEX perf_events_event_number_index ON perf_events (event_number);

INSERT INTO ledger_transactions (id, type, reference_type, reference_id)
SELECT ('20000000-0000-7000-8000-' || lpad(to_hex(event_number), 12, '0'))::uuid,
       'deposit',
       'deposit',
       ('30000000-0000-7000-8000-' || lpad(to_hex(event_number), 12, '0'))::uuid
FROM perf_events;

CREATE TEMP TABLE perf_identity_bases ON COMMIT DROP AS
SELECT COALESCE((SELECT max(id) FROM ledger_entries), 0)::bigint AS entry_base,
       COALESCE((SELECT max(id) FROM wallet_balance_states), 0)::bigint AS state_base;

INSERT INTO ledger_entries (id, ledger_transaction_id, wallet_id, amount_minor)
SELECT identity.entry_base + event.event_number * 2 - 1,
       ('20000000-0000-7000-8000-' || lpad(to_hex(event.event_number), 12, '0'))::uuid,
       ('10000000-0000-7000-8000-' || lpad(to_hex(event.wallet_number), 12, '0'))::uuid,
       1
FROM perf_events AS event CROSS JOIN perf_identity_bases AS identity
UNION ALL
SELECT identity.entry_base + event.event_number * 2,
       ('20000000-0000-7000-8000-' || lpad(to_hex(event.event_number), 12, '0'))::uuid,
       ('018f0000-0000-7000-8000-' || lpad(to_hex(4096 + event.bucket), 12, '0'))::uuid,
       -1
FROM perf_events AS event CROSS JOIN perf_identity_bases AS identity;

INSERT INTO wallet_balance_states (
    id, wallet_id, allows_negative, version, previous_state_id, previous_version,
    ledger_entry_id, delta_minor, balance_before_minor, balance_after_minor
)
SELECT identity.state_base + event.event_number * 2 - 1,
       user_wallet.id,
       false,
       event.state_number,
       CASE WHEN event.state_number = 1
            THEN opening.id
            ELSE identity.state_base + (event.event_number - 1) * 2 - 1 END,
       event.state_number - 1,
       identity.entry_base + event.event_number * 2 - 1,
       1,
       event.state_number - 1,
       event.state_number
FROM perf_events AS event
CROSS JOIN perf_identity_bases AS identity
JOIN wallets AS user_wallet
  ON user_wallet.id = ('10000000-0000-7000-8000-' || lpad(to_hex(event.wallet_number), 12, '0'))::uuid
JOIN wallet_balance_states AS opening
  ON opening.wallet_id = user_wallet.id AND opening.version = 0
UNION ALL
SELECT identity.state_base + event.event_number * 2,
       clearing_wallet.id,
       true,
       event.bucket_sequence,
       CASE WHEN event.bucket_sequence = 1
            THEN opening.id
            ELSE identity.state_base + event.previous_bucket_event_number * 2 END,
       event.bucket_sequence - 1,
       identity.entry_base + event.event_number * 2,
       -1,
       -(event.bucket_sequence - 1),
       -event.bucket_sequence
FROM perf_events AS event
CROSS JOIN perf_identity_bases AS identity
JOIN wallets AS clearing_wallet
  ON clearing_wallet.id = ('018f0000-0000-7000-8000-' || lpad(to_hex(4096 + event.bucket), 12, '0'))::uuid
JOIN wallet_balance_states AS opening
  ON opening.wallet_id = clearing_wallet.id AND opening.version = 0;

SELECT setval(pg_get_serial_sequence('ledger_entries', 'id'), (SELECT max(id) FROM ledger_entries), true);
SELECT setval(pg_get_serial_sequence('wallet_balance_states', 'id'), (SELECT max(id) FROM wallet_balance_states), true);

ANALYZE ledger_transactions;
ANALYZE ledger_entries;
ANALYZE wallet_balance_states;
ANALYZE outbox_messages;

COMMIT;

\echo Generated :wallet_count user wallets with :states_per_wallet ledger-backed states each.
