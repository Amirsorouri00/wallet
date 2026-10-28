# ADR 0005: Withdrawal debit timing and compensation

- Status: Accepted
- Date: 2026-10-24

## Context

Withdrawal workflows have asynchronous administrative states. Reserving funds at request time adds a hold model, expiry, and release semantics that the current domain does not require, while debiting at settlement makes an approved withdrawal compete unpredictably with later spending.

## Decision

Creation records intent without moving funds. Approval performs the single debit from the user wallet to a pooled withdrawal-suspense system wallet. Settlement changes the lifecycle state without another posting. Rejection before approval posts nothing; rejection after approval appends a compensating transaction returning the funds.

Every transition is idempotent and locks the withdrawal row. Terminal transitions are mutually exclusive. Compensation is new accounting activity rather than mutation of the approval posting.

## Consequences

- Funds remain spendable until approval, and insufficient funds at approval is a durable business rejection.
- Approved withdrawals have already consumed the user balance.
- Reversals preserve the audit trail but add another balanced transaction and outbox event.
- Expiring holds are unnecessary; adding authorization holds later would be a separate domain decision.
