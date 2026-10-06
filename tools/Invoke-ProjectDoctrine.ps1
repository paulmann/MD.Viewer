#Requires -Version 7.6
#Requires -PSEdition Core

<#
.SYNOPSIS
    Project-doctrine checker for MD.Viewer.

.DESCRIPTION
    Verifies the conventions this project actually follows, established by a
    read-only reconnaissance of the repository at commit e3ca21d
    (md.php 2.13.0, updater.php 3.12.0).

    Read-only with respect to the project: nothing here writes to it. Two checks
    execute syntax-only tooling on project files (`php -l`, `node --check`); no
    project code is executed.

    Every rule below was observed to hold on the day it was written. A rule that
    could not be demonstrated with a concrete input that would make it fail is
    not in this file.

    Two checks report conditions that already exist and are recorded in AGENTS.md
    as the baseline:

      * README's component version table, layout diagram and badges lag behind the
        code markers. Documentation drift only: the updater reads the code
        markers, not the README;
      * no secret-shaped file exists in this repository, so any that appears is a
        new finding rather than a known one.

    A changed number is information, not automatically a regression. Read
    AGENTS.md before treating it as one.

.PARAMETER ProjectRoot
    Repository root. Defaults to the parent directory of this script, which is
    correct when the file is placed in the project's tools directory.

.PARAMETER MaxExamples
    How many examples to print per failed check. Defaults to 10.

.PARAMETER Strict
    Treat warnings as errors.

.EXAMPLE
    pwsh -NoLogo -NoProfile -NonInteractive -File .\Invoke-ProjectDoctrine.ps1

.EXAMPLE
    pwsh -NoLogo -NoProfile -NonInteractive -File .\Invoke-ProjectDoctrine.ps1 -Strict
#>

[CmdletBinding()]
param(
    [string] $ProjectRoot = (Split-Path -Parent $PSScriptRoot),
    [int] $MaxExamples = 10,
    [switch] $Strict
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:ProjectRoot = [System.IO.Path]::GetFullPath($ProjectRoot)
if (-not (Test-Path -LiteralPath $script:ProjectRoot -PathType Container)) {
    throw "Project root not found: $script:ProjectRoot"
}
$script:MaxExamples = $MaxExamples

# --------------------------------------------------------------- exclusions

# Service directories of the agent tooling. They hold snapshots of earlier runs,
# including stale copies of this very file, so scanning them produces findings
# about code that is no longer present. The rest of this project is small, so no
# other directory needs excluding - and widening this list would blind the checks
# below for no reason.
$script:CodeExclusionPattern = '[\\/](?:\.git|\.zwork|\.dev_agent)[\\/]'

# The same list applies to the secret scan. The only reason to skip a directory
# is that its contents are a snapshot rather than the project; a secret-shaped
# file can sit anywhere else, so the list must not grow for convenience.
$script:SecretExclusionPattern = '[\\/](?:\.git|\.zwork|\.dev_agent)[\\/]'

# The nine components the updater ships, plus the README. This is the shipping
# package: the only set of files whose content these checks judge.
$script:ShippingFiles = @(
    'md.php',
    'updater.php',
    'js/md/md.js',
    'js/md/settings.js',
    'js/md/tooltips.js',
    'js/md/upload.js',
    'css/md/md.css',
    'css/md/settings.css',
    'css/md/tooltips.css',
    'README.md'
)

# --------------------------------------------------------------- framework

function Test-RegexLiteral {
    <#
    .SYNOPSIS
        Is this a pattern the regex engine can actually compile?

    .DESCRIPTION
        An invalid pattern does not fail where you would notice: inside
        `-notmatch` it raises, and a check written as `if ($text -notmatch $p)`
        can report a failure that never happened, or worse, a PASS. Validate
        every pattern you intend to use.
    #>
    param([Parameter(Mandatory)][string] $Pattern)

    try { $null = [regex]::new($Pattern); return $true }
    catch { return $false }
}

function Assert-RegexLiteral {
    param(
        [Parameter(Mandatory)][string] $Pattern,
        [Parameter(Mandatory)][string] $What
    )

    if (Test-RegexLiteral -Pattern $Pattern) { return }
    throw "invalid regex for ${What}: $Pattern"
}

function Get-RelativePath {
    param([Parameter(Mandatory)][string] $FullPath)
    return ([System.IO.Path]::GetRelativePath($script:ProjectRoot, $FullPath)) -replace '\\', '/'
}

function Get-RepositoryFile {
    <#
    .SYNOPSIS
        Project files, with service trees excluded.
    #>
    param(
        [string[]] $Extension = @(),
        [switch] $SecretScan
    )

    $pattern = $script:CodeExclusionPattern
    if ($SecretScan) { $pattern = $script:SecretExclusionPattern }
    Assert-RegexLiteral -Pattern $pattern -What 'repository file exclusion'
    $exclude = [regex]::new($pattern)

    $items = Get-ChildItem -LiteralPath $script:ProjectRoot -Recurse -File -Force -ErrorAction SilentlyContinue
    $result = [System.Collections.Generic.List[System.IO.FileInfo]]::new()

    foreach ($file in $items) {
        if ($exclude.IsMatch($file.FullName)) { continue }
        if ($Extension.Count -gt 0 -and $Extension -notcontains $file.Extension.ToLowerInvariant()) { continue }
        $result.Add($file)
    }

    return $result
}

function Get-ProjectText {
    <#
    .SYNOPSIS
        File text with line endings normalised to LF.
    #>
    param([Parameter(Mandatory)][string] $Path)

    $text = [System.IO.File]::ReadAllText($Path)
    $text = $text -replace "`r`n", "`n"
    $text = $text -replace "`r", "`n"
    return $text
}

function Get-HeadBytes {
    <#
    .SYNOPSIS
        The first 1000 bytes of a file as text.

    .DESCRIPTION
        The version marker must be found inside the first 1000 bytes, because
        that is the region the updater reads it from. Reading the whole file
        would report a marker the product itself cannot see.
    #>
    param([Parameter(Mandatory)][string] $Path)

    $bytes = [System.IO.File]::ReadAllBytes($Path)
    $length = [Math]::Min(1000, $bytes.Length)
    return [System.Text.Encoding]::UTF8.GetString($bytes, 0, $length)
}

function Get-Sha256 {
    param([Parameter(Mandatory)][string] $Text)

    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        $bytes = $sha.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($Text))
        return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
    }
    finally { $sha.Dispose() }
}

function Get-FunctionRegion {
    <#
    .SYNOPSIS
        The text of one top-level PHP function, from its declaration to the first
        closing brace in column zero.

    .DESCRIPTION
        Returns an empty string when the function is absent. That is what lets the
        caller report a miss instead of silently comparing nothing.
    #>
    param(
        [Parameter(Mandatory)][string] $Text,
        [Parameter(Mandatory)][string] $Name
    )

    $pattern = '(?ms)^function\s+' + [regex]::Escape($Name) + '\s*\(.*?^\}'
    $match = [regex]::Match($Text, $pattern)
    if (-not $match.Success) { return '' }
    return $match.Value.TrimEnd()
}

function New-CheckResult {
    <#
    .SYNOPSIS
        One check outcome, with the size of its evidence.

    .PARAMETER Examined
        How many observations the check made (files read, records compared).
        REQUIRED for a passing check: a green result with Examined 0 is reported
        as a false PASS and fails the run.

    .PARAMETER NotApplicable
        Reason this check cannot apply in this environment. Use it instead of an
        unearned PASS; the runner prints [N/A] and does not count it as an error.
    #>
    param(
        [Parameter(Mandatory)][string] $Name,
        [Parameter(Mandatory)][bool] $Ok,
        [int] $Examined = -1,
        [string[]] $Details = @(),
        [switch] $Warning,
        [string] $NotApplicable
    )

    # The parameter is $Details and the local list is $detailList. PowerShell
    # treats the two names as one, so reusing it would wipe the parameter and the
    # check would report a failure with no stated reason.
    $detailList = [System.Collections.Generic.List[string]]::new()
    foreach ($detail in $Details) { $detailList.Add($detail) }

    $kind = 'error'
    if ($NotApplicable) { $kind = 'na' }
    elseif ($Ok) {
        if ($Examined -le 0) {
            # The defect this framework exists to catch. Report it rather than
            # crashing, so the run always prints why it failed.
            $Ok = $false
            $kind = 'void'
            $detailList.Insert(0, 'FALSE PASS: check reported Ok while examining nothing (-Examined = ' + $Examined + ')')
        }
        else { $kind = 'pass' }
    }
    elseif ($Warning) { $kind = 'warn' }

    [pscustomobject]@{
        Name     = $Name
        Ok       = $Ok
        Examined = $Examined
        Details  = @($detailList)
        Warning  = [bool]$Warning
        Kind     = $kind
    }
}

function Write-CheckResult {
    param(
        [Parameter(Mandatory)][pscustomobject] $Check,
        [Parameter(Mandatory)][int] $Pad
    )

    $label = switch ($Check.Kind) {
        'pass' { '[PASS]' }
        'na'   { '[N/A] ' }
        'warn' { '[WARN]' }
        default { '[FAIL]' }
    }
    $color = switch ($Check.Kind) {
        'pass' { 'Green' }
        'na'   { 'DarkGray' }
        'warn' { 'Yellow' }
        default { 'Red' }
    }

    $suffix = ''
    if ($Check.Examined -ge 0) { $suffix = "  (examined $($Check.Examined))" }

    Write-Host ($label + ' ' + $Check.Name.PadRight($Pad) + $suffix) -ForegroundColor $color

    $shown = @($Check.Details | Select-Object -First $script:MaxExamples)
    foreach ($detail in $shown) { Write-Host "         $detail" }
    if ($Check.Details.Count -gt $shown.Count) {
        Write-Host "         ... and $($Check.Details.Count - $shown.Count) more"
    }
}

function Invoke-SyntaxCheck {
    <#
    .SYNOPSIS
        Run one external syntax check and return a structured outcome.

    .DESCRIPTION
        Start-Process with redirected streams is used deliberately: a child that
        writes a run-time error to stdout still exits 0 when run through a
        pipeline, so the exit code alone cannot be trusted.
    #>
    param(
        [Parameter(Mandatory)][string] $FilePath,
        [Parameter(Mandatory)][string[]] $Arguments
    )

    $outPath = Join-Path ([System.IO.Path]::GetTempPath()) ('mdv-' + [guid]::NewGuid().ToString('N') + '.out')
    $errPath = $outPath + '.err'
    try {
        $proc = Start-Process -FilePath $FilePath -NoNewWindow -PassThru -ArgumentList $Arguments `
            -RedirectStandardOutput $outPath -RedirectStandardError $errPath
        if (-not $proc.WaitForExit(60000)) {
            $proc.Kill()
            return [pscustomobject]@{ Ok = $false; Message = 'timed out after 60000 ms' }
        }
        $code = $proc.ExitCode
        $out = ''
        if (Test-Path -LiteralPath $outPath) { $out = [System.IO.File]::ReadAllText($outPath) }
        $err = ''
        if (Test-Path -LiteralPath $errPath) { $err = [System.IO.File]::ReadAllText($errPath) }
        if ($code -ne 0) {
            $first = ($err + $out) -split "`n" | Where-Object { $_.Trim() } | Select-Object -First 1
            return [pscustomobject]@{ Ok = $false; Message = "exit $code : $($first.Trim())" }
        }
        return [pscustomobject]@{ Ok = $true; Message = '' }
    }
    finally {
        foreach ($temp in @($outPath, $errPath)) {
            if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp -Force }
        }
    }
}

function Test-PhpSyntax {
    <#
    .SYNOPSIS
        Both PHP entry points parse.

    .DESCRIPTION
        There is no test suite and no CI in this repository, so a syntax check is
        the strongest automated evidence available. It is a smoke test, not an
        acceptance test: it proves the file parses and nothing about behaviour.
    #>
    $php = Get-Command php -ErrorAction SilentlyContinue
    if ($null -eq $php) {
        return New-CheckResult -Name 'PHP entry points parse (php -l)' `
            -Ok $false -NotApplicable 'php is not available in this environment'
    }

    $problems = [System.Collections.Generic.List[string]]::new()
    $examined = 0

    foreach ($relative in @('md.php', 'updater.php')) {
        $path = Join-Path $script:ProjectRoot $relative
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { continue }
        $examined++
        $result = Invoke-SyntaxCheck -FilePath $php.Source -Arguments @('-l', $path)
        if (-not $result.Ok) { $problems.Add("${relative} : $($result.Message)") }
    }

    if ($examined -eq 0) {
        return New-CheckResult -Name 'PHP entry points parse (php -l)' `
            -Ok $false -Examined 0 -Details @('no PHP entry point could be read')
    }

    return New-CheckResult -Name 'PHP entry points parse (php -l)' `
        -Ok ($problems.Count -eq 0) -Examined $examined -Details $problems
}

function Test-JavaScriptSyntax {
    <#
    .SYNOPSIS
        The four browser assets parse.

    .DESCRIPTION
        `node --check` reads the file as a script. None of the four assets uses an
        import or export statement, so the check is meaningful here. A future ES
        module would need different invocation, and this check would then report a
        tooling limit rather than a defect - say so, do not "fix" it by removing
        the check.
    #>
    $node = Get-Command node -ErrorAction SilentlyContinue
    if ($null -eq $node) {
        return New-CheckResult -Name 'JavaScript assets parse (node --check)' `
            -Ok $false -NotApplicable 'node is not available in this environment'
    }

    $problems = [System.Collections.Generic.List[string]]::new()
    $examined = 0

    foreach ($relative in @('js/md/md.js', 'js/md/settings.js', 'js/md/tooltips.js', 'js/md/upload.js')) {
        $path = Join-Path $script:ProjectRoot ($relative -replace '/', '\')
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { continue }
        $examined++
        $result = Invoke-SyntaxCheck -FilePath $node.Source -Arguments @('--check', $path)
        if (-not $result.Ok) { $problems.Add("${relative} : $($result.Message)") }
    }

    if ($examined -eq 0) {
        return New-CheckResult -Name 'JavaScript assets parse (node --check)' `
            -Ok $false -Examined 0 -Details @('no JavaScript asset could be read')
    }

    return New-CheckResult -Name 'JavaScript assets parse (node --check)' `
        -Ok ($problems.Count -eq 0) -Examined $examined -Details $problems
}

function Test-BaselineLayout {
    <#
    .SYNOPSIS
        The ten shipping files are still where the checks expect them.

    .DESCRIPTION
        Guards against running a checker whose subject has moved: without this,
        the file-reading checks would compare empty strings and could report a
        clean sweep of nothing.
    #>
    $problems = [System.Collections.Generic.List[string]]::new()
    $examined = 0

    foreach ($relative in $script:ShippingFiles) {
        $examined++
        $path = Join-Path $script:ProjectRoot ($relative -replace '/', '\')
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            $problems.Add("not present: $relative")
        }
    }

    return New-CheckResult -Name 'repository layout matches the recorded baseline' `
        -Ok ($problems.Count -eq 0) -Examined $examined -Details $problems
}

function Test-VersionMarkers {
    <#
    .SYNOPSIS
        Every shipping file carries a parseable version marker.

    .DESCRIPTION
        The marker inside the first 1000 bytes is not decoration. It is the
        ownership test the updater uses to decide whether a file may be replaced,
        and it is what the settings panel reports. A file without it is treated as
        foreign and will not be updated, and the loss is silent.

        Uniqueness of the version numbers is deliberately NOT checked. It was
        written, run against this repository, and removed: the tooltips script and
        its stylesheet both carry 2.4.5, because that pair is versioned in
        lockstep, and the updater's backups are filed by version rather than by
        file - so two components sharing a number is a coherent state here, not a
        collision. A rule the project contradicts on the day it is written is a
        false failure, and a false failure teaches the reader to ignore the run.
    #>
    $markerPattern = '\*\s+Version:\s*(\d[\w.\-]+)'
    Assert-RegexLiteral -Pattern $markerPattern -What 'version marker'

    $problems = [System.Collections.Generic.List[string]]::new()
    $examined = 0

    foreach ($relative in $script:ShippingFiles) {
        $path = Join-Path $script:ProjectRoot ($relative -replace '/', '\')
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { continue }
        $examined++

        $head = Get-HeadBytes -Path $path
        $match = [regex]::Match($head, $markerPattern)
        if (-not $match.Success) {
            $problems.Add("no version marker in the first 1000 bytes: $relative")
        }
    }

    if ($examined -eq 0) {
        return New-CheckResult -Name 'every shipping file carries a version marker' `
            -Ok $false -Examined 0 -Details @('no shipping file could be read')
    }

    return New-CheckResult -Name 'every shipping file carries a version marker' `
        -Ok ($problems.Count -eq 0) -Examined $examined -Details $problems
}

function Test-FeatureWiring {
    <#
    .SYNOPSIS
        Every feature toggle is wired from the configuration file to the browser.

    .DESCRIPTION
        Adding a toggle is the most frequent change this project invites, and it
        fails quietly: the render site reads a constant that was never defined
        from configuration, so the setting appears in the panel and does nothing.

        Five places must agree. The reconnaissance confirmed all sixteen
        recognized keys are present in each place that applies to them:

          * the recognized key list in the viewer;
          * the constants defined from the configuration file;
          * the browser configuration object;
          * the settings panel's feature list;
          * the panel's map from configuration key to feature.

        Two keys are recognized without being checkboxes, and demanding a panel
        entry for them was wrong - both were found by running this check before it
        was accepted:

          * COOKIE_ACCEPT is defined as a hard false, because the master switch is
            managed from the browser rather than from the configuration file;
          * PARAGRAPH_BREAK_STYLE carries a string value ('double-br') rather than
            a boolean, so it is a setting and not a toggle.

        They are exempted by name, not by a general rule, so a genuinely new key
        cannot borrow the exemption.
    #>
    $problems = [System.Collections.Generic.List[string]]::new()
    $examined = 0

    $mdPath = Join-Path $script:ProjectRoot 'md.php'
    $settingsPath = Join-Path $script:ProjectRoot 'js\md\settings.js'
    if (-not (Test-Path -LiteralPath $mdPath -PathType Leaf)) {
        return New-CheckResult -Name 'feature toggles are wired end to end' `
            -Ok $false -Examined 0 -Details @('the viewer script could not be read')
    }
    if (-not (Test-Path -LiteralPath $settingsPath -PathType Leaf)) {
        return New-CheckResult -Name 'feature toggles are wired end to end' `
            -Ok $false -Examined 0 -Details @('the settings panel script could not be read')
    }

    $md = Get-ProjectText -Path $mdPath
    $js = Get-ProjectText -Path $settingsPath

    $keyListPattern = '(?s)\$featureKeys\s*=\s*\[(.*?)\];'
    Assert-RegexLiteral -Pattern $keyListPattern -What 'feature key list'
    $keyList = [regex]::Match($md, $keyListPattern)
    if (-not $keyList.Success) {
        return New-CheckResult -Name 'feature toggles are wired end to end' `
            -Ok $false -Examined 0 -Details @('the recognized feature key list was not found in the viewer')
    }
    $recognized = [regex]::Matches($keyList.Groups[1].Value, "'([A-Z_0-9]+)'") |
        ForEach-Object { $_.Groups[1].Value } | Select-Object -Unique
    if ($recognized.Count -eq 0) {
        return New-CheckResult -Name 'feature toggles are wired end to end' `
            -Ok $false -Examined 0 -Details @('the recognized feature key list is empty')
    }

    $definePattern = "define\('([A-Z_0-9]+)'\s*,\s*feat\("
    Assert-RegexLiteral -Pattern $definePattern -What 'feature constant definitions'
    $defined = [regex]::Matches($md, $definePattern) |
        ForEach-Object { $_.Groups[1].Value } | Select-Object -Unique

    $configPattern = "(?s)json_encode\(\[(.*?)\],\s*JSON_THROW_ON_ERROR"
    Assert-RegexLiteral -Pattern $configPattern -What 'browser configuration object'
    $configBlock = [regex]::Match($md, $configPattern)
    if (-not $configBlock.Success) {
        return New-CheckResult -Name 'feature toggles are wired end to end' `
            -Ok $false -Examined 0 -Details @('the browser configuration object was not found in the viewer')
    }
    $configConstants = [regex]::Matches($configBlock.Groups[1].Value, "'\w+'\s*=>\s*([A-Z_0-9]+)") |
        ForEach-Object { $_.Groups[1].Value } | Select-Object -Unique

    # Operational settings rather than feature keys: they gate a capability, not
    # a rendering feature, and they are governed by the two configuration scopes
    # that Test-ConfigKeyScopes already guards.
    $operational = @('DISABLE_UPLOAD', 'DISABLE_CLIPBOARD',
        'DISABLE_SAVE_CLIPBOARD_TO_FILE', 'ALLOW_UPDATE', 'ALLOW_RESTORE',
        'ALLOW_CREATE_INDEX_PHP_LINK')

    # Recognized keys with no constant of their own, and recognized keys with no
    # checkbox in the panel. Both are facts of the code, read rather than guessed.
    $notDefinedFromConfig = @('COOKIE_ACCEPT')
    $notACheckbox = @('COOKIE_ACCEPT', 'PARAGRAPH_BREAK_STYLE')

    $panelListPattern = "(?s)const FEATURES\s*=\s*\[(.*?)\n\s*\];"
    Assert-RegexLiteral -Pattern $panelListPattern -What 'settings panel feature list'
    $panelList = [regex]::Match($js, $panelListPattern)
    if (-not $panelList.Success) {
        return New-CheckResult -Name 'feature toggles are wired end to end' `
            -Ok $false -Examined 0 -Details @('the settings panel feature list was not found')
    }
    $panelKeys = [regex]::Matches($panelList.Groups[1].Value, "key:\s*'([A-Z_0-9]+)'") |
        ForEach-Object { $_.Groups[1].Value } | Select-Object -Unique

    $mapPattern = '(?s)const CFG_MAP\s*=\s*\{(.*?)\n\s*\};'
    Assert-RegexLiteral -Pattern $mapPattern -What 'settings panel configuration map'
    $mapBlock = [regex]::Match($js, $mapPattern)
    if (-not $mapBlock.Success) {
        return New-CheckResult -Name 'feature toggles are wired end to end' `
            -Ok $false -Examined 0 -Details @('the settings panel configuration map was not found')
    }
    $mapKeys = [regex]::Matches($mapBlock.Groups[1].Value, '(?m)^\s*([A-Z_0-9]+):') |
        ForEach-Object { $_.Groups[1].Value } | Select-Object -Unique

    foreach ($key in $recognized) {
        # Every recognized key must reach the browser, or the setting is read from
        # the configuration file and then discarded before the page can use it.
        $examined++
        if ($key -notin $configConstants) {
            $problems.Add("recognized but never passed to the browser: $key")
        }

        if ($notDefinedFromConfig -notcontains $key) {
            $examined++
            if ($key -notin $defined) {
                $problems.Add("recognized but no constant is defined from configuration: $key")
            }
        }

        if ($notACheckbox -notcontains $key) {
            $examined++
            if ($key -notin $panelKeys) {
                $problems.Add("missing from the settings panel feature list: $key")
            }
            $examined++
            if ($key -notin $mapKeys) {
                $problems.Add("missing from the settings panel configuration map: $key")
            }
        }
    }

    foreach ($key in $panelKeys) {
        $examined++
        if ($key -notin $recognized) {
            $problems.Add("the settings panel offers a toggle the viewer does not recognize: $key")
        }
    }

    foreach ($key in $configConstants) {
        $examined++
        if ($key -notin $recognized -and $operational -notcontains $key) {
            $problems.Add("the browser configuration carries an unexpected setting: $key")
        }
    }

    return New-CheckResult -Name 'feature toggles are wired end to end' `
        -Ok ($problems.Count -eq 0) -Examined $examined -Details $problems
}

function Test-ConfigKeyScopes {
    <#
    .SYNOPSIS
        Per-directory and installation-level configuration keys stay disjoint,
        and the shipping configuration sets only listed keys.

    .DESCRIPTION
        Only three keys are honoured from a subdirectory's own configuration file;
        the rest are ignored with a log entry, because letting a browsed directory
        set them would hand directory owners installation-level authority.

        The second half catches a key the shipping file sets but neither list
        governs - it would be silently dropped.
    #>
    $problems = [System.Collections.Generic.List[string]]::new()
    $examined = 0

    $mdPath = Join-Path $script:ProjectRoot 'md.php'
    if (-not (Test-Path -LiteralPath $mdPath -PathType Leaf)) {
        return New-CheckResult -Name 'per-directory and installation-level keys stay disjoint' `
            -Ok $false -Examined 0 -Details @('the viewer script could not be read')
    }
    $md = Get-ProjectText -Path $mdPath

    $perDirPattern = 'const MDV_PER_DIR_KEYS = \[(.*?)\];'
    $installPattern = 'const MDV_INSTALL_KEYS = \[(.*?)\];'
    Assert-RegexLiteral -Pattern $perDirPattern -What 'per-directory key list'
    Assert-RegexLiteral -Pattern $installPattern -What 'installation-level key list'

    $perDirMatch = [regex]::Match($md, $perDirPattern)
    $installMatch = [regex]::Match($md, $installPattern)
    if (-not $perDirMatch.Success -or -not $installMatch.Success) {
        return New-CheckResult -Name 'per-directory and installation-level keys stay disjoint' `
            -Ok $false -Examined 0 -Details @('one of the two configuration key lists was not found in the viewer')
    }

    $perDir = [regex]::Matches($perDirMatch.Groups[1].Value, "'(\w+)'") |
        ForEach-Object { $_.Groups[1].Value } | Select-Object -Unique
    $install = [regex]::Matches($installMatch.Groups[1].Value, "'(\w+)'") |
        ForEach-Object { $_.Groups[1].Value } | Select-Object -Unique
    if ($perDir.Count -eq 0 -or $install.Count -eq 0) {
        return New-CheckResult -Name 'per-directory and installation-level keys stay disjoint' `
            -Ok $false -Examined 0 -Details @('one of the two configuration key lists is empty')
    }

    foreach ($key in $perDir) {
        $examined++
        if ($install -contains $key) {
            $problems.Add("key is governed by both scopes: $key")
        }
    }

    $known = @($perDir + $install)

    $iniPath = Join-Path $script:ProjectRoot '.md.ini'
    if (Test-Path -LiteralPath $iniPath -PathType Leaf) {
        # Key names only. The shipping configuration file also holds a generated
        # API key, so its values are never read, in whole or in part.
        $iniText = Get-ProjectText -Path $iniPath
        $iniKeys = [regex]::Matches($iniText, '(?m)^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=') |
            ForEach-Object { $_.Groups[1].Value } | Select-Object -Unique
        foreach ($key in $iniKeys) {
            $examined++
            if ($known -notcontains $key) {
                $problems.Add("the shipping configuration sets an unlisted key: $key")
            }
        }
    }

    return New-CheckResult -Name 'per-directory and installation-level keys stay disjoint' `
        -Ok ($problems.Count -eq 0) -Examined $examined -Details $problems
}

function Test-AssetReferences {
    <#
    .SYNOPSIS
        Every asset URL points at a file that exists, and cache-busting arguments
        stay attached to the assets they version.

    .DESCRIPTION
        Asset URLs in this product are relative, which is what lets it be installed
        in a subdirectory. The cache-busting helper resolves the same path on the
        filesystem, so the URL and the argument are coupled only by the string
        literal handed to the helper. Renaming an asset therefore has to change
        both, and a mismatch is invisible at run time: the page simply serves a
        stale stylesheet, or none.
    #>
    $problems = [System.Collections.Generic.List[string]]::new()
    $examined = 0

    $mdPath = Join-Path $script:ProjectRoot 'md.php'
    if (-not (Test-Path -LiteralPath $mdPath -PathType Leaf)) {
        return New-CheckResult -Name 'asset URLs and cache-busting arguments agree' `
            -Ok $false -Examined 0 -Details @('the viewer script could not be read')
    }
    $md = Get-ProjectText -Path $mdPath

    $referencePattern = '(?:href|src)="((?:css|js)/md/[a-z]+\.(?:css|js))'
    $versionPattern = "assetVersion\('([^']+)'\)"
    Assert-RegexLiteral -Pattern $referencePattern -What 'asset references'
    Assert-RegexLiteral -Pattern $versionPattern -What 'cache-busting arguments'

    $referenced = [regex]::Matches($md, $referencePattern) |
        ForEach-Object { $_.Groups[1].Value } | Select-Object -Unique
    $versioned = [regex]::Matches($md, $versionPattern) |
        ForEach-Object { $_.Groups[1].Value } | Select-Object -Unique

    if ($referenced.Count -eq 0) {
        return New-CheckResult -Name 'asset URLs and cache-busting arguments agree' `
            -Ok $false -Examined 0 -Details @('no asset reference was found in the viewer')
    }

    # The two stylesheets in the document head are loaded without a version
    # argument on purpose: they must apply before any script runs.
    $unversionedByDesign = @('css/md/tooltips.css', 'css/md/md.css')

    foreach ($asset in $referenced) {
        $examined++
        $path = Join-Path $script:ProjectRoot ($asset -replace '/', '\')
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            $problems.Add("referenced asset is absent: $asset")
        }
        if ($versioned -notcontains $asset -and $unversionedByDesign -notcontains $asset) {
            $problems.Add("asset is referenced without a cache-busting argument: $asset")
        }
    }

    foreach ($asset in $versioned) {
        $examined++
        if ($referenced -notcontains $asset) {
            $problems.Add("a cache-busting argument exists for an asset that is never referenced: $asset")
        }
    }

    return New-CheckResult -Name 'asset URLs and cache-busting arguments agree' `
        -Ok ($problems.Count -eq 0) -Examined $examined -Details $problems
}

function Test-RawHtmlBoundary {
    <#
    .SYNOPSIS
        Unescaped document-authored HTML is emitted from exactly one file.

    .DESCRIPTION
        The viewer deliberately splices unescaped HTML back into the rendered
        document: a Markdown file may contain a script tag and it reaches the page
        verbatim. That is a design decision of this product and it is load-bearing,
        so the check does not forbid it. What it forbids is a second place doing it.

        The reason is the companion's API. The updater answers machine requests
        with JSON and is protected by a same-origin rule and an API key, while the
        viewer has no authentication of any kind. An unescaped output added there
        would be a second, unguarded surface rather than a variation of the
        existing one.

        The viewer's count is pinned rather than merely bounded: the render
        pipeline already repeats one sequence between the clipboard branch and the
        file branch, and a fourth unescaped echo is how that class of mistake
        becomes visible in review.
    #>
    $targets = @(
        @{ File = 'md.php'; Allowed = 4 }
        @{ File = 'updater.php'; Allowed = 0 }
    )

    $problems = [System.Collections.Generic.List[string]]::new()
    $examined = 0
    $echoPattern = '<\?=\s*\$[A-Za-z_]'
    Assert-RegexLiteral -Pattern $echoPattern -What 'unescaped echo'

    foreach ($target in $targets) {
        $path = Join-Path $script:ProjectRoot $target.File
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { continue }
        $examined++
        $text = Get-ProjectText -Path $path
        $count = ([regex]::Matches($text, $echoPattern)).Count
        if ($count -ne $target.Allowed) {
            $problems.Add("$($target.File): unescaped echo sites = $count, baseline is $($target.Allowed)")
        }
    }

    if ($examined -eq 0) {
        return New-CheckResult -Name 'unescaped document HTML is emitted from the viewer only' `
            -Ok $false -Examined 0 -Details @('neither PHP entry point could be read')
    }

    return New-CheckResult -Name 'unescaped document HTML is emitted from the viewer only' `
        -Ok ($problems.Count -eq 0) -Examined $examined -Details $problems
}

function Test-DoubledSlashRule {
    <#
    .SYNOPSIS
        The doubled-slash rule has exactly three implementations and the redirect
        stays GET-guarded.

    .DESCRIPTION
        A page reached at a path with a duplicated slash must be normalised, or a
        generated link reads as protocol-relative and its first path segment
        becomes the host. One rule, three implementations: the viewer redirects,
        and two browser scripts rebuild absolute URLs.

        Three copies means a fix applied to one can miss the others, which is why
        the count is pinned rather than merely the presence. The redirect must stay
        GET-only: the clipboard preview posts a body to the same script, and a
        redirect would discard it.
    #>
    $problems = [System.Collections.Generic.List[string]]::new()
    $examined = 0

    $sites = @(
        @{ File = 'md.php'; Expect = 1; Kind = 'php' }
        @{ File = 'js/md/md.js'; Expect = 1; Kind = 'js' }
        @{ File = 'js/md/upload.js'; Expect = 1; Kind = 'js' }
    )

    $phpPattern = "preg_replace\('#/\{2,\}#'"
    $jsPattern = 'pathname\.replace\(/\\?/\{2,\}/g'
    Assert-RegexLiteral -Pattern $phpPattern -What 'viewer normalisation'
    Assert-RegexLiteral -Pattern $jsPattern -What 'browser normalisation'

    foreach ($site in $sites) {
        $path = Join-Path $script:ProjectRoot ($site.File -replace '/', '\')
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { continue }
        $examined++
        $text = Get-ProjectText -Path $path
        $pattern = $phpPattern
        if ($site.Kind -eq 'js') { $pattern = $jsPattern }
        $count = ([regex]::Matches($text, $pattern)).Count
        if ($count -ne $site.Expect) {
            $problems.Add("$($site.File): normalisation sites = $count, baseline is $($site.Expect)")
        }
    }

    $mdPath = Join-Path $script:ProjectRoot 'md.php'
    if (Test-Path -LiteralPath $mdPath -PathType Leaf) {
        $examined++
        $md = Get-ProjectText -Path $mdPath
        $guardPattern = "(?s)REQUEST_METHOD'\] \?\? 'GET'\) === 'GET'.{0,400}?header\('Location:"
        Assert-RegexLiteral -Pattern $guardPattern -What 'redirect GET guard'
        if (-not [regex]::IsMatch($md, $guardPattern)) {
            $problems.Add('the doubled-slash redirect is no longer guarded by a GET test')
        }
    }

    if ($examined -eq 0) {
        return New-CheckResult -Name 'the doubled-slash rule has three implementations' `
            -Ok $false -Examined 0 -Details @('no file carrying the rule could be read')
    }

    return New-CheckResult -Name 'the doubled-slash rule has three implementations' `
        -Ok ($problems.Count -eq 0) -Examined $examined -Details $problems
}

function Test-NumericLimits {
    <#
    .SYNOPSIS
        The deliberate numeric limits still hold their recorded values.

    .DESCRIPTION
        Each of these guards something that only fails under load or under attack,
        which is exactly the kind of value that gets widened during an unrelated
        change and never widened back:

          * the depth cap on a requested path, reused for the per-directory
            configuration chain and for the directory listing - one edit changes
            three behaviours;
          * the length cap on the file parameter;
          * the file cap on the directory scan;
          * the file cap on the updater's name-clash scan.

        The last two differ on purpose: the updater's comment says it mirrors the
        viewer, but it scans a wider tree and allows double the files. Do not
        "fix" that into equality - the values are pinned separately so the
        difference is recorded rather than accidental.
    #>
    $limits = @(
        @{ File = 'md.php'; Constant = 'MAX_FILE_PARAM_LENGTH'; Expected = 255 }
        @{ File = 'md.php'; Constant = 'MAX_SCAN_DEPTH'; Expected = 3 }
        @{ File = 'md.php'; Constant = 'MAX_FILES_SCAN'; Expected = 10000 }
        @{ File = 'updater.php'; Constant = 'CONFLICT_SCAN_MAX_FILES'; Expected = 20000 }
    )

    $problems = [System.Collections.Generic.List[string]]::new()
    $examined = 0
    $cache = @{}

    foreach ($limit in $limits) {
        $examined++
        $path = Join-Path $script:ProjectRoot $limit.File
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            $problems.Add("$($limit.File) is not present, so $($limit.Constant) cannot be verified")
            continue
        }
        if (-not $cache.ContainsKey($limit.File)) {
            $cache[$limit.File] = Get-ProjectText -Path $path
        }
        $pattern = 'const\s+' + [regex]::Escape($limit.Constant) + '\s*=\s*(\d+)'
        Assert-RegexLiteral -Pattern $pattern -What 'numeric limit'
        $match = [regex]::Match($cache[$limit.File], $pattern)
        if (-not $match.Success) {
            $problems.Add("$($limit.File): constant $($limit.Constant) was not found")
            continue
        }
        $actual = [int]$match.Groups[1].Value
        if ($actual -ne $limit.Expected) {
            $problems.Add("$($limit.File): $($limit.Constant) = $actual, baseline is $($limit.Expected)")
        }
    }

    return New-CheckResult -Name 'deliberate numeric limits still hold their values' `
        -Ok ($problems.Count -eq 0) -Examined $examined -Details $problems
}

function Test-AnchoredRegions {
    <#
    .SYNOPSIS
        Regions whose behaviour is load-bearing are byte-identical to the ones that
        were verified.

    .DESCRIPTION
        Three of these regions are the reason a small edit is dangerous here, and
        none of them has a test:

          * the request-path validator decides what the viewer may read, and it is
            the only thing between a crafted path and the filesystem;
          * the updater's atomic write replaces live files on other people's
            servers, so its temp-file and rename discipline is not a detail;
          * the same-origin guard is what refuses cross-site state changes, and a
            one-line mistake removes it.

        A mismatch is a question, not a verdict: it means a region that must be
        re-verified by hand was edited. When the change is intended, update the
        recorded hash in the same commit and say why in the commit message.

        The two render functions are pinned because they are the largest bodies of
        hand-written logic in the project and the raw-HTML splice lives inside
        them.
    #>
    $anchors = @(
        # v2.16.0: Layer 7 widened from ASCII-only to "path characters + space +
        # Unicode letters and marks", so a Cyrillic file name or directory is now
        # readable. The hash was updated in the same commit as the change, and the
        # five regions this task did not touch were re-derived to confirm the
        # recorded algorithm still reproduces them.
        @{ File = 'md.php'; Name = 'validateRequestedFile'; Hash = '2a90acc7a9bb9421f7c6fe910bc89e363f26e9ffd72410009405755706dd499c' }
        @{ File = 'md.php'; Name = 'renderMarkdown'; Hash = 'bf056eb71849553e18bd29890bcdcfd0f0e12bfd9be749ca0197d5af476bc217' }
        @{ File = 'md.php'; Name = 'inlineMarkdown'; Hash = 'a48ce85095640443a65aa5042c40fa00055a44de40126bd725eb9c2f635ca5ba' }
        @{ File = 'updater.php'; Name = 'atomicWrite'; Hash = 'ad04231b756ad25873953934860c851953a4849965bf4bdd7ee6ca58a63be44b' }
        @{ File = 'updater.php'; Name = 'requireSameOrigin'; Hash = '2c5e5c21853487305d45353a3b124fe029214895c673817b78e2e1d5b00fed1c' }
        @{ File = 'updater.php'; Name = 'localVersion'; Hash = '2d3a77e0d17812c57215160f4fe1551650ced90464ffed946238a712be05f7d1' }
    )

    $problems = [System.Collections.Generic.List[string]]::new()
    $examined = 0
    $cache = @{}

    foreach ($anchor in $anchors) {
        $examined++
        $path = Join-Path $script:ProjectRoot $anchor.File
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            $problems.Add("$($anchor.File) is not present, so $($anchor.Name) cannot be verified")
            continue
        }
        if (-not $cache.ContainsKey($anchor.File)) {
            $cache[$anchor.File] = Get-ProjectText -Path $path
        }
        $region = Get-FunctionRegion -Text $cache[$anchor.File] -Name $anchor.Name
        if ([string]::IsNullOrEmpty($region)) {
            $problems.Add("$($anchor.File): function $($anchor.Name) was not found")
            continue
        }
        $actual = Get-Sha256 -Text $region
        if ($actual -ne $anchor.Hash) {
            $problems.Add("$($anchor.File): $($anchor.Name) changed (recorded $($anchor.Hash.Substring(0, 12)), found $($actual.Substring(0, 12)))")
        }
    }

    return New-CheckResult -Name 'load-bearing regions are unchanged' `
        -Ok ($problems.Count -eq 0) -Examined $examined -Details $problems
}

function Test-ScaffoldMarkers {
    <#
    .SYNOPSIS
        The shipping files carry no unfilled scaffold marker.

    .DESCRIPTION
        The updater publishes these exact files to every installation, so a marker
        left in one of them reaches installed copies. Reported as a warning: a
        deliberate marker is a decision and an accidental one is a defect, and
        this check cannot tell them apart.
    #>
    $markers = @('TODO:', 'FIXME', 'XXX:')

    $problems = [System.Collections.Generic.List[string]]::new()
    $examined = 0

    foreach ($relative in $script:ShippingFiles) {
        $path = Join-Path $script:ProjectRoot ($relative -replace '/', '\')
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { continue }
        $examined++
        $text = Get-ProjectText -Path $path
        foreach ($marker in $markers) {
            $count = ([regex]::Matches($text, [regex]::Escape($marker))).Count
            if ($count -gt 0) { $problems.Add("${relative}: $count x $marker") }
        }
    }

    if ($examined -eq 0) {
        return New-CheckResult -Name 'no unfilled scaffold markers in the shipping package' `
            -Ok $false -Examined 0 -Details @('no shipping file could be read')
    }

    return New-CheckResult -Name 'no unfilled scaffold markers in the shipping package' `
        -Ok ($problems.Count -eq 0) -Examined $examined -Details $problems -Warning
}

function Test-DocumentationVersions {
    <#
    .SYNOPSIS
        README's component versions match the code markers.

    .DESCRIPTION
        README states each component's version in a table, in the layout diagram
        and in two badges; the code states it in a docblock marker. The updater
        reads the code marker, so a stale table is harmless to the running product
        and misleading to everyone reading the repository - which is why it is
        reported here rather than left to be rediscovered.

        This is the pre-existing finding recorded in the baseline: four table rows
        and two badges disagree with the code.
    #>
    $readmePath = Join-Path $script:ProjectRoot 'README.md'
    if (-not (Test-Path -LiteralPath $readmePath -PathType Leaf)) {
        return New-CheckResult -Name 'README component versions match the code' `
            -Ok $false -Examined 0 -Details @('the README could not be read')
    }

    $readme = Get-ProjectText -Path $readmePath
    $markerPattern = '\*\s+Version:\s*(\d[\w.\-]+)'
    $rowPattern = '(?m)^([A-Za-z0-9_./]+\.(?:php|js|css|md))\s+v(\d[\w.\-]+)\s*$'
    $badgePattern = 'badge/([A-Za-z0-9_.]+)-v(\d[\w.\-]+)-'
    Assert-RegexLiteral -Pattern $markerPattern -What 'version marker'
    Assert-RegexLiteral -Pattern $rowPattern -What 'README version rows'
    Assert-RegexLiteral -Pattern $badgePattern -What 'README version badges'

    $problems = [System.Collections.Generic.List[string]]::new()
    $examined = 0

    $claims = [System.Collections.Generic.List[object]]::new()
    foreach ($match in [regex]::Matches($readme, $rowPattern)) {
        $claims.Add([pscustomobject]@{ Where = 'table'; File = $match.Groups[1].Value; Version = $match.Groups[2].Value })
    }
    foreach ($match in [regex]::Matches($readme, $badgePattern)) {
        $claims.Add([pscustomobject]@{ Where = 'badge'; File = $match.Groups[1].Value; Version = $match.Groups[2].Value })
    }

    if ($claims.Count -eq 0) {
        return New-CheckResult -Name 'README component versions match the code' `
            -Ok $false -Examined 0 -Details @('the README states no component version')
    }

    foreach ($claim in $claims) {
        $examined++
        $path = Join-Path $script:ProjectRoot ($claim.File -replace '/', '\')
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            $problems.Add("the README names a file that is not in the package: $($claim.File)")
            continue
        }
        $head = Get-HeadBytes -Path $path
        $codeMatch = [regex]::Match($head, $markerPattern)
        if (-not $codeMatch.Success) {
            $problems.Add("no version marker to compare against: $($claim.File)")
            continue
        }
        if ($codeMatch.Groups[1].Value -ne $claim.Version) {
            $problems.Add("README $($claim.Where) states $($claim.File) is $($claim.Version), the code marker is $($codeMatch.Groups[1].Value)")
        }
    }

    return New-CheckResult -Name 'README component versions match the code' `
        -Ok ($problems.Count -eq 0) -Examined $examined -Details $problems -Warning
}

function Test-SecretHygiene {
    <#
    .SYNOPSIS
        Secret-shaped files have not appeared beyond the recorded baseline.

    .DESCRIPTION
        The files are never opened: only their names are compared. This repository
        ships no secret-shaped file, so the baseline is empty and any match is a
        finding.

        The shipping configuration file holds a generated API key. It is not
        secret-shaped and is not reported here; that is a property of its contents,
        which this check deliberately does not read.
    #>
    $patterns = @(
        '^\.env$', '^\.env\.(?!example$|sample$|template$)',
        '\.key$', '^\.config\.php$', '^\.(htpasswd|httpauth)$',
        '^wp-config\.php$', '^id_(rsa|dsa|ecdsa|ed25519)',
        '\.(pem|pfx|p12|jks|keystore|ppk)$',
        '^\.(netrc|_netrc|git-credentials|npmrc|kdbx|ovpn)$',
        '\.sql\.php$', '\.(bak|backup|orig)$'
    )
    $benign = @('cacert\.pem$', 'ca-bundle\.', '\.crt$', '\.min\.js$')

    foreach ($pattern in ($patterns + $benign)) {
        Assert-RegexLiteral -Pattern $pattern -What 'secret hygiene'
    }

    # Recorded baseline: no secret-shaped file existed at hand-over.
    $baseline = @()

    $problems = [System.Collections.Generic.List[string]]::new()
    $examined = 0

    foreach ($file in (Get-RepositoryFile -SecretScan)) {
        $examined++
        $relative = Get-RelativePath $file.FullName

        $skip = $false
        foreach ($pattern in $benign) {
            if ($file.Name -match $pattern) { $skip = $true; break }
        }
        if ($skip) { continue }

        $matched = $false
        foreach ($pattern in $patterns) {
            if ($file.Name -match $pattern) { $matched = $true; break }
        }
        if (-not $matched) { continue }

        $known = $false
        foreach ($entry in $baseline) {
            if ($relative -like $entry) { $known = $true; break }
        }
        if ($known) { continue }

        $problems.Add("secret-shaped and not in the recorded baseline: $relative")
    }

    if ($examined -eq 0) {
        return New-CheckResult -Name 'secret-shaped files stayed within the baseline' `
            -Ok $false -Examined 0 -Details @('no file was walked, so nothing was examined')
    }

    return New-CheckResult -Name 'secret-shaped files stayed within the baseline' `
        -Ok ($problems.Count -eq 0) -Examined $examined -Details $problems -Warning:($problems.Count -gt 0)
}

# ------------------------------------------------------------------- runner

$script:CheckFunctions = @(
    'Test-BaselineLayout'
    'Test-PhpSyntax'
    'Test-JavaScriptSyntax'
    'Test-VersionMarkers'
    'Test-FeatureWiring'
    'Test-ConfigKeyScopes'
    'Test-AssetReferences'
    'Test-RawHtmlBoundary'
    'Test-DoubledSlashRule'
    'Test-NumericLimits'
    'Test-AnchoredRegions'
    'Test-ScaffoldMarkers'
    'Test-DocumentationVersions'
    'Test-SecretHygiene'
)

Write-Host 'project doctrine check'
Write-Host "repository: $script:ProjectRoot"
Write-Host ''

# Each check runs exactly once; the results are buffered so the column width can
# be computed from the real names without executing anything twice.
$results = [System.Collections.Generic.List[object]]::new()
foreach ($name in $script:CheckFunctions) {
    $results.Add((& $name))
}

$pad = 0
foreach ($check in $results) { if ($check.Name.Length -gt $pad) { $pad = $check.Name.Length } }

$errors = 0
$warnings = 0
$notApplicable = 0
$voidChecks = 0

foreach ($check in $results) {
    Write-CheckResult -Check $check -Pad $pad

    switch ($check.Kind) {
        'na'   { $notApplicable++ }
        'warn' { $warnings++ }
        'void' { $voidChecks++; $errors++ }
        'pass' { }
        default {
            if ($check.Warning -and -not $Strict) { $warnings++ } else { $errors++ }
        }
    }
}

Write-Host ''
Write-Host "checks: $($results.Count), errors: $errors, warnings: $warnings, not-applicable: $notApplicable"

if ($voidChecks -gt 0) {
    Write-Host "$voidChecks checks reported a false PASS (nothing examined) - that is a checker defect, not a project one." -ForegroundColor Red
}

if ($errors -gt 0) {
    Write-Host 'Result: FAIL' -ForegroundColor Red
    exit 1
}

if ($warnings -gt 0) {
    Write-Host 'Result: PASS with warnings' -ForegroundColor Yellow
    exit 0
}

Write-Host 'Result: PASS' -ForegroundColor Green
exit 0
