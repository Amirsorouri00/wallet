param(
    [ValidateSet('smoke', 'sustained', 'burst')]
    [string]$Profile = 'smoke',
    [string]$BaseUrl = 'http://127.0.0.1:8000',
    [string]$DatabaseHost = $(if ($env:PGHOST) { $env:PGHOST } else { '127.0.0.1' }),
    [string]$DatabasePort = $(if ($env:PGPORT) { $env:PGPORT } else { '5432' }),
    [string]$DatabaseName = $(if ($env:PGDATABASE) { $env:PGDATABASE } else { 'taline_wallet_ledger' }),
    [string]$DatabaseUser = $(if ($env:PGUSER) { $env:PGUSER } else { 'postgres' })
)

$ErrorActionPreference = 'Stop'

if (-not (Get-Command k6 -ErrorAction SilentlyContinue)) {
    throw 'k6 is not installed or is not on PATH.'
}

$psqlPath = (Get-Command psql -ErrorAction SilentlyContinue).Source
if (-not $psqlPath) {
    $psqlPath = (Get-ChildItem 'C:\Program Files\PostgreSQL\*\bin\psql.exe' -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending |
        Select-Object -First 1).FullName
}
if (-not $psqlPath) { throw 'psql is not installed or could not be located.' }

k6 run --env "PROFILE=$Profile" --env "BASE_URL=$BaseUrl" benchmarks/k6/wallet-api.js
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

php artisan wallets:reconcile
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& $psqlPath -X -v ON_ERROR_STOP=1 -h $DatabaseHost -p $DatabasePort -U $DatabaseUser -d $DatabaseName -f benchmarks/sql/verify-invariants.sql
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host 'Capacity run and post-run financial invariants passed.'
