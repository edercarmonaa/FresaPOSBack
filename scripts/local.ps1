$Tool = 'serve'
$ToolArgs = @()
if ($args.Count -gt 0) { $Tool = $args[0] }
if ($args.Count -gt 1) { $ToolArgs = $args[1..($args.Count - 1)] }
if ($Tool -notin @('php', 'composer', 'serve')) {
    throw 'Comando esperado: php, composer o serve.'
}

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$php = Join-Path $projectRoot '.tools/php/php.exe'
if (-not (Test-Path -LiteralPath $php)) {
    throw 'Ejecuta primero .\scripts\setup-local.ps1'
}

$oldPath = $env:PATH
$oldHome = $env:COMPOSER_HOME
$oldCache = $env:COMPOSER_CACHE_DIR
Push-Location $projectRoot
try {
    $env:PATH = $PSScriptRoot + ';' + (Split-Path $php -Parent) + ';' + $env:PATH
    $env:COMPOSER_HOME = Join-Path $projectRoot '.tools/composer-home'
    $env:COMPOSER_CACHE_DIR = Join-Path $projectRoot '.tools/composer-cache'
    switch ($Tool) {
        'php' { & $php @ToolArgs }
        'composer' { & $php (Join-Path $projectRoot '.tools/composer.phar') @ToolArgs }
        'serve' { & $php artisan serve --host=127.0.0.1 --port=8080 @ToolArgs }
    }
    $result = $LASTEXITCODE
} finally {
    $env:PATH = $oldPath
    $env:COMPOSER_HOME = $oldHome
    $env:COMPOSER_CACHE_DIR = $oldCache
    Pop-Location
}
exit $result
