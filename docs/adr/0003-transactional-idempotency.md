# ADR 0003: Transactional idempotency

- Status: Accepted
- Date: 2026-10-24

## Context

Clients retry when connections time out or responses are lost. A retry may arrive after the original mutation committed, so transport behavior cannot determine whether it is safe to execute again.

## Decision

Financial commands require a validated `Idempotency-Key`. Records are uniquely scoped by actor wallet, operation, and key. A SHA-256 fingerprint of the canonical normalized request binds the key to one intent.

The idempotency reservation, financial mutation, response representation, and outbox event share one database transaction. Successful results and deterministic business rejections are stored and replayed. A reused key with a different fingerprint returns a conflict. A transient exception rolls the reservation back with the rest of the transaction.

## Consequences

- Lost-response retries return the original committed result without duplicating ledger activity.
- Identical keys can be reused safely in different wallet or operation scopes.
- Canonicalization rules are part of the command contract and must remain stable.
- Records require retention and archival policy in a long-lived deployment.
