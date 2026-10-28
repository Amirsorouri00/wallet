# ADR 0002: PostgreSQL transactions and deterministic lock ordering

- Status: Accepted
- Date: 2026-10-24

## Context

Concurrent transfers, approvals, and callbacks can race over the same wallets and lifecycle records. Application-level balance checks alone allow overspending when two requests read the same starting balance.

## Decision

Every financial command runs in one PostgreSQL transaction. The posting service requires an active transaction, locks all affected wallet rows with `SELECT ... FOR UPDATE`, and acquires those locks in ascending wallet UUID order before calculating new balances.

Lifecycle records such as deposits and withdrawals are also locked before terminal transitions. Laravel's bounded transaction retry handles retryable database concurrency failures.

## Consequences

- Conflicting debits serialize at the wallet boundary, so only one can consume a given balance.
- Opposing transfers acquire locks in the same order, reducing deadlock risk.
- A hot wallet is an intentional serialization point and therefore a throughput boundary.
- Callers must retain idempotency because a database retry cannot resolve an HTTP response lost after commit.
