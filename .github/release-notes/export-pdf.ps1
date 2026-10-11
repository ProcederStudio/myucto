[CmdletBinding()]
param([switch] $Preview)

$ErrorActionPreference = 'Stop'
$enginePath = Join-Path (Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))) 'MD2PDF\export-pdf.ps1'
if (-not (Test-Path -LiteralPath $enginePath)) { throw "MD2PDF nenalezen: $enginePath" }
& $enginePath -Config (Join-Path $PSScriptRoot 'md2pdf.config.php') -Preview:$Preview
