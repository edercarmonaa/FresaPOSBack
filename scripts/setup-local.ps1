param([string]$SqlInstance = '.\SQLEXPRESS')

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = Split-Path $PSScriptRoot -Parent
$tools = Join-Path $projectRoot '.tools'
$utf8 = New-Object System.Text.UTF8Encoding($false)
New-Item -ItemType Directory -Force -Path $tools | Out-Null

function Get-VerifiedFile([string]$Url, [string]$Path, [string]$Hash) {
    if (-not (Test-Path -LiteralPath $Path)) {
        Invoke-WebRequest -UseBasicParsing -Uri $Url -OutFile $Path
    }
    if ((Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash -ne $Hash) {
        throw "Checksum incorrecto: $Path. Retira ese archivo y repite."
    }
}

if (-not (Get-OdbcDriver -Name 'ODBC Driver 18 for SQL Server' -Platform '64-bit' -ErrorAction SilentlyContinue)) {
    throw 'Instala Microsoft ODBC Driver 18 x64. Consulta docs/entorno-desarrollo.md.'
}

Get-VerifiedFile 'https://windows.php.net/downloads/releases/php-8.3.36-1-nts-Win32-vs16-x64.zip' (Join-Path $tools 'php.zip') '1a097d52b7a68622cdc723073b61cc70caf79c941218ff65b2d8441dbc6b10d4'
$phpDir = Join-Path $tools 'php'
if (-not (Test-Path -LiteralPath (Join-Path $phpDir 'php.exe'))) {
    Expand-Archive -LiteralPath (Join-Path $tools 'php.zip') -DestinationPath $phpDir
}
Get-VerifiedFile 'https://getcomposer.org/download/2.9.2/composer.phar' (Join-Path $tools 'composer.phar') '471f2d857abf0ec18af7b055e61472214d91adb24f9bdbbb864c1c64faad7dd6'
Get-VerifiedFile 'https://github.com/microsoft/msphpsql/releases/download/v5.13.3/Windows_5.13.3RTW.zip' (Join-Path $tools 'msphpsql-windows.zip') '1538292e1463dc706e0c3038d685b9e55781e592b0dc8634ecf54f44eef4d4d8'
Expand-Archive -LiteralPath (Join-Path $tools 'msphpsql-windows.zip') -DestinationPath (Join-Path $tools 'msphpsql-windows') -Force
foreach ($name in @('php_sqlsrv_83_nts_x64.dll', 'php_pdo_sqlsrv_83_nts_x64.dll')) {
    $source = Join-Path $tools "msphpsql-windows/Windows/$name"
    $destination = Join-Path $phpDir "ext/$name"
    if (-not (Test-Path -LiteralPath $destination) -or
        (Get-FileHash -LiteralPath $source).Hash -ne (Get-FileHash -LiteralPath $destination).Hash) {
        Copy-Item -LiteralPath $source -Destination $destination -Force
    }
}
$ca = Join-Path $tools 'cacert.pem'
if (-not (Test-Path -LiteralPath $ca)) {
    Invoke-WebRequest -UseBasicParsing 'https://curl.se/ca/cacert.pem' -OutFile $ca
}
$extensions = (Join-Path $phpDir 'ext').Replace('\', '/')
$ca = $ca.Replace('\', '/')
$ini = @"
; Managed by scripts/setup-local.ps1, for the project portable PHP only.
extension_dir="$extensions"
extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=zip
extension=intl
extension=gd
extension=exif
extension=php_sqlsrv_83_nts_x64.dll
extension=php_pdo_sqlsrv_83_nts_x64.dll
curl.cainfo="$ca"
openssl.cafile="$ca"
date.timezone=America/Mexico_City
upload_max_filesize=8M
post_max_size=16M
memory_limit=256M
"@
[IO.File]::WriteAllText((Join-Path $phpDir 'php.ini'), $ini, $utf8)

$envPath = Join-Path $projectRoot '.env'
if (-not (Test-Path -LiteralPath $envPath)) {
    if ($SqlInstance -match '[\r\n";]') { throw 'Instancia SQL invalida.' }
    $example = [IO.File]::ReadAllText((Join-Path $projectRoot '.env.example'))
    # El ejemplo versiona marcadores; este script construye el perfil local.
    $localValues = @{
        APP_ENV = 'local'
        APP_DEBUG = 'true'
        APP_URL = 'http://127.0.0.1:8080'
        DB_HOST = $SqlInstance
        DB_DATABASE = 'fresapos'
    }
    $lines = foreach ($line in ($example -split '\r?\n')) {
        $name = ($line -split '=', 2)[0]
        if ($localValues.ContainsKey($name)) { "$name=$($localValues[$name])" } else { $line }
    }
    $example = [string]::Join([Environment]::NewLine, $lines)
    [IO.File]::WriteAllText($envPath, $example, $utf8)
}

& (Join-Path $PSScriptRoot 'local.ps1') composer install --no-interaction --prefer-dist
if ($LASTEXITCODE -ne 0) { throw 'Composer install fallo.' }
if ([IO.File]::ReadAllText($envPath) -match '(?m)^APP_KEY=\s*$') {
    & (Join-Path $PSScriptRoot 'local.ps1') php artisan key:generate
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo generar APP_KEY.' }
}
& (Join-Path $PSScriptRoot 'local.ps1') php artisan config:clear
if ($LASTEXITCODE -ne 0) { throw 'No se pudo limpiar configuracion.' }
& (Join-Path $PSScriptRoot 'local.ps1') php scripts/init-local-db.php
if ($LASTEXITCODE -ne 0) { throw 'No se pudo preparar la base. Verifica instancia y permisos de tu usuario Windows.' }
Write-Output 'Listo. Inicia con .\scripts\local.cmd serve y abre http://127.0.0.1:8080'
