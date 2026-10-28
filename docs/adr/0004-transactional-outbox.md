# ADR 0004: Transactional outbox with at-least-once delivery

- Status: Accepted
- Date: 2026-10-24

## Context

Publishing to an external broker inside a database transaction creates a dual-write problem: either the broker or database can succeed while the other fails.

## Decision

Domain events are inserted into `outbox_messages` in the same PostgreSQL transaction as the lifecycle, ledger, projection, and idempotency writes. A separate worker claims bounded batches with `FOR UPDATE SKIP LOCKED`, publishes outside the claim transaction, and marks completion only while it still owns the lease.

Failures retain the stable event UUID, increment attempts, and schedule bounded exponential backoff. Expired leases can be reclaimed. Messages reaching the attempt ceiling become inspectable dead letters rather than disappearing.

## Consequences

- A committed mutation always has a committed event record; a rollback has neither.
- Delivery is at least once, so consumers must deduplicate by event UUID.
- Multiple workers can claim disjoint rows without a central coordinator.
- Publication health is operational state and must be monitored through lag, attempts, and dead-letter counts.
