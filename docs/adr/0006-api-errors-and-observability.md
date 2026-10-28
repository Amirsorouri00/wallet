# ADR 0006: Stable errors and request correlation

- Status: Accepted
- Date: 2026-10-24

## Context

Clients need stable failure behavior, while operators need enough context to diagnose concurrency and delivery problems without exposing internal exceptions or credentials.

## Decision

API failures use a centralized RFC 9457-style `application/problem+json` representation. Public error definitions own their stable code, HTTP status, title, problem type, retryability, and safe detail. Validation can add field errors; transient rate-limit and database-busy responses add retry guidance.

Middleware accepts a syntactically safe `X-Request-Id` or generates one, echoes it on every response, places it in structured log context, and uses it as the problem instance correlation value. Logs include operation and outcome identifiers while raw idempotency keys and credentials are omitted or hashed.

## Consequences

- Clients branch on stable codes rather than exception text.
- Unexpected errors remain diagnosable in server logs but receive sanitized responses.
- Request identifiers correlate API, problem, and log records across processes.
- Public error changes require contract and OpenAPI review.
