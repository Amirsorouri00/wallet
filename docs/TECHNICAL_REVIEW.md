# Technical Review Guide

This document is the shortest useful path through the wallet ledger. It explains the guarantees the code is designed to provide, where those guarantees are enforced, how to exercise the service, and which scale claims remain intentionally unproven.

## System at a glance

The service is a PHP 8.3 and Laravel 13 API backed by PostgreSQL. It supports deposits with gateway callbacks, wallet-to-wallet transfers, withdrawals, cursor-based history, and asynchronous event publication.

The central design rule is that `ledger_transactions` and `ledger_entries` are the accounting authority. `wallet_balance_states` is an append-only, auditable projection: every wallet mutation creates a row containing the delta, previous balance, resulting balance, version, predecessor, and originating ledger entry. There is no mutable balance column to update in place.

```text
HTTP request
  -> validation and idempotency scope
  -> PostgreSQL transaction
       -> lock affected wallets in UUID order
       -> lifecycle state change
       -> balanced ledger transaction and entries
       -> append wallet balance states
       -> persist terminal idempotency result
       -> append outbox event
  -> commit
  -> stable response or replayed result

Outbox worker
  -> claim a bounded batch with FOR UPDATE SKIP LOCKED
  -> publish with a stable event UUID
  -> mark published, retry with backoff, or dead-letter
```

## Correctness boundaries

| Property | Primary enforcement | Independent evidence |
| --- | --- | --- |
| Every posting balances to zero | Posting value object and service | PostgreSQL integration tests and reconciliation |
| A user wallet never becomes negative | Ordered row locks and pre-post balance calculation | Database constraint on every projected state |
| Balance history cannot fork or skip a version | Composite predecessor foreign key and uniqueness constraints | Chain reconciliation query |
| Financial history is append-only | PostgreSQL update/delete rejection triggers | Mutation tests |
| A retry cannot apply a second mutation | Transactional idempotency row with scoped unique key | Identical-key and changed-payload tests |
| An event cannot commit without its mutation | Outbox row written in the same transaction | Rollback injection tests |
| Multiple workers do not publish the same active claim | Leases plus `FOR UPDATE SKIP LOCKED` | Multi-worker integration tests |
| Concurrent debits do not overspend | `SELECT ... FOR UPDATE` on wallets in deterministic order | Native-process PostgreSQL contention tests |

Application checks produce useful domain errors. Database checks, composite foreign keys, unique constraints, and triggers defend the invariants even if a future write path bypasses an application service.

## Transaction and retry semantics

Each financial command commits its lifecycle row, idempotency result, balanced ledger posting, balance projection, and outbox message atomically. An exception at any point rolls the whole unit back.

Idempotency is scoped by actor wallet, operation, and visible-ASCII key. A canonical request hash distinguishes a legitimate retry from reuse with different input. Successful responses and deterministic business rejections are durable and replayable; transient failures roll back the reservation so a later request can retry the operation.

Wallets are locked in ascending UUID order. This serializes competing debits and removes inconsistent lock acquisition as a source of transfer deadlocks. Laravel's bounded transaction retry is reserved for database concurrency failures; it does not replace idempotency because the client may lose a response after commit.

The outbox provides atomic recording and at-least-once delivery, not exactly-once external effects. Event UUIDs remain stable across attempts, so consumers must deduplicate by event ID. Failed events use bounded exponential backoff and become inspectable dead letters after the configured attempt ceiling.

## Withdrawal lifecycle

Creating a withdrawal records intent but does not reserve funds. Approval performs the only debit, moving value from the user wallet to a pooled system suspense wallet. Settlement changes lifecycle state without a second debit. Rejection before approval posts nothing; rejection after approval creates an explicit compensating ledger transaction.

Lifecycle rows are locked before administrative transitions. Concurrent approval, rejection, settlement, and transfer paths therefore have one observable winner while preserving ledger and projection consistency.

## API and failure contract

Business routes are versioned under `/api/v1`; liveness and readiness remain operational endpoints at `/api/health` and `/api/ready`. Financial mutations require `Idempotency-Key`. Amounts are signed 64-bit integer minor units; currency conversion is deliberately outside this service boundary.

Errors use RFC 9457-style `application/problem+json` responses with stable `type`, `title`, `status`, `detail`, `instance`, and `code` fields. Optional `errors` and `retryable` fields carry validation and retry guidance. Raw SQL, exception messages, and stack traces are not public response contracts.

Every response carries `X-Request-Id`. The same identifier is placed in structured log context and used by Problem Details instances. Rate-limit and exhausted transient-database failures include retry guidance.

The generated contract is [openapi.json](openapi.json). With the application running, interactive documentation is available at `http://127.0.0.1:8000/docs/api`.

## Operational model

- `/api/health` is cheap process liveness.
- `/api/ready` checks PostgreSQL connectivity, migration compatibility, required system wallets, and outbox lag.
- `php artisan outbox:work` continuously publishes bounded batches.
- `php artisan outbox:status --max-lag=60` returns a failing exit status when delivery falls behind.
- `php artisan wallets:reconcile --limit=10000 --fail-on-drift` compares ledger authority with balance projections without repairing or mutating financial history.
- `php artisan wallet:demo-scenario` creates isolated data and demonstrates deposit, replay, transfer, withdrawal, history, and reconciliation behavior.

The canonical project gate is `composer check`. It performs formatting, package validation and advisory checks, PHP syntax checks, fresh PostgreSQL migrations and seeds, the complete test suite, OpenAPI drift detection, reconciliation, and outbox-lag checks. CI invokes the same gate instead of maintaining a second definition.

## Scale posture

One hundred million requests per day is about 1,158 requests per second on average, but averages conceal bursts, hot-wallet contention, database write amplification, and outbox lag. The code prepares for horizontal stateless API processes, bounded pagination and commands, pooled system wallets, deterministic locking, rate-limit backpressure, and parallel outbox claims.

No local correctness suite establishes a throughput capacity. A production claim requires representative sustained and burst load, hot-wallet scenarios, database saturation monitoring, outbox-lag thresholds, and post-run invariant checks. Partitioning, replicas, connection pooling, and sharding are deployment decisions to make from measured bottlenecks, not speculative complexity embedded in this repository.

## Repository map

| Path | Responsibility |
| --- | --- |
| `app/Actions/Wallet` | Financial use cases and lifecycle transitions |
| `app/Services/Wallet` | Posting, idempotency, outbox, and provisioning mechanics |
| `app/Domain/Wallet` | Stable enums, error codes, outcomes, and value objects |
| `app/Http` | Validation, controllers, response contracts, and request correlation |
| `database/migrations` | Executable relational and accounting contracts |
| `tests/Integration/Wallet` | Real PostgreSQL transaction, constraint, rollback, and concurrency coverage |
| `benchmarks` | Load workload and post-run invariant queries; not capacity evidence by themselves |
| `docs/adr` | Context and consequences of the major architectural decisions |
| `scripts` | Native Windows setup, execution, and canonical verification entry points |

## Architecture decisions

1. [Double-entry authority and append-only projection](adr/0001-double-entry-ledger-and-balance-projection.md)
2. [PostgreSQL transaction boundaries and lock ordering](adr/0002-postgresql-transactions-and-lock-ordering.md)
3. [Transactional idempotency and retry safety](adr/0003-transactional-idempotency.md)
4. [Transactional outbox and at-least-once delivery](adr/0004-transactional-outbox.md)
5. [Withdrawal lifecycle and compensation](adr/0005-withdrawal-lifecycle.md)
6. [Problem Details, request correlation, and operational errors](adr/0006-api-errors-and-observability.md)
7. [Scaling posture and operational boundaries](adr/0007-scaling-and-operational-boundaries.md)

## Known boundaries

Authentication and authorization, multi-currency accounting, fees, KYC, a real payment gateway, broker selection, table partitioning, and multi-region operation are not implemented. The included publisher is an integration seam; a production broker consumer still needs event-ID deduplication. The Docker Compose stack is for local execution and is not a production orchestration specification.
