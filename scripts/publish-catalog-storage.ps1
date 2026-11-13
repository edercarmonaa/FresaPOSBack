$ErrorActionPreference = 'Stop'
$repository = Split-Path $PSScriptRoot -Parent
$target = [IO.Path]::GetFullPath((Join-Path $repository 'storage/app/public'))
$link = [IO.Path]::GetFullPath((Join-Path $repository 'public/storage'))
if (-not (Test-Path -LiteralPath $target -PathType Container)) { throw 'No existe el disco público local.' }
$existing = Get-Item -LiteralPath $link -Force -ErrorAction SilentlyContinue
if ($null -ne $existing) {
    if (-not $existing.LinkType -or @($existing.Target).Count -ne 1) { throw 'public/storage ya existe y no es un enlace comprobable; no se modificó.' }
    $linkTarget = @($existing.Target)[0]
    $actualTarget = if ([IO.Path]::IsPathRooted($linkTarget)) { [IO.Path]::GetFullPath($linkTarget) } else { [IO.Path]::GetFullPath((Join-Path (Split-Path $link -Parent) $linkTarget)) }
    if ($actualTarget -ne $target) { throw 'public/storage apunta a otra ubicación; no se modificó.' }
} else {
    New-Item -ItemType Junction -Path $link -Target $target | Out-Null
}
$images = Join-Path $target 'catalog/products'
New-Item -ItemType Directory -Path $images -Force | Out-Null
$rules = Join-Path $images '.htaccess'
$template = Join-Path $repository 'Modules/Catalog/resources/storage/.htaccess'
if (Test-Path -LiteralPath $rules) {
    if ((Get-FileHash -LiteralPath $rules).Hash -ne (Get-FileHash -LiteralPath $template).Hash) { throw 'Ya existen reglas diferentes de publicación; no se sobrescribieron.' }
} else {
    Copy-Item -LiteralPath $template -Destination $rules
}
Write-Output 'Publicacion local verificada; enlace al disco publico y reglas Apache preparadas. Sin imagenes de prueba.'
