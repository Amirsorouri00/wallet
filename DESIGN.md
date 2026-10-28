# Design

## Why this architecture

The difficult requirements are not CRUD; they are preserving money under retries, concurrent spending, partial failures, and asynchronous delivery. The architecture therefore keeps every financial invariant inside one PostgreSQL transaction and uses a modular Laravel application around that boundary.

This is deliberately a modular monolith rather than a set of services. Deposits, transfers, withdrawals, ledger posting, idempotency, and event recording need one atomic commit. Splitting them across services would introduce distributed transactions, sagas, and additional failure states without providing useful independence at this stage. The application and outbox workers are still stateless, so they can scale horizontally without splitting the accounting transaction.

The ledger is the write authority; the balance-state chain is a projection optimized for current balance and history reads. This combines double-entry auditability with practical API reads without adopting a general-purpose event-sourcing framework.

## Data model

All monetary values are signed 64-bit integer minor units. UUIDs identify externally meaningful aggregates; bigint identity columns order append-only ledger entries and balance states efficiently.

| Table | Purpose and important relationships | Invariants |
| --- | --- | --- |
| `wallets` | Identifies a unique `(owner_type, owner_id)` as either a user wallet or a named system wallet. It intentionally has no balance column. | User wallets cannot be negative; system wallets may be negative. Role and naming checks prevent using arbitrary wallets as clearing or suspense accounts. |
| `ledger_transactions` | Header for one accounting event, linked uniquely to its business reference such as a deposit, transfer, withdrawal approval, or compensation. | A business reference can produce at most one ledger transaction. Transaction and reference types are constrained. |
| `ledger_entries` | Signed lines belonging to a ledger transaction and wallet. A posting contains distinct wallets, non-zero values, and balances to zero. | Amount range, non-zero lines, unique wallet per transaction, immutable history, and restrictive foreign keys. |
| `wallet_balance_states` | Append-only wallet projection. Version 0 is the zero genesis state; each later state points to one predecessor and one ledger entry. | Unique wallet/version, unique predecessor, unique ledger entry, contiguous versions, exact arithmetic, matching entry delta, and nonnegative user balances. |
| `deposits` | Lifecycle for a gateway deposit from `PENDING` to `SUCCEEDED` or `FAILED`, linking a user wallet, pooled clearing wallet, gateway reference, and optional posting. | Unique gateway reference, one success posting, legal status-field combinations, positive amount, and immutable clearing identity. |
| `withdrawal_requests` | Lifecycle from `PENDING` to `APPROVED`, then `SETTLED` or `REJECTED`; links the user wallet, pooled suspense wallet, approval posting, and optional compensation. | Legal transition shapes, distinct wallet roles, immutable financial identity, positive amount, and unique approval/compensation postings. |
| `idempotency_records` | Durable command reservation and terminal response, scoped by actor wallet, operation, and key. | One scoped key, canonical SHA-256 request fingerprint, and consistent processing/succeeded/rejected response fields. |
| `outbox_messages` | Durable event recorded with the financial transaction and later leased by publishers. | JSON object payload, stable event ID, valid lease pairs, nonnegative attempts, and mutually exclusive published/dead-letter terminal states. |

The important relationship is:

```text
business lifecycle row
        │
        └── ledger_transaction ──< ledger_entries >── wallets
                                      │                  │
                                      └── wallet_balance_states

same database transaction:
idempotency_record + lifecycle + ledger + balance states + outbox_message
```

The latest `wallet_balance_states` row is fast to read, but it is not trusted independently. Its delta must match its ledger entry, its opening balance must match its predecessor's closing balance, and reconciliation can recompute balances from the ledger authority.

## Design decisions, alternatives, and trade-offs

### Double-entry ledger plus append-only balance states

**Decision:** Keep `ledger_transactions` and `ledger_entries` as the accounting authority and materialize every wallet balance change as a new `wallet_balance_states` row.

**Why:** A mutable `wallets.balance` is fast but loses the explanation for how the value was reached and is easy to corrupt through an overlooked update path. A pure ledger calculation is auditable but makes every balance read increasingly expensive. The selected model provides both a durable audit source and predictable reads.

**Trade-off:** Each posting writes more rows and carries chain constraints. Corrections require compensating transactions rather than edits, and historical tables need retention, partitioning, or archival planning as they grow.

### PostgreSQL as the consistency boundary

**Decision:** Commit the lifecycle transition, idempotency result, balanced posting, balance states, and outbox message in one PostgreSQL transaction.

**Why:** These records must agree or none of them should exist. A single ACID boundary makes rollback behavior explicit and testable. A cache, broker, or separate balance service in the write path would create dual-write failure cases.

**Trade-off:** The primary database absorbs all writes and becomes the main capacity and availability dependency. This favors correctness and operational simplicity over independent service scaling.

### Pessimistic locking with deterministic order

**Decision:** Lock affected wallet rows using `SELECT ... FOR UPDATE`, always in ascending UUID order, before reading balances and posting.

**Why:** Optimistic reads allow two concurrent debits to observe the same funds. Database row locks serialize only conflicting wallets, and consistent ordering reduces reverse-transfer deadlocks.

**Trade-off:** A hot wallet is intentionally serialized and can dominate latency. Lock timeouts and bounded retries provide backpressure rather than pretending unlimited concurrency is safe.

### Transactional, database-backed idempotency

**Decision:** Scope an idempotency key by actor wallet and operation, bind it to a canonical request hash, and persist the terminal result inside the financial transaction.

**Why:** Network retries cannot reveal whether the first request committed before its response was lost. In-memory locks, cache-only keys, or database uniqueness on business rows cannot reliably replay the exact committed response or deterministic rejection.

**Trade-off:** Idempotency records consume durable storage and need an eventual retention policy. Canonical request normalization becomes part of the compatibility contract.

### Transactional outbox with at-least-once delivery

**Decision:** Record events in `outbox_messages` during the financial transaction; publish later using leased batches and `FOR UPDATE SKIP LOCKED`.

**Why:** Publishing directly to a broker during the transaction is a dual write: either the database or broker can succeed alone. The outbox guarantees that every committed mutation has a publishable event and every rollback has none.

**Trade-off:** Delivery is at least once, not exactly once. Consumers must deduplicate stable event IDs, events are eventually rather than immediately delivered, and operators must monitor lag and dead letters.

### Withdrawal debit at approval

**Decision:** Creation records intent, approval performs the only debit into a suspense wallet, settlement changes lifecycle state without another debit, and post-approval rejection posts compensation.

**Why:** Debiting at settlement lets approved withdrawals lose a later race for funds. Reserving at request time would require a distinct hold ledger, expiration, release, and available-versus-book balance model. Approval is the smallest clear commitment point for this scope.

**Trade-off:** Funds remain spendable before approval. Compensation adds another ledger transaction and event, but preserves the audit history instead of rewriting it.

### Integer minor units and no currency dimension

**Decision:** Use bounded integer `amount_minor` values and omit currency from this version.

**Why:** Integers avoid floating-point rounding and keep balance arithmetic enforceable by database checks. Currency was not required and adding it correctly would require currency-scoped wallets or entries, precision rules, and explicit foreign-exchange postings.

**Trade-off:** A wallet cannot safely hold multiple currencies. Adding currency later is a schema and domain migration, not a label added to existing amounts.

### Modular monolith over microservices or full event sourcing

**Decision:** Keep use cases in one Laravel application with explicit action, domain, HTTP, and infrastructure boundaries.

**Why:** The components share one transaction and one deployment-sized domain. Microservices would move local invariants into distributed coordination. Full event sourcing would add event-versioning and projection-rebuild infrastructure even though the relational ledger already supplies the required immutable source.

**Trade-off:** Modules cannot be deployed independently, and discipline is required to preserve boundaries inside one codebase. The design accepts that cost in exchange for simpler transactions, debugging, and local operation.

## Deliberately left out

The service does not implement authentication or authorization, multi-currency accounting, fees, KYC, a real payment gateway, consumer-side event deduplication, broker-specific delivery, table partitioning, replicas, sharding, or multi-region consensus. The included Docker Compose stack is a local runtime, not a production orchestration specification.

These omissions keep the implementation focused on accounting correctness, retry safety, concurrent spending, lifecycle races, and recoverable event delivery.

## Coping with far more traffic

Application and outbox-worker processes are stateless and can scale horizontally. Work is bounded through cursor pagination, batch limits, rate limiting, reconciliation limits, and lease-based parallel outbox claims. Clearing and withdrawal-suspense traffic is distributed across pools of system wallets to avoid a single global lock.

The next scaling step would be measurement, not immediate sharding: sustained and burst tests, hot-wallet contention, database wait events, connection saturation, outbox lag, and post-run invariant checks. From that evidence I would add a connection pooler, tune indexes and batch sizes, move read-only history to replicas, and partition the largest append-only tables. Wallet-affinity routing or ledger sharding would come only after the single-primary design reaches a measured limit, because cross-shard transfers introduce a substantially harder consistency problem.

No local test proves a 100-million-request/day capacity; that target requires representative infrastructure and workload evidence.

## With another week

1. Add authentication and explicit authorization policies for user, gateway, and administrative routes.
2. Connect the outbox publisher to a real broker and add a reference consumer with event-ID deduplication.
3. Run sustained, burst, and hot-wallet load tests; publish latency, lock-wait, database, and outbox-lag results together with post-run reconciliation evidence.
4. Add retention and archival policies for idempotency records, published outbox rows, and long wallet histories.
5. Add production deployment manifests, secret injection, connection pooling, metrics, dashboards, alerts, backup restoration, and failure-recovery runbooks.
6. Exercise PostgreSQL failover and process-termination fault injection around transaction commit and event publication.

Deeper decision records and the invariant map are available in [docs/TECHNICAL_REVIEW.md](docs/TECHNICAL_REVIEW.md) and [docs/adr](docs/adr/).
