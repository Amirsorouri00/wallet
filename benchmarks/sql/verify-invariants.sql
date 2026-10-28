\set ON_ERROR_STOP on

\if :{?max_outbox_lag_seconds}
\else
\set max_outbox_lag_seconds 60
\endif

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM wallet_balance_states AS state
        JOIN wallets AS wallet ON wallet.id = state.wallet_id
        WHERE wallet.owner_type = 'user' AND state.balance_after_minor < 0
    ) THEN
        RAISE EXCEPTION 'negative user wallet state detected';
    END IF;

    IF EXISTS (
        SELECT previous_state_id
        FROM wallet_balance_states
        WHERE previous_state_id IS NOT NULL
        GROUP BY previous_state_id
        HAVING count(*) > 1
    ) THEN
        RAISE EXCEPTION 'wallet state branch detected';
    END IF;
END
$$;

SELECT count(*) AS negative_user_state_count
FROM wallet_balance_states AS state
JOIN wallets AS wallet ON wallet.id = state.wallet_id
WHERE wallet.owner_type = 'user' AND state.balance_after_minor < 0;

SELECT count(*) AS branched_predecessor_count
FROM (
    SELECT previous_state_id
    FROM wallet_balance_states
    WHERE previous_state_id IS NOT NULL
    GROUP BY previous_state_id
    HAVING count(*) > 1
) AS branches;

SELECT COALESCE(
    EXTRACT(EPOCH FROM (
        CURRENT_TIMESTAMP - min(occurred_at) FILTER (
            WHERE published_at IS NULL
              AND dead_lettered_at IS NULL
              AND available_at <= CURRENT_TIMESTAMP
              AND (leased_until IS NULL OR leased_until <= CURRENT_TIMESTAMP)
        )
    )),
    0
)::numeric(20, 3) AS maximum_due_outbox_lag_seconds
FROM outbox_messages
\gset

SELECT (:maximum_due_outbox_lag_seconds::numeric > :'max_outbox_lag_seconds'::numeric)::integer AS outbox_lag_exceeded
\gset

\if :outbox_lag_exceeded
\echo Due outbox lag exceeds :max_outbox_lag_seconds seconds.
\quit 1
\endif

\echo Maximum due outbox lag: :maximum_due_outbox_lag_seconds seconds.
