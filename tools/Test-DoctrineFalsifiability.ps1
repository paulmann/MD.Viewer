#Requires -Version 7.6
#Requires -PSEdition Core

<#
.SYNOPSIS
    Proves that the rules in Invoke-ProjectDoctrine.ps1 can actually fail.

.DESCRIPTION
    A check that cannot fail is worse than no check: it makes the run green and
    proves nothing. This harness copies the project into a temporary directory,
    applies one deliberate mutation at a time, runs the doctrine checker against
    the copy, and reports whether the expected check responded.

    The project itself is never modified: every mutation lands in a throwaway
    copy under the system temporary directory, which is removed at the end.

    Why this file exists rather than a note in a report: it is what turned two
    plausible rules into deleted ones. A "version numbers must be unique" rule
    and a "every recognized key needs a panel entry" rule both failed against
    this repository on the day they were written, and both were removed for it.
    Without a reproducible harness, the next person re-adds them.

    When you add a rule to the checker, add a mutation here that breaks it. A
    rule with no mutation is an assumption.

.PARAMETER ProjectRoot
    Repository root to copy and mutate. Defaults to the parent directory of this
    script's directory, which is correct when the script sits in the project's
    tools directory.

.PARAMETER CheckerPath
    The doctrine checker to exercise. Defaults to the sibling script.

.PARAMETER KeepWork
    Leave the temporary copies in place for inspection. They are removed
    otherwise.

.EXAMPLE
    pwsh -NoLogo -NoProfile -NonInteractive -File .\Test-DoctrineFalsifiability.ps1
#>

[CmdletBinding()]
param(
    [string] $ProjectRoot = (Split-Path -Parent $PSScriptRoot),
    [string] $CheckerPath = (Join-Path $PSScriptRoot 'Invoke-ProjectDoctrine.ps1'),
    [switch] $KeepWork
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$source = [System.IO.Path]::GetFullPath($ProjectRoot)
if (-not (Test-Path -LiteralPath $source -PathType Container)) {
    throw "Project root not found: $source"
}
if (-not (Test-Path -LiteralPath $CheckerPath -PathType Leaf)) {
    throw "Checker not found: $CheckerPath"
}

# `pwsh -File` refuses any extension other than .ps1, so the checker is copied to
# a temporary .ps1 before it is executed.
$checker = Join-Path ([System.IO.Path]::GetTempPath()) ('doctrine-' + [guid]::NewGuid().ToString('N') + '.ps1')
Copy-Item -LiteralPath $CheckerPath -Destination $checker -Force

function New-ProjectCopy {
    param([Parameter(Mandatory)][string] $Destination)

    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    foreach ($item in Get-ChildItem -LiteralPath $source -Force) {
        # Service directories hold snapshots of earlier runs, including stale
        # copies of the checker itself. Copying them would test the wrong file.
        if ($item.Name -in @('.git', '.zwork', '.dev_agent')) { continue }
        Copy-Item -LiteralPath $item.FullName -Destination $Destination -Recurse -Force
    }
}

function Invoke-Checker {
    param([Parameter(Mandatory)][string] $Root)

    $out = Join-Path ([System.IO.Path]::GetTempPath()) ('mut-' + [guid]::NewGuid().ToString('N') + '.out')
    $errPath = $out + '.err'
    try {
        $proc = Start-Process -FilePath 'pwsh' -NoNewWindow -PassThru `
            -ArgumentList @('-NoLogo', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
                '-File', $checker, '-ProjectRoot', $Root) `
            -RedirectStandardOutput $out -RedirectStandardError $errPath
        if (-not $proc.WaitForExit(180000)) { $proc.Kill(); throw 'the checker timed out' }
        $errText = ''
        if (Test-Path -LiteralPath $errPath) { $errText = [System.IO.File]::ReadAllText($errPath) }
        return [pscustomobject]@{
            Exit = $proc.ExitCode
            Out  = [System.IO.File]::ReadAllText($out)
            Err  = $errText
        }
    }
    finally {
        foreach ($temp in @($out, $errPath)) {
            if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp -Force }
        }
    }
}

function Edit-Text {
    <#
        Apply one regex replacement and refuse to continue when nothing changed.
        A mutation that silently does not apply would be reported as a checker
        miss, sending the next person after the wrong file.
    #>
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][string] $Pattern,
        [Parameter(Mandatory)][string] $Replacement
    )

    $text = [System.IO.File]::ReadAllText($Path)
    $new = [regex]::Replace($text, $Pattern, $Replacement)
    if ($new -eq $text) { throw "mutation did not change the file: $Path / $Pattern" }
    [System.IO.File]::WriteAllText($Path, $new)
}

# ---------------------------------------------------------------- mutations

$mutations = @(
    @{
        Name  = 'version marker removed from md.php'
        Check = 'version marker'
        Want  = 'FAIL'
        Apply = {
            param($root)
            # Pinned to the marker as a shape, not to one version string. The
            # literal "2.13.0" used to sit here and stopped matching as soon as
            # md.php was versioned again, so the whole harness aborted on case 1
            # and reported nothing about the other eleven rules.
            Edit-Text -Path (Join-Path $root 'md.php') `
                -Pattern '(?m)^(\s*\*\s+)Version:\s*[\d.]+' -Replacement '${1}Changed: 0.0.0'
        }
    }
    @{
        Name  = 'a feature key is recognized but never wired'
        Check = 'feature toggles'
        Want  = 'FAIL'
        Apply = {
            param($root)
            Edit-Text -Path (Join-Path $root 'md.php') `
                -Pattern '(\$featureKeys = \[)' -Replacement "`$1`n        'UNWIRED_TOGGLE',"
        }
    }
    @{
        Name  = 'the path depth limit is widened'
        Check = 'numeric limits'
        Want  = 'FAIL'
        Apply = {
            param($root)
            Edit-Text -Path (Join-Path $root 'md.php') `
                -Pattern 'const MAX_SCAN_DEPTH = 3;' -Replacement 'const MAX_SCAN_DEPTH = 9;'
        }
    }
    @{
        Name  = 'a load-bearing function is edited'
        Check = 'load-bearing regions'
        Want  = 'FAIL'
        Apply = {
            param($root)
            Edit-Text -Path (Join-Path $root 'md.php') `
                -Pattern '\$depth > MAX_SCAN_DEPTH' -Replacement '$depth > MAX_SCAN_DEPTH + 1'
        }
    }
    @{
        Name  = 'a secret-shaped file appears'
        Check = 'secret-shaped'
        Want  = 'WARN'
        Apply = {
            param($root)
            # The rule matches on the file NAME, so the body deliberately holds no
            # credential-shaped assignment: this harness will not write one to disk
            # even as a fixture.
            $note = 'placeholder created by the falsifiability harness; the rule matches on the file name only' + "`n"
            [System.IO.File]::WriteAllText((Join-Path $root '.env'), $note)
        }
    }
    @{
        Name  = 'the doubled-slash redirect loses its GET guard'
        Check = 'doubled-slash'
        Want  = 'FAIL'
        Apply = {
            param($root)
            $guard = [regex]::Escape("(`$_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'")
            Edit-Text -Path (Join-Path $root 'md.php') -Pattern $guard -Replacement 'true'
        }
    }
    @{
        Name  = 'a PHP entry point stops parsing'
        Check = 'PHP entry points parse'
        Want  = 'FAIL'
        Apply = {
            param($root)
            $path = Join-Path $root 'updater.php'
            $text = [System.IO.File]::ReadAllText($path) + "`nfunction broken( {`n"
            [System.IO.File]::WriteAllText($path, $text)
        }
    }
    @{
        Name  = 'the user-visible stylesheet goes stale'
        Check = 'asset URLs'
        Want  = 'FAIL'
        Apply = {
            param($root)
            Edit-Text -Path (Join-Path $root 'md.php') `
                -Pattern "assetVersion\('css/md/settings\.css'\)" -Replacement "assetVersion('css/md/missing.css')"
        }
    }
    @{
        Name  = 'a configuration key is claimed by both scopes'
        Check = 'stay disjoint'
        Want  = 'FAIL'
        Apply = {
            param($root)
            Edit-Text -Path (Join-Path $root 'md.php') `
                -Pattern "const MDV_PER_DIR_KEYS = \['DISABLE_UPLOAD'" `
                -Replacement "const MDV_PER_DIR_KEYS = ['BROWSE_DIR', 'DISABLE_UPLOAD'"
        }
    }
    @{
        Name  = 'an unrecognized key reaches the browser configuration'
        Check = 'feature toggles'
        Want  = 'FAIL'
        Apply = {
            param($root)
            Edit-Text -Path (Join-Path $root 'md.php') `
                -Pattern "('updaterUrl'\s*=>\s*'updater\.php',)" `
                -Replacement "'mysterySetting'  => MAX_SCAN_DEPTH,`n            `$1"
        }
    }
    @{
        Name  = 'an unescaped echo is added to the writer'
        Check = 'unescaped document HTML'
        Want  = 'FAIL'
        Apply = {
            param($root)
            $path = Join-Path $root 'updater.php'
            $text = [System.IO.File]::ReadAllText($path) + "`n<?= `$someValue ?>`n"
            [System.IO.File]::WriteAllText($path, $text)
        }
    }
    @{
        Name  = 'the README version drift is repaired'
        Check = 'README component versions'
        Want  = 'PASS'
        Apply = {
            param($root)
            # "File" is the path the checker reads; "Key" is the name that appears
            # in a row. They differ exactly where the real latent defect lived:
            # the tracked-files block writes the full path (js/md/md.js) while the
            # layout diagram writes the leaf (md.js), and the old mutation knew
            # only the leaf, so it aborted the whole harness instead of mutating.
            $pairs = @(
                @{ File = 'md.php';            Key = 'md.php';          Now = '2.16.0' }
                @{ File = 'updater.php';       Key = 'updater.php';     Now = '3.13.0' }
                @{ File = 'js/md/md.js';       Key = 'js/md/md.js';     Now = '2.6.0' }
                @{ File = 'js/md/settings.js'; Key = 'js/md/settings.js'; Now = '2.10.1' }
                @{ File = 'js/md/upload.js';   Key = 'js/md/upload.js'; Now = '2.9.1' }
            )
            $path = Join-Path $root 'README.md'
            $text = [System.IO.File]::ReadAllText($path)
            foreach ($pair in $pairs) {
                $keyEsc = [regex]::Escape($pair.Key)
                # The tracked-files block: name, then anything, then vX.
                $claim = [regex]::Match($text, "^$keyEsc[^\r\n]*?v(\d[\w.\-]*)", 'Multiline')
                if ($claim.Success) {
                    $was = [regex]::Escape($claim.Groups[1].Value)
                    $text = [regex]::Replace($text, "($keyEsc[^\r\n]*?)v$was", "`$1v$($pair.Now)")
                }
                # The badge, whose payload is the full path.
                $badge = [regex]::Match($text, 'badge/' + [regex]::Escape($pair.File) + '-v(\d[\w.\-]*)')
                if ($badge.Success) {
                    $text = $text.Replace($badge.Value, 'badge/' + $pair.File + '-v' + $pair.Now)
                }
                # The layout diagram, which carries the leaf name after a '#'.
                $leaf = [regex]::Escape(($pair.File -split '/')[-1])
                $diag = [regex]::Match($text, "^\S*$leaf\s+#+\s*v(\d[\w.\-]*)", 'Multiline')
                if ($diag.Success) {
                    $text = $text.Replace($diag.Value, $diag.Value.Replace('v' + $diag.Groups[1].Value, 'v' + $pair.Now))
                }
                if (-not ($claim.Success -or $badge.Success -or $diag.Success)) {
                    throw "README states no version for $($pair.File)"
                }
            }
            [System.IO.File]::WriteAllText($path, $text)
        }
    }
)

# --------------------------------------------------------------- baseline

$work = Join-Path ([System.IO.Path]::GetTempPath()) ('doctrine-mut-' + [guid]::NewGuid().ToString('N'))
New-ProjectCopy -Destination $work

$baseline = Invoke-Checker -Root $work
$summary = [regex]::Match($baseline.Out, 'checks:\s*\d+, errors:\s*\d+, warnings:\s*\d+')
Write-Host 'doctrine falsifiability'
Write-Host "repository: $source"
Write-Host ''
Write-Host "baseline on the unmodified copy: exit=$($baseline.Exit)  $($summary.Value)"
if ($baseline.Exit -ne 0) { Write-Host $baseline.Out }
Write-Host ''

# --------------------------------------------------------------- run

$results = [System.Collections.Generic.List[string]]::new()

$index = 0
foreach ($mutation in $mutations) {
    $index++
    $caseDir = Join-Path $work ("case$index")
    New-ProjectCopy -Destination $caseDir
    & $mutation.Apply $caseDir

    $run = Invoke-Checker -Root $caseDir

    $seen = '(check not found)'
    foreach ($verdict in [regex]::Matches($run.Out, '^\[(PASS|FAIL|WARN|N/A)\]\s+(.+?)\s{2,}\(examined', 'Multiline')) {
        if ($verdict.Groups[2].Value -match [regex]::Escape($mutation.Check)) {
            $seen = $verdict.Groups[1].Value
            break
        }
    }

    $tag = 'MISS'
    if ($seen -eq $mutation.Want) { $tag = 'OK  ' }
    $results.Add("$tag  $($mutation.Name)  ->  wanted $($mutation.Want), saw $seen")
    if ($tag -eq 'MISS') {
        $results.Add("        exit=$($run.Exit)  $([regex]::Match($run.Out, 'checks:.*').Value)")
    }
}

foreach ($line in $results) { Write-Host $line }

$misses = @($results | Where-Object { $_.StartsWith('MISS') })
Write-Host ''
Write-Host "cases: $($mutations.Count), misses: $($misses.Count)"

if (-not $KeepWork) {
    Remove-Item -LiteralPath $work -Recurse -Force
}
else {
    Write-Host "working copies kept: $work"
}
Remove-Item -LiteralPath $checker -Force

if ($misses.Count -gt 0) {
    Write-Host 'Result: FAIL - a rule did not respond to a mutation that should break it.' -ForegroundColor Red
    exit 1
}

Write-Host 'Result: PASS - every rule failed when its rule was broken.' -ForegroundColor Green
exit 0
