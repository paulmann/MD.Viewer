#Requires -Version 7.6
#Requires -PSEdition Core
<#
.SYNOPSIS
    Behavioural probe for the two defects fixed in md.php 2.16.0.

.DESCRIPTION
    The project has no test suite. This probe is the only evidence that the two
    fixes actually work, so it is run before and after the change and must fail
    on the unfixed code.

    It never touches the repository: it copies md.php, js/ and css/ into a
    temporary directory, writes a .md.ini pointing BROWSE_DIR at a temporary
    browse root, serves that copy with the PHP built-in server, and issues real
    HTTP requests.

    Case 1 (hang):   a document whose inline code span contains "<" not followed
                     by "-". parseUniversalPattern() never advanced its index on
                     that character, so the render loop ran until the 30 s PHP
                     limit and answered HTTP 500.
    Case 2 (refuse): a document whose name or path carries non-ASCII letters (or
                     a space). The Layer 7 whitelist was ASCII-only, so the
                     request was refused with HTTP 200 and the error text.
    Case 3 (refuse): a document whose name carries an em dash (U+2014). The ASCII
                     hyphen was allowed, a dash was not, so the file browser
                     listed the row and the row could not be opened.

    Four negative cases are part of the probe: widening the whitelist must not
    turn into "everything is allowed".

    Exit code 0 = every case behaves; 1 = at least one case still misbehaves.
#>
[CmdletBinding()]
param(
    [string] $MdPhp = (Join-Path $PSScriptRoot '..\md.php'),
    [int]    $Port  = 8097
)

$ErrorActionPreference = 'Stop'

$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$php  = 'C:\tools\php85\php.exe'

if (-not (Test-Path -LiteralPath $php)) { throw "php not found at $php" }
if (-not (Test-Path -LiteralPath $MdPhp)) { throw "md.php not found at $MdPhp" }

$work = Join-Path ([IO.Path]::GetTempPath()) ('mdv-robust-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
$doc  = Join-Path $work 'docs'
$cyr  = Join-Path $doc 'SagaAI'

New-Item -ItemType Directory -Path $cyr -Force | Out-Null
Copy-Item -LiteralPath $MdPhp -Destination (Join-Path $work 'md.php')
Copy-Item -LiteralPath (Join-Path $root 'js')  -Destination $work -Recurse
Copy-Item -LiteralPath (Join-Path $root 'css') -Destination $work -Recurse

# BROWSE_DIR points at the temporary document tree, so the probe never scans the
# real workspace and never writes anywhere but its own directory.
Set-Content -LiteralPath (Join-Path $work '.md.ini') -Encoding utf8NoBOM -Value @"
BROWSE_DIR = $doc
DISABLE_UPLOAD = true
"@

# Case 1 fixture: a code span containing "<" not followed by "-". This is the
# shape of the real "--basetemp=<path>" span that hung the render.
$hangFile = Join-Path $doc 'hang.md'
Set-Content -LiteralPath $hangFile -Encoding utf8NoBOM -Value @'
# Hang probe

Body text before.

`--basetemp=<path>` is the parameter.

Body text after the span.

`<T> -- <U>` is a second shape of the same thing.
'@

# Case 2 fixtures: a Cyrillic file name, a spaced file name, and a file inside a
# Cyrillic directory (the directory component is validated too).
$cyrFile   = Join-Path $cyr 'отчёт-2026.md'
$spaceFile = Join-Path $doc 'Отчет по 17.08 - 21.08.md'
foreach ($f in @($cyrFile, $spaceFile)) {
    Set-Content -LiteralPath $f -Encoding utf8NoBOM -Value "# Probe`n`nCyrillic content.`n"
}

# Case 3 fixture: an em dash (U+2014) in the name. The hyphen is ASCII and was
# always allowed; a dash is not a hyphen, and this name was listed by the browser
# and refused when opened. Built from the code point so the fixture does not
# depend on the encoding of this file.
$emName = 'Промт v2 ' + [char]0x2014 + ' мастер-отчёт по 5 конфигура.md'
$emFile = Join-Path $doc $emName
Set-Content -LiteralPath $emFile -Encoding utf8NoBOM -Value "# Probe`n`nEm dash content.`n"

# A name that must STAY refused: it carries a character the whitelist has never
# allowed.
$percentFile = Join-Path $doc 'bad name %.md'
Set-Content -LiteralPath $percentFile -Encoding utf8NoBOM -Value "# Probe`n`nMust stay refused.`n"

$server  = $null
$results = [System.Collections.Generic.List[object]]::new()
$base    = "http://127.0.0.1:$Port/md.php"

function Test-Case {
    param([string] $Label, [string] $Url, [int] $MaxMs, [bool] $ExpectDenied)

    $sw = [Diagnostics.Stopwatch]::StartNew()
    try {
        $r = Invoke-WebRequest -Uri $Url -SkipHttpErrorCheck -TimeoutSec 120
        $sw.Stop()
        $denied = ([string] $r.Content) -match 'Path validation failed'
        $ok = ($r.StatusCode -eq 200) -and ($denied -eq $ExpectDenied) -and ($sw.ElapsedMilliseconds -le $MaxMs)
        $results.Add([pscustomobject]@{
            Case = $Label; Status = $r.StatusCode; Ms = $sw.ElapsedMilliseconds
            Denied = $denied; Ok = $ok; Note = ''
        })
    } catch {
        $sw.Stop()
        $results.Add([pscustomobject]@{
            Case = $Label; Status = 'EXCEPTION'; Ms = $sw.ElapsedMilliseconds
            Denied = $null; Ok = $false; Note = $_.Exception.Message
        })
    }
}

try {
    $server = Start-Process -FilePath $php -ArgumentList '-S', "127.0.0.1:$Port", '-t', $work `
        -PassThru -WindowStyle Hidden -RedirectStandardOutput (Join-Path $work 'srv.out') `
        -RedirectStandardError (Join-Path $work 'srv.err')
    Start-Sleep -Seconds 2
    if (-not (Get-Process -Id $server.Id -ErrorAction SilentlyContinue)) { throw 'the probe server did not start' }

    Test-Case -Label 'hanging inline code (< without -)' -MaxMs 10000 -ExpectDenied $false `
        -Url ($base + '?file=' + [uri]::EscapeDataString('hang.md'))
    Test-Case -Label 'cyrillic file name' -MaxMs 10000 -ExpectDenied $false `
        -Url ($base + '?file=' + [uri]::EscapeDataString('SagaAI/отчёт-2026.md'))
    Test-Case -Label 'em dash in the name' -MaxMs 10000 -ExpectDenied $false `
        -Url ($base + '?file=' + [uri]::EscapeDataString($emName))
    Test-Case -Label 'spaces in the name' -MaxMs 10000 -ExpectDenied $false `
        -Url ($base + '?file=' + [uri]::EscapeDataString('Отчет по 17.08 - 21.08.md'))
    Test-Case -Label 'percent sign must stay refused' -MaxMs 10000 -ExpectDenied $true `
        -Url ($base + '?file=' + [uri]::EscapeDataString('bad name %.md'))
    Test-Case -Label 'traversal must stay refused' -MaxMs 10000 -ExpectDenied $true `
        -Url ($base + '?file=' + [uri]::EscapeDataString('../secret.md'))
    Test-Case -Label 'non-md must stay refused' -MaxMs 10000 -ExpectDenied $true `
        -Url ($base + '?file=' + [uri]::EscapeDataString('docs/file.txt'))
} finally {
    if ($server) { Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue }
}

$results | Format-Table -AutoSize | Out-String -Width 200 | Write-Host

$failed = @($results | Where-Object { -not $_.Ok })
Write-Host ("cases: {0}, failures: {1}" -f $results.Count, $failed.Count)
foreach ($f in $failed) { Write-Host ("  FAILED: {0} (status {1}, denied {2})" -f $f.Case, $f.Status, $f.Denied) }

if (Test-Path -LiteralPath $work) { Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue }

if ($failed.Count -gt 0) { exit 1 }
exit 0
