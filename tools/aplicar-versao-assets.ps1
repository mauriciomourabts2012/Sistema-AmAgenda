[CmdletBinding()]
param(
    [ValidateSet('Apply', 'Check')]
    [string]$Mode = 'Apply',

    [string[]]$Files = @(
        'public/views/login-empresa.php',
        'public/views/agenda.html',
        'public/views/painel-administrativo/painel-administrativo.html'
    )
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$versionPath = Join-Path $repoRoot 'backend/_config/app-version.json'

if (-not (Test-Path -LiteralPath $versionPath -PathType Leaf)) {
    throw "Fonte central de versão não encontrada: $versionPath"
}

$versionConfig = Get-Content -Raw -LiteralPath $versionPath | ConvertFrom-Json
$appVersion = [string]$versionConfig.version

if ($appVersion -notmatch '^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$') {
    throw "Versão inválida em ${versionPath}: '$appVersion'"
}

# Leitura e gravacao sempre em UTF-8 SEM BOM, explicito e independente da versao do
# PowerShell. No Windows PowerShell 5.1, Get-Content sem -Encoding le arquivos sem BOM
# como ANSI (Windows-1252) e Set-Content -Encoding utf8 grava COM BOM; juntos corrompiam
# os acentos e quebravam o declare(strict_types=1) dos arquivos PHP.
# throwOnInvalidBytes = $true: um arquivo que nao seja UTF-8 valido interrompe o script
# em vez de ser regravado corrompido.
$utf8SemBom = New-Object System.Text.UTF8Encoding($false, $true)
$bomUtf8 = [byte[]](0xEF, 0xBB, 0xBF)

function Test-Utf8Bom {
    param([byte[]]$Bytes)
    return $Bytes.Length -ge 3 -and $Bytes[0] -eq $bomUtf8[0] -and $Bytes[1] -eq $bomUtf8[1] -and $Bytes[2] -eq $bomUtf8[2]
}

$assetPattern = [regex]::new(
    '(?<prefix><(?:script|link)\b[^>]*?\b(?:src|href)\s*=\s*(?<quote>["'']))(?<url>[^"'']+?\.(?:js|css))(?<query>\?[^"'']*)?\k<quote>',
    [System.Text.RegularExpressions.RegexOptions]::IgnoreCase
)

function Set-VersionQuery {
    param([System.Text.RegularExpressions.Match]$Match)

    $url = $Match.Groups['url'].Value
    if ($url -match '^(?:https?:)?//' -or $url.StartsWith('data:', [System.StringComparison]::OrdinalIgnoreCase)) {
        return $Match.Value
    }

    $query = $Match.Groups['query'].Value
    if ([string]::IsNullOrEmpty($query)) {
        $newQuery = "?v=$appVersion"
    } elseif ($query -match '(?i)([?&])v=[^&]*') {
        $newQuery = [regex]::Replace($query, '(?i)([?&])v=[^&]*', "`$1v=$appVersion", 1)
    } else {
        $newQuery = "$query&v=$appVersion"
    }

    return $Match.Groups['prefix'].Value + $url + $newQuery + $Match.Groups['quote'].Value
}

$pending = @()
$totalChanges = 0

foreach ($relativePath in $Files) {
    $filePath = Join-Path $repoRoot $relativePath
    if (-not (Test-Path -LiteralPath $filePath -PathType Leaf)) {
        throw "Arquivo de entrada não encontrado: $relativePath"
    }

    $bytes = [System.IO.File]::ReadAllBytes($filePath)
    $temBom = Test-Utf8Bom -Bytes $bytes
    $inicio = if ($temBom) { 3 } else { 0 }
    $original = $utf8SemBom.GetString($bytes, $inicio, $bytes.Length - $inicio)
    $changesInFile = 0
    $updated = $assetPattern.Replace($original, {
        param($match)
        $replacement = Set-VersionQuery -Match $match
        if ($replacement -cne $match.Value) {
            $script:changesInFile++
        }
        return $replacement
    })

    if ($updated -cne $original -or $temBom) {
        $pending += $relativePath
        $totalChanges += $changesInFile
        if ($Mode -eq 'Apply') {
            # Grava somente o texto (sem BOM); quebras de linha e conteudo sao preservados.
            [System.IO.File]::WriteAllText($filePath, $updated, $utf8SemBom)
        }
    }
}

if ($Mode -eq 'Check' -and $pending.Count -gt 0) {
    Write-Error ("Versão central não aplicada (ou BOM UTF-8 presente) em: " + ($pending -join ', '))
    exit 1
}

if ($Mode -eq 'Apply') {
    Write-Output "Versão $appVersion aplicada em $totalChanges referência(s), em $($pending.Count) arquivo(s)."
} else {
    Write-Output "Versão $appVersion confirmada nos $($Files.Count) arquivo(s) configurados."
}
