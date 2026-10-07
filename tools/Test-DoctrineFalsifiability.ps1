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
            #
            # The version each claim is corrected TO is read from the file's own
            # marker rather than pinned here. A hard-coded list went stale twice:
            # once when a component was versioned again (the harness then aborted),
            # and once when the checker's subject list grew past the list below, so
            # the mutation repaired some rows and left the rest drifting, which the
            # harness reported as a miss. Reading the marker makes the mutation
            # correct for whatever the tree currently versions.
            $readmePath = Join-Path $root 'README.md'
            $markerPattern = '\*\s+Version:\s*(\d[\w.\-]+)'
            $rowPattern = '(?m)^([A-Za-z0-9_./]+\.(?:php|js|css|md))\s+v(\d[\w.\-]+)\s*$'
            $badgePattern = 'badge/([A-Za-z0-9_.]+)-v(\d[\w.\-]+)-'

            # The mutation creates the drift it then repairs. Repairing drift
            # that merely happened to be present made the case pass for the wrong
            # reason: once the README was brought into line, the old body matched
            # nothing, changed nothing, and the rule still reported PASS - a green
            # check that verified nothing. Writing a wrong version first means the
            # repair has something to do in every tree state.
            Edit-Text -Path $readmePath `
                -Pattern '(?m)^(md\.php\s+)v[\d.\-]+' -Replacement '${1}v0.0.1'

            # One correction per pass, then rescan: a replacement shifts every
            # later match offset, so the scan is re-run against the new text.
            for ($pass = 0; $pass -lt 20; $pass++) {
                $text = [System.IO.File]::ReadAllText($readmePath)
                $fixed = $false

                foreach ($pattern in @($rowPattern, $badgePattern)) {
                    foreach ($claim in [regex]::Matches($text, $pattern)) {
                        $file = $claim.Groups[1].Value
                        $claimed = $claim.Groups[2].Value
                        $target = Join-Path $root ($file -replace '/', '\')
                        if (-not (Test-Path -LiteralPath $target -PathType Leaf)) {
                            throw "README names a file that is not in the package: $file"
                        }
                        $head = [System.IO.File]::ReadAllText($target)
                        $marker = [regex]::Match($head, $markerPattern)
                        if (-not $marker.Success) {
                            throw "no version marker to correct against: $file"
                        }
                        if ($marker.Groups[1].Value -eq $claimed) { continue }
                        # Replace this one claim only, at the offset the match
                        # reports, so an identical version elsewhere is untouched.
                        # The concatenation stays on one line on purpose: a
                        # continuation line beginning with '+' is not part of the
                        # expression in PowerShell, so it silently truncated the
                        # text and the harness reported a PASS it had not earned.
                        $corrected = $claim.Value.Replace('v' + $claimed, 'v' + $marker.Groups[1].Value)
                        $text = $text.Substring(0, $claim.Index) + $corrected + $text.Substring($claim.Index + $claim.Length)
                        $fixed = $true
                        break
                    }
                    if ($fixed) { break }
                }

                if (-not $fixed) { break }
                [System.IO.File]::WriteAllText($readmePath, $text)
            }

            # The repair must have reached every claim; otherwise the rule would
            # be reported green on a tree that is still drifting.
            $final = [System.IO.File]::ReadAllText($readmePath)
            $still = [regex]::Match($final, '(?m)^md\.php\s+v([\d.]+)')
            if (-not $still.Success -or $still.Groups[1].Value -eq '0.0.1') {
                throw 'the repair did not correct the drift it introduced'
            }
        }
    }
    @{
        Name  = 'a sort option the table does not offer'
        Check = 'default file sort'
        Want  = 'FAIL'
        Apply = {
            param($root)
            # The panel offers a field the table cannot order by. The panel would
            # store it, the reader would refuse it as unknown, and the setting
            # would do nothing - with no error on either side.
            Edit-Text -Path (Join-Path $root 'md.php') `
                -Pattern '(<option value="size">Size</option>)' `
                -Replacement "`$1`n                        <option value=`"author`">Author</option>"
        }
    }
    @{
        Name  = 'the storage key is renamed on one side only'
        Check = 'default file sort'
        Want  = 'FAIL'
        Apply = {
            param($root)
            # The two scripts share no state, so the key name IS the interface.
            # Renaming it in the reader alone leaves the panel writing a value
            # nobody reads: both files still parse, both look wired.
            Edit-Text -Path (Join-Path $root 'js/md/md.js') `
                -Pattern "readSortPref\('mdv_sort'\)" -Replacement "readSortPref('mdv_sortkey')"
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
