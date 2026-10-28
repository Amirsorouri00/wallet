# Taline Wallet Ledger

A PHP 8.3, Laravel 13, and PostgreSQL wallet service built around a double-entry accounting ledger. Each posting appends an auditable wallet state containing the delta, previous balance, resulting balance, version, predecessor, and source ledger entry; balances are never updated in place.

The API supports deposits and signed gateway callbacks, transfers, withdrawals, cursor-based history, transactional idempotency, and transactional outbox delivery. PostgreSQL provides the transaction, constraint, and locking boundary. Public failures use Problem Details, responses carry request IDs, and operational endpoints expose liveness and readiness separately.

## Architecture highlights

- `ledger_transactions` and `ledger_entries` are the accounting authority; `wallet_balance_states` is an append-only projection.
- Financial commands atomically commit lifecycle state, ledger entries, projected balances, idempotency results, and outbox messages.
- A canonical request fingerprint makes identical retries replayable and changed-payload key reuse a conflict.
- Wallet locks are acquired in ascending UUID order to prevent concurrent overspending and reduce deadlocks.
- Outbox delivery is at least once: workers use leases and `FOR UPDATE SKIP LOCKED`, while consumers deduplicate stable event UUIDs.
- Withdrawal approval performs the only debit; later rejection uses an explicit compensating transaction.

Start with the short [DESIGN.md](DESIGN.md) for the data model, trade-offs, exclusions, scaling approach, and next steps. For the invariant matrix, transaction flow, and repository map, continue to the [Technical Review](docs/TECHNICAL_REVIEW.md). Major decisions are recorded under [Architecture Decision Records](docs/adr/).

## Native Windows

Prerequisites are PHP 8.3 with `pdo_pgsql`, Composer, PostgreSQL client tools, and a running PostgreSQL server.

### Recommended automated setup

From a fresh clone, run these commands in order:

```powershell
git clone <repository-url>
cd taline_wallet_ledger
.\scripts\setup-windows.ps1 -PostgresUser postgres -PostgresPassword postgres
.\scripts\run-local.ps1
```

The setup script runs `composer install --no-interaction --prefer-dist`, creates `.env` and `.env.testing` when missing, generates the application key, creates the development and test databases, and migrates and seeds both databases. The committed `composer.lock` ensures another machine installs the same dependency versions. If an existing `.env` does not target PostgreSQL, either update it manually or opt into the script's backed-up environment change:

```powershell
.\scripts\setup-windows.ps1 `
    -PostgresUser postgres `
    -PostgresPassword postgres `
    -ConfigureEnvironment
```

`run-local.ps1` starts the API and continuous outbox worker in labelled PowerShell windows. Press Ctrl+C in each window to stop them.

### Manual setup

If the setup script is not used, the equivalent order is:

```powershell
git clone <repository-url>
cd taline_wallet_ledger

# Restore the gitignored vendor directory from composer.lock.
composer install --no-interaction --prefer-dist

# Create local environment files.
Copy-Item .env.example .env
Copy-Item .env.testing.example .env.testing
php artisan key:generate

# Create both PostgreSQL databases if they do not already exist.
$env:PGPASSWORD = 'postgres'
createdb -h 127.0.0.1 -p 5432 -U postgres taline_wallet_ledger
createdb -h 127.0.0.1 -p 5432 -U postgres taline_wallet_ledger_test

# Apply the development and test schemas and seed required wallets.
php artisan migrate --seed --force
php artisan migrate:fresh --seed --env=testing --force

# Start the API and outbox worker.
.\scripts\run-local.ps1
```

If either database already exists, skip its `createdb` command. Update the PostgreSQL `DB_*` values in `.env` and `.env.testing` when your host, port, username, or password differs from the shown local defaults.

## Docker Compose

Docker Compose needs no host PHP, Composer, or PostgreSQL installation:

```powershell
docker compose up --build -d
docker compose ps
docker compose logs -f api outbox-worker
```

Startup is deliberately ordered:

1. PostgreSQL must pass `pg_isready`.
2. The one-shot `migrate` service runs `php artisan migrate --force --no-interaction`, then idempotently provisions the required demo and pooled system wallets.
3. The API and outbox worker start only after migration exits successfully.

Laravel migrations are forward-only and idempotent, so every Compose startup checks and applies pending migrations before serving traffic. The seeder verifies deterministic wallet identities and is also safe to rerun. If either step fails, dependent services remain stopped and the error is visible with `docker compose logs migrate`.

The image is built from [build/Dockerfile](build/Dockerfile). It uses a Composer dependency stage and a PHP 8.3 CLI runtime stage containing the extensions required by the application and its installed packages. The Compose stack is intended for local development and inspection, not as a production deployment manifest.

Stop the stack while retaining PostgreSQL data:

```powershell
docker compose down
```

Remove the local database volume as well:

```powershell
docker compose down --volumes
```

The second command permanently deletes the Compose database data.

## Local endpoints

- Liveness: [http://127.0.0.1:8000/api/health](http://127.0.0.1:8000/api/health)
- Readiness: [http://127.0.0.1:8000/api/ready](http://127.0.0.1:8000/api/ready)
- Interactive OpenAPI: [http://127.0.0.1:8000/docs/api](http://127.0.0.1:8000/docs/api)
- Generated OpenAPI JSON: [docs/openapi.json](docs/openapi.json)

Business routes are versioned under `/api/v1`. Financial mutation requests require a visible-ASCII `Idempotency-Key` between 1 and 128 bytes. Amounts use integer `amount_minor`; currency conversion is outside this service boundary.

## Verify and demonstrate

The canonical quality gate uses an isolated PostgreSQL test database:

```powershell
.\scripts\verify.ps1
```

Equivalently:

```powershell
composer check
```

The gate checks formatting, Composer metadata and advisories, PHP syntax, fresh migrations and seeds, unit/feature/integration tests, generated OpenAPI drift, reconciliation drift, and outbox lag.

To walk through the lifecycle with isolated demo wallets:

```powershell
php artisan wallet:demo-scenario
```

The scenario covers deposit creation and confirmation, identical and conflicting retries, transfer, withdrawal approval and settlement, wallet state history, and reconciliation.

## Operations

```powershell
php artisan outbox:work --sleep=1000 --batch=100
php artisan outbox:status --max-lag=60
php artisan wallets:reconcile --limit=10000 --fail-on-drift
```

`/api/health` is process liveness. `/api/ready` verifies database connectivity, migrations, required system wallets, and acceptable outbox lag. Operational commands are bounded and return nonzero exit codes for unhealthy state.

## Documentation

- [Short design overview](DESIGN.md)
- [Technical review and repository tour](docs/TECHNICAL_REVIEW.md)
- [Architecture decision records](docs/adr/)
- [Generated OpenAPI contract](docs/openapi.json)

The service is structured for horizontal application and worker scaling, but no local test establishes a 100-million-request/day capacity. Any throughput claim requires representative sustained, burst, hot-wallet, outbox-lag, and post-run invariant measurements.
