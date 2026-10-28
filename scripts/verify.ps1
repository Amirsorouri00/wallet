[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $projectRoot

& composer check
if ($LASTEXITCODE -ne 0) {
    throw "Verification failed with exit code $LASTEXITCODE."
}

Write-Host 'All verification gates passed.' -ForegroundColor Green
