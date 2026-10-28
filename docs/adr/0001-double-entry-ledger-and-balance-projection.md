# ADR 0001: Double-entry authority and append-only balance projection

- Status: Accepted
- Date: 2026-10-24

## Context

A wallet needs fast balance reads without allowing a mutable balance field to become an untraceable accounting authority. Financial history must remain explainable after retries, compensation, and concurrent activity.

## Decision

`ledger_transactions` and `ledger_entries` are the accounting authority. Every posting has distinct, nonzero wallet lines whose signed `amount_minor` values sum to zero.

Each posting also appends one `wallet_balance_states` row per affected wallet. A state records `delta_minor`, `balance_before_minor`, `balance_after_minor`, a monotonically increasing version, its predecessor, and its source ledger entry. Version zero is the wallet's genesis state.

PostgreSQL composite foreign keys, uniqueness constraints, arithmetic checks, nonnegative-user-wallet checks, and immutability triggers enforce the projection shape. Reconciliation recomputes ledger totals and validates projection links without rewriting history.

## Consequences

- Balance reads use the latest projection row while audits can trace every change to a ledger entry.
- Corrections require new compensating postings; historical rows cannot be edited.
- Every write has more rows and constraints than an in-place balance update.
- Rebuilding or independently verifying the projection remains possible because the ledger is authoritative.
