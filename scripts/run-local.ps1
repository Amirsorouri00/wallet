[CmdletBinding()]
param(
    [int]$Port = 8000
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot

if ($Port -lt 1 -or $Port -gt 65535) {
    throw 'Port must be between 1 and 65535.'
}

$serverCommand = "Set-Location -LiteralPath '$projectRoot'; `$Host.UI.RawUI.WindowTitle='Taline API'; php artisan serve --host=127.0.0.1 --port=$Port"
$workerCommand = "Set-Location -LiteralPath '$projectRoot'; `$Host.UI.RawUI.WindowTitle='Taline Outbox Worker'; php artisan outbox:work --sleep=1000 --batch=100"

Start-Process powershell -ArgumentList '-NoExit', '-Command', $serverCommand
Start-Process powershell -ArgumentList '-NoExit', '-Command', $workerCommand

Write-Host "API:        http://127.0.0.1:$Port/api/health"
Write-Host "Readiness:  http://127.0.0.1:$Port/api/ready"
Write-Host "OpenAPI UI: http://127.0.0.1:$Port/docs/api"
Write-Host 'Close both labelled PowerShell windows, or press Ctrl+C in each, to stop the local processes.'
