param([string]$PythonPath = '')
$ErrorActionPreference = 'Stop'
$repository = Split-Path $PSScriptRoot -Parent
Push-Location $repository
try {
    if (-not (Test-Path -LiteralPath '.tools/openapi/Scripts/python.exe')) {
        if (-not $PythonPath) { throw 'Indica -PythonPath con un Python 3.12 real para crear el entorno aislado de desarrollo.' }
        & $PythonPath -m venv .tools/openapi
        if ($LASTEXITCODE -ne 0) { throw 'No se pudo crear el entorno de OpenAPI.' }
    }
    & .tools/openapi/Scripts/python.exe -m pip install --disable-pip-version-check -r scripts/requirements-openapi.txt
    if ($LASTEXITCODE -ne 0) { throw 'No se pudieron preparar las herramientas de OpenAPI.' }
} finally {
    Pop-Location
}
