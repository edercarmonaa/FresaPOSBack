param(
    [string]$SqlInstance = '.\SQLEXPRESS',
    [string]$Database = 'fresapos_test'
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
if ($Database.Length -gt 128 -or $Database -notmatch '\A[a-zA-Z][a-zA-Z0-9_]*_test\z' -or $Database -like 'CHANGE_ME*') {
    throw 'La base debe tener un nombre propio terminado en _test y hasta 128 caracteres.'
}
if ($SqlInstance -notmatch '\A(?:\.|\(local\)|localhost|127\.0\.0\.1)(?:\\[a-zA-Z0-9_]+)?\z') {
    throw 'Este preparador solo admite una instancia local autorizada.'
}
$envPath = Join-Path $projectRoot '.env.testing'
if (-not (Test-Path -LiteralPath $envPath)) {
    $example = [IO.File]::ReadAllText((Join-Path $projectRoot '.env.testing.example'))
    $example = $example.Replace('TEST_DB_HOST=CHANGE_ME', "TEST_DB_HOST=$SqlInstance")
    $example = $example.Replace('TEST_DB_DATABASE=CHANGE_ME_test', "TEST_DB_DATABASE=$Database")
    [IO.File]::WriteAllText($envPath, $example, (New-Object System.Text.UTF8Encoding($false)))
}
& (Join-Path $PSScriptRoot 'local.ps1') php scripts/init-test-db.php
if ($LASTEXITCODE -ne 0) {
    throw 'No se pudo preparar la base de pruebas. Revisa .env.testing; si faltan permisos, entregar scripts/create-test-database.sql al administrador.'
}
