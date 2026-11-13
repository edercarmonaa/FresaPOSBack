param([string]$Responses = '', [string]$Report = '.tools/openapi-report.json')
$ErrorActionPreference = 'Stop'
$repository = Split-Path $PSScriptRoot -Parent
Push-Location $repository
try {
    if (-not (Test-Path -LiteralPath '.tools/openapi/Scripts/python.exe')) { throw 'Prepara el validador con scripts/setup-openapi.ps1 antes de validar.' }
    $routes = & .\scripts\local.cmd php scripts/export-api-routes.php
    if ($LASTEXITCODE -ne 0) { throw 'No se pudieron leer las rutas reales.' }
    [IO.File]::WriteAllText((Join-Path $repository '.tools/openapi-routes.json'), ($routes -join "`n"), [Text.UTF8Encoding]::new($false))
    $validationArgs = @('scripts/validate-openapi.py', 'docs/openapi.yaml', '--routes', '.tools/openapi-routes.json', '--report', $Report)
    if ($Responses) { $validationArgs += @('--responses', $Responses) }
    & .tools/openapi/Scripts/python.exe @validationArgs
    if ($LASTEXITCODE -ne 0) { throw 'La validacion OpenAPI o de contratos fallo. Revisa el informe.' }
} finally {
    Pop-Location
}
