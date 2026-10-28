# ADR 0007: Scaling posture and operational boundaries

- Status: Accepted
- Date: 2026-10-24

## Context

High request volume demands bounded work and horizontal concurrency, but financial correctness creates necessary serialization points. Premature sharding or removing locks would trade demonstrable invariants for speculative throughput.

## Decision

Keep API processes stateless and use PostgreSQL as the consistency boundary. Bound history pages, reconciliation, and outbox batches. Spread deposit clearing and withdrawal suspense traffic across configured system-wallet pools. Use database-backed rate limits and readiness checks for controlled backpressure. Scale outbox publication with lease-based parallel workers.

Treat 100 million requests per day as a workload target, not a verified capability. Require representative sustained, burst, hot-wallet, and delivery-lag tests plus post-run invariant checks before making a capacity claim. Introduce connection pooling, partitioning, replicas, or sharding only from measured bottlenecks.

## Consequences

- Independent API and worker replicas can scale horizontally until database or hot-key limits dominate.
- Hot wallets remain serialized by design; pooled system wallets reduce avoidable global contention.
- Operational commands and probes expose lag and drift as machine-readable failure signals.
- Capacity remains explicitly unverified without workload-specific infrastructure evidence.
