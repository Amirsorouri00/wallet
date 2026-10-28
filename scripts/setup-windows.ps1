[CmdletBinding()]
param(
    [string]$PostgresHost = $(if ($env:DB_HOST) { $env:DB_HOST } else { '127.0.0.1' }),
    [int]$PostgresPort = $(if ($env:DB_PORT) { [int]$env:DB_PORT } else { 5432 }),
    [string]$PostgresUser = $(if ($env:DB_USERNAME) { $env:DB_USERNAME } else { 'postgres' }),
    [string]$PostgresPassword = $(if ($env:DB_PASSWORD) { $env:DB_PASSWORD } else { 'postgres' }),
    [switch]$ConfigureEnvironment
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $projectRoot

function Set-DotEnvValue {
    param(
        [Parameter(Mandatory)] [string]$Path,
        [Parameter(Mandatory)] [string]$Name,
        [Parameter(Mandatory)] [string]$Value
    )

    $lines = [System.Collections.Generic.List[string]](Get-Content -LiteralPath $Path)
    $replacement = "$Name=$Value"
    $matched = $false

    for ($index = 0; $index -lt $lines.Count; $index++) {
        if ($lines[$index] -match "^#?\s*$([Regex]::Escape($Name))=") {
            $lines[$index] = $replacement
            $matched = $true
            break
        }
    }

    if (-not $matched) {
        $lines.Add($replacement)
    }

    Set-Content -LiteralPath $Path -Value $lines -Encoding utf8
}

foreach ($command in @('php', 'composer', 'psql', 'createdb')) {
    if (-not (Get-Command $command -ErrorAction SilentlyContinue)) {
        throw "Required command '$command' is not on PATH. Install PHP 8.3, Composer, and PostgreSQL client tools first."
    }
}

$phpVersion = & php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'
if ($phpVersion -ne '8.3') {
    throw "PHP 8.3 is required; found PHP $phpVersion."
}

$requiredExtensions = @('ctype', 'curl', 'fileinfo', 'json', 'mbstring', 'openssl', 'pdo_pgsql', 'pgsql', 'tokenizer', 'xml')
$loadedExtensions = & php -r 'echo implode(PHP_EOL, get_loaded_extensions());'
foreach ($extension in $requiredExtensions) {
    if ($loadedExtensions -notcontains $extension) {
        throw "Required PHP extension '$extension' is not loaded. Enable it in php.ini."
    }
}

$env:PGPASSWORD = $PostgresPassword
& psql -h $PostgresHost -p $PostgresPort -U $PostgresUser -d postgres -v ON_ERROR_STOP=1 -c 'SELECT 1' | Out-Null

foreach ($database in @('taline_wallet_ledger', 'taline_wallet_ledger_test')) {
    if ($database -notmatch '^[a-z0-9_]+$') {
        throw "Unsafe database name '$database'."
    }

    $exists = & psql -h $PostgresHost -p $PostgresPort -U $PostgresUser -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$database'"
    if ($exists -ne '1') {
        & createdb -h $PostgresHost -p $PostgresPort -U $PostgresUser $database
    }
}

& composer install --no-interaction --prefer-dist

$environmentCreated = -not (Test-Path -LiteralPath '.env')

if ($environmentCreated) {
    Copy-Item -LiteralPath '.env.example' -Destination '.env'
} elseif (-not (Select-String -LiteralPath '.env' -Pattern '^DB_CONNECTION=pgsql$' -Quiet)) {
    if (-not $ConfigureEnvironment) {
        throw 'Existing .env was preserved but DB_CONNECTION is not pgsql. Update its DB_* values, or rerun with -ConfigureEnvironment to create a backup and update only APP_NAME/DB_*.'
    }

    if (-not (Test-Path -LiteralPath '.env.before-postgres')) {
        Copy-Item -LiteralPath '.env' -Destination '.env.before-postgres'
    }
}

if ($environmentCreated -or $ConfigureEnvironment) {
    $environmentValues = [ordered]@{
        APP_NAME = '"Taline Wallet Ledger"'
        DB_CONNECTION = 'pgsql'
        DB_HOST = $PostgresHost
        DB_PORT = [string]$PostgresPort
        DB_DATABASE = 'taline_wallet_ledger'
        DB_USERNAME = $PostgresUser
        DB_PASSWORD = $PostgresPassword
        DB_TIMEZONE = 'UTC'
        DB_APPLICATION_NAME = 'taline-wallet-ledger'
    }

    foreach ($entry in $environmentValues.GetEnumerator()) {
        Set-DotEnvValue -Path '.env' -Name $entry.Key -Value $entry.Value
    }
}

if (-not (Test-Path -LiteralPath '.env.testing')) {
    Copy-Item -LiteralPath '.env.testing.example' -Destination '.env.testing'
}

if (-not (Select-String -LiteralPath '.env' -Pattern '^APP_KEY=.+$' -Quiet)) {
    & php artisan key:generate --force
}

$env:DB_CONNECTION = 'pgsql'
$env:DB_HOST = $PostgresHost
$env:DB_PORT = [string]$PostgresPort
$env:DB_DATABASE = 'taline_wallet_ledger'
$env:DB_USERNAME = $PostgresUser
$env:DB_PASSWORD = $PostgresPassword
$env:DB_TIMEZONE = 'UTC'
& php artisan migrate --seed --force

$env:DB_DATABASE = 'taline_wallet_ledger_test'
& php artisan migrate:fresh --seed --env=testing --force
& php artisan route:list --path=api

Write-Host 'Setup complete. Run .\scripts\run-local.ps1, then open http://127.0.0.1:8000/docs/api.' -ForegroundColor Green
