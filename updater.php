<?php
/**
 * Markdown Viewer — Self-Updater
 * Version: 3.5.0
 * Author: Mikhail Deynekin
 * Site: https://Deynekin.com
 * Email: Mikhail@Deynekin.com
 *
 * PHP 8.3+ self-update engine. No GitHub API, no tokens.
 *
 * Update detection uses a two-layer approach:
 *   1. Conditional GET with stored ETag (If-None-Match) against
 *      raw.githubusercontent.com — 304 = file unchanged, skip immediately.
 *   2. On 200, verify with SHA-256 comparison of the downloaded payload
 *      against the local file — identical hash = no real change (CDN quirk).
 *   3. Version string (" * Version: X.Y.Z") extracted from first 1000 bytes
 *      for human-readable status in the Settings panel.
 *
 * ETag + hash state persisted per file in md.backup/.state/{slug}.json.
 * Atomic write: tempnam() + LOCK_EX fwrite + rename() on same filesystem.
 * Backup before replace: md.backup/{localVersion}/{file} (never deleted).
 *
 * Endpoints:
 *   check    GET  — ETag conditional check for all tracked files
 *   apply    POST — backup old → conditional fetch → atomic replace
 *   restore  POST — backup current → restore from md.backup/{version}/
 *   backups  GET  — list available backup versions
 *   version  GET  — local version of md.php (Settings badge)
 *
 * Rules:
 *   - Never deletes local files absent from remote.
 *   - Same-origin CORS guard; POST required for mutating actions.
 *   - cURL required (curl extension).
 *   - updater.php updates itself safely: rename() is atomic on the same filesystem,
 *     and PHP has already loaded the current script into memory/opcache for the
 *     running request. The new version takes effect from the next request onward.
 *
 * v2.0.0: Raw Range requests, no API/tokens.
 * v2.1.0: Backup-before-replace, restore-from-backup.
 * v3.5.0: TRACKED_FILES expanded — settings.js, settings.css, upload.js, README.md, LICENSE.
 * v3.4.0: ALLOW_UPDATE flag; direct ?update=true mode with two-phase self-update.
 * v3.3.0: save_clipboard action; uploads.md/ directory for both upload and save.
 * v3.2.1: upload_md checks DISABLE_UPLOAD from .md.ini.
 * v3.2.0: upload_md action — .md file upload with filename sanitization.
 * v3.1.1: index_create refuses (409) if regular index.php exists.
 * v3.1.0: index.php hard-link management (index_status/create/remove).
 * v3.0.1: updater.php added to TRACKED_FILES — now self-updates.
 * v3.0.0: RawFileUpdater class — ETag + SHA-256 conditional updates.
 */
declare(strict_types=1);

// ── Configuration ─────────────────────────────────────────────────────────────

const RAW_BASE = 'https://raw.githubusercontent.com/paulmann/MD.Viewer/refs/heads/main';

const TRACKED_FILES = [
    // Core PHP scripts
    'md.php',
    'updater.php',
    // JavaScript
    'assets/js/md.js',
    'assets/js/settings.js',
    'assets/js/tooltips.js',
    'assets/js/upload.js',
    // CSS
    'assets/css/md.css',
    'assets/css/settings.css',
    'assets/css/tooltips.css',
    // Docs (read-only: never backed up, never force-replaced if local edits exist)
    'README.md',
    'LICENSE',
];

// ── RawFileUpdater ────────────────────────────────────────────────────────────

/**
 * Keeps one local file in sync with its raw GitHub counterpart.
 *
 * Relies only on plain HTTP against raw.githubusercontent.com:
 *   1) Conditional GET with stored ETag (If-None-Match) → 304 = no change.
 *   2) On 200, verify payload via SHA-256 against local copy.
 *   3) Atomic replace: tempnam() + LOCK_EX + rename().
 *   4) Persist ETag + content hash in a small JSON state file.
 */
final class RawFileUpdater
{
    private const USER_AGENT = 'MDViewer-RawUpdater/3.0 (+https://Deynekin.com)';

    public function __construct(
        private readonly string  $rawUrl,
        private readonly string  $localPath,
        private readonly ?string $statePath  = null,
        private readonly int     $timeout    = 15,
    ) {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The cURL extension is required.');
        }
    }

    /** SHA-256 of the local file, or null when it does not exist. */
    public function localHash(): ?string
    {
        if (!is_file($this->localPath)) return null;
        $hash = @hash_file('sha256', $this->localPath);
        if ($hash === false) {
            throw new RuntimeException("Cannot hash local file: {$this->localPath}");
        }
        return $hash;
    }

    /**
     * Check only — returns status without writing anything.
     *
     * @return array{status: string, localHash: ?string, remoteHash: ?string,
     *               etag: ?string, httpStatus: int, error: ?string}
     */
    public function check(): array
    {
        $state = $this->loadState();
        try {
            [$httpStatus, $respHeaders, $body] = $this->conditionalGet($state['etag'] ?? null);
        } catch (RuntimeException $e) {
            return [
                'status'     => 'error',
                'localHash'  => $this->localHash(),
                'remoteHash' => null,
                'etag'       => null,
                'httpStatus' => 0,
                'error'      => $e->getMessage(),
            ];
        }

        $localHash = $this->localHash();

        if ($httpStatus === 304) {
            return [
                'status'     => 'current',
                'localHash'  => $localHash,
                'remoteHash' => $state['hash'] ?? null,
                'etag'       => $state['etag'] ?? null,
                'httpStatus' => 304,
                'error'      => null,
            ];
        }

        if ($httpStatus !== 200 || !is_string($body)) {
            return [
                'status'     => 'error',
                'localHash'  => $localHash,
                'remoteHash' => null,
                'etag'       => null,
                'httpStatus' => $httpStatus,
                'error'      => "Unexpected HTTP {$httpStatus}",
            ];
        }

        $remoteHash = hash('sha256', $body);
        $newEtag    = $respHeaders['etag'] ?? null;

        // If hashes match, update ETag cache and report current
        if ($localHash !== null && hash_equals($localHash, $remoteHash)) {
            $this->saveState($newEtag, $remoteHash);
            return [
                'status'     => 'current',
                'localHash'  => $localHash,
                'remoteHash' => $remoteHash,
                'etag'       => $newEtag,
                'httpStatus' => 200,
                'error'      => null,
            ];
        }

        // Content changed — caller decides whether to apply
        return [
            'status'     => $localHash === null ? 'missing' : 'outdated',
            'localHash'  => $localHash,
            'remoteHash' => $remoteHash,
            'etag'       => $newEtag,
            'httpStatus' => 200,
            'body'       => $body,       // included so apply() can reuse without re-fetch
            'error'      => null,
        ];
    }

    /**
     * Apply update. Downloads and atomically replaces the local file.
     * Backs up the old file to md.backup/{version}/ before replacing.
     *
     * @return array{status: string, error: ?string}
     */
    public function apply(string $backupVersion = ''): array
    {
        $result = $this->check();

        if ($result['status'] === 'error') {
            return ['status' => 'error', 'error' => $result['error']];
        }
        if ($result['status'] === 'current') {
            return ['status' => 'current', 'error' => null];
        }

        // Body may already be available from check() to avoid double-download
        $body = $result['body'] ?? null;
        if ($body === null) {
            // Re-fetch without ETag (shouldn't happen normally)
            [, $respHeaders, $body] = $this->conditionalGet(null);
            if (!is_string($body)) {
                return ['status' => 'error', 'error' => 'Download failed'];
            }
        }

        if (strlen($body) < 64) {
            return ['status' => 'error', 'error' => 'Downloaded content too small — aborting'];
        }

        // Backup old file
        if ($backupVersion !== '' && is_file($this->localPath)) {
            $this->backupTo($backupVersion);
        }

        try {
            $this->atomicReplace($body);
        } catch (RuntimeException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        $this->saveState($result['etag'], hash('sha256', $body));

        return [
            'status' => $result['status'] === 'missing' ? 'created' : 'updated',
            'error'  => null,
        ];
    }

    /** Copy local file to md.backup/{version}/{relative-path}. */
    public function backupTo(string $version): void
    {
        $backupDest = backupDir($version) . '/' . ltrim(
            str_replace(docRoot(), '', $this->localPath), '/\\'
        );
        $dir = dirname($backupDest);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @copy($this->localPath, $backupDest);
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    /**
     * @return array{0: int, 1: array<string,string>, 2: string|null}
     */
    private function conditionalGet(?string $etag): array
    {
        $reqHeaders = [
            'User-Agent: ' . self::USER_AGENT,
            'Accept: */*',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ];
        if ($etag !== null && $etag !== '') {
            $reqHeaders[] = 'If-None-Match: ' . $etag;
        }

        $respHeaders = [];
        $ch = curl_init($this->rawUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER     => $reqHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => '',           // transparent gzip/deflate
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        if ($body === false && curl_errno($ch) !== 0) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL error: {$err}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, $respHeaders, is_string($body) ? $body : null];
    }

    // ── State (ETag + hash) ───────────────────────────────────────────────────

    /** @return array{etag?: ?string, hash?: ?string} */
    private function loadState(): array
    {
        $path = $this->statePathOrDefault();
        if (!is_file($path)) return [];
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') return [];
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private function saveState(?string $etag, string $hash): void
    {
        $path    = $this->statePathOrDefault();
        $dir     = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $payload = json_encode(
            ['etag' => $etag, 'hash' => $hash, 'updated_at' => gmdate('c')],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        );
        @file_put_contents($path, $payload, LOCK_EX);
    }

    private function statePathOrDefault(): string
    {
        return $this->statePath ?? $this->localPath . '.state.json';
    }

    // ── Atomic write ──────────────────────────────────────────────────────────

    private function atomicReplace(string $content): void
    {
        $dir = dirname($this->localPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException("Cannot create directory: {$dir}");
        }
        $tmp = tempnam($dir, '.upd_');
        if ($tmp === false) {
            throw new RuntimeException("Cannot create temp file in: {$dir}");
        }
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('Failed to write temporary file.');
        }
        if (!@rename($tmp, $this->localPath)) {
            @unlink($tmp);
            throw new RuntimeException('Atomic replace (rename) failed.');
        }
        @chmod($this->localPath, 0644);
    }
}

// ── Procedural helpers ────────────────────────────────────────────────────────

function docRoot(): string
{
    return rtrim($_SERVER['DOCUMENT_ROOT'] ?: dirname(__FILE__), '/\\');
}

function readIni(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $path  = docRoot() . '/.md.ini';
    $cache = is_file($path) ? (@parse_ini_file($path, false, INI_SCANNER_TYPED) ?: []) : [];

    // Auto-append ALLOW_UPDATE if the file exists but is missing the key
    if (is_file($path) && !array_key_exists('ALLOW_UPDATE', $cache)) {
        $append  = "\n; Allow updating files via updater.php?update=true or the Settings panel\n";
        $append .= "ALLOW_UPDATE = false\n";
        @file_put_contents($path, $append, FILE_APPEND | LOCK_EX);
        $cache['ALLOW_UPDATE'] = false;
    }

    return $cache;
}

function requireAllowUpdate(): void
{
    $ini = readIni();
    if (!(bool)($ini['ALLOW_UPDATE'] ?? false)) {
        jsonError(403, 'Updates are disabled. Set ALLOW_UPDATE = true in .md.ini to enable.');
    }
}

function localPath(string $file): string
{
    return docRoot() . '/' . ltrim($file, '/\\');
}

function backupRoot(): string
{
    return docRoot() . '/md.backup';
}

function backupDir(string $version): string
{
    $safe = preg_replace('/[^a-zA-Z0-9.\-]/', '_', $version);
    return backupRoot() . '/' . $safe;
}

function stateDir(): string
{
    return backupRoot() . '/.state';
}

function stateFile(string $file): string
{
    // Convert path to flat filename: "assets/js/md.js" → "assets_js_md.js.json"
    $slug = str_replace(['/', '\\'], '_', $file);
    return stateDir() . '/' . $slug . '.json';
}

function rawUrl(string $file): string
{
    return RAW_BASE . '/' . ltrim($file, '/');
}

function extractVersion(string $content): string
{
    if (preg_match('/\*\s+Version:\s*(\d[\w.\-]+)/m', $content, $m)) {
        return $m[1];
    }
    return '';
}

function localVersion(string $file = 'md.php'): string
{
    $path = localPath($file);
    if (!is_file($path)) return '';
    $fh = fopen($path, 'rb');
    if (!$fh) return '';
    $head = fread($fh, 1000);
    fclose($fh);
    return extractVersion((string) $head);
}

function backupFile(string $file, string $version): bool
{
    $src = localPath($file);
    if (!is_file($src)) return true;
    $dir  = backupDir($version) . '/' . dirname($file);
    $dest = backupDir($version) . '/' . $file;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;
    return copy($src, $dest);
}

function atomicWrite(string $destPath, string $content): bool
{
    $dir = dirname($destPath);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;
    $tmp = $destPath . '.upd.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content) === false) { @unlink($tmp); return false; }
    if (!rename($tmp, $destPath)) { @unlink($tmp); return false; }
    return true;
}

function makeUpdater(string $file): RawFileUpdater
{
    return new RawFileUpdater(
        rawUrl:    rawUrl($file),
        localPath: localPath($file),
        statePath: stateFile($file),
        timeout:   15,
    );
}

function jsonError(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────


// ── Direct-access update mode ─────────────────────────────────────────────────
// Triggered by opening updater.php?update=true directly in a browser.
// Two-phase bootstrap:
//   Phase 1 (?_phase absent or 1): update updater.php itself first.
//             If updater.php changed → redirect to ?update=true&_phase=2
//             so the NEW version of updater.php handles phase 2.
//   Phase 2 (?_phase=2)           : run full apply on all TRACKED_FILES,
//             output human-readable HTML result page.
//
// Requires ALLOW_UPDATE = true in .md.ini.

if (isset($_GET['update']) && $_GET['update'] === 'true') {

    $ini = @parse_ini_file(docRoot() . '/.md.ini', false, INI_SCANNER_TYPED) ?: [];
    if (!(bool)($ini['ALLOW_UPDATE'] ?? false)) {
        http_response_code(403);
        outputUpdatePage('Access denied', [[
            'type'    => 'error',
            'message' => 'Updates are disabled. Set <code>ALLOW_UPDATE = true</code> in <code>.md.ini</code> to enable.',
        ]]);
        exit;
    }

    $phase = (int)($_GET['_phase'] ?? 1);

    if ($phase === 1) {
        // ── Phase 1: self-update updater.php ──────────────────────────────────
        $selfUpdater = makeUpdater('updater.php');
        $result      = $selfUpdater->apply(backupVersion: localVersion('updater.php'));

        if ($result['status'] === 'error') {
            outputUpdatePage('Self-update failed', [[
                'type'    => 'error',
                'message' => 'Could not update updater.php: ' . htmlspecialchars($result['error'] ?? 'unknown error'),
            ]]);
            exit;
        }

        if ($result['status'] === 'updated' || $result['status'] === 'created') {
            // New updater.php written — redirect so the new version handles phase 2
            $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?') . '?update=true&_phase=2';
            header('Location: ' . $redirectUrl);
            exit;
        }

        // updater.php was already current — fall through to phase 2 directly
        $phase = 2;
    }

    if ($phase === 2) {
        // ── Phase 2: update all tracked files ─────────────────────────────────
        $backupVer = localVersion('md.php');
        $rows      = [];

        $docsFiles = ['README.md', 'LICENSE']; // no version strings, no backup needed
        foreach (TRACKED_FILES as $file) {
            if ($file === 'updater.php') {
                // Already handled in phase 1 — report current status
                $rows[] = ['file' => $file, 'status' => 'current (updated in phase 1)'];
                continue;
            }
            $isDoc     = in_array($file, $docsFiles, true);
            $verBefore = $isDoc ? null : localVersion($file);
            $updater   = makeUpdater($file);
            // Docs: no backup (pass empty string); code files: backup to versioned dir
            $res       = $updater->apply(backupVersion: $isDoc ? '' : $backupVer);
            $rows[]    = [
                'file'    => $file,
                'status'  => $res['status'],
                'from'    => $verBefore,
                'to'      => (!$isDoc && ($res['status'] === 'updated' || $res['status'] === 'created'))
                             ? localVersion($file) : null,
                'error'   => $res['error'] ?? null,
            ];
        }

        outputUpdatePage('Update complete', $rows);
        exit;
    }

    // Unknown phase
    http_response_code(400);
    outputUpdatePage('Bad request', [['type' => 'error', 'message' => 'Unknown phase.']]);
    exit;
}

/**
 * Output a simple standalone HTML page with update results.
 *
 * @param string $title   Page/heading title
 * @param array  $rows    Each row: ['file'=>string, 'status'=>string, 'from'=>?string,
 *                                   'to'=>?string, 'error'=>?string, 'message'=>?string, 'type'=>?string]
 */
function outputUpdatePage(string $title, array $rows): void
{
    $cssClass = static fn(string $s): string => match(true) {
        str_starts_with($s, 'updated'), str_starts_with($s, 'created') => 'ok',
        str_starts_with($s, 'current') => 'skip',
        str_starts_with($s, 'error')   => 'err',
        default                         => 'info',
    };

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>MD.Viewer Updater</title>';
    echo '<style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:system-ui,sans-serif;background:#f8fafc;color:#0f172a;padding:2rem 1rem;min-height:100vh}
        .card{max-width:700px;margin:0 auto;background:#fff;border-radius:16px;
              box-shadow:0 4px 32px rgba(0,0,0,.10);overflow:hidden}
        .card-head{background:#1e293b;color:#f8fafc;padding:1.25rem 1.5rem}
        .card-head h1{font-size:1.25rem;font-weight:700}
        .card-head p{font-size:.8rem;opacity:.6;margin-top:.25rem}
        .rows{padding:.5rem 0}
        .row{display:flex;align-items:baseline;gap:.75rem;padding:.65rem 1.5rem;border-bottom:1px solid #f1f5f9}
        .row:last-child{border:none}
        .badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:.72rem;font-weight:700;white-space:nowrap}
        .ok   .badge{background:#dcfce7;color:#166534}
        .skip .badge{background:#f1f5f9;color:#475569}
        .err  .badge{background:#fee2e2;color:#991b1b}
        .info .badge{background:#dbeafe;color:#1e40af}
        .file{font-family:monospace;font-size:.85rem;flex:1;word-break:break-all}
        .ver {font-size:.75rem;color:#64748b}
        .footer{padding:1rem 1.5rem;text-align:right;background:#f8fafc;border-top:1px solid #e2e8f0}
        .btn{display:inline-block;padding:.55rem 1.25rem;background:#1e293b;color:#f8fafc;
             border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:600}
        .btn:hover{background:#334155}
        code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-size:.85em}
        @media(prefers-color-scheme:dark){
            body{background:#0f172a;color:#e2e8f0}
            .card{background:#1e293b;box-shadow:0 4px 32px rgba(0,0,0,.4)}
            .row{border-color:#334155}
            .footer{background:#0f172a;border-color:#334155}
            .btn{background:#3b82f6;color:#fff}
            .ok   .badge{background:#14532d;color:#bbf7d0}
            .skip .badge{background:#1e293b;color:#94a3b8}
            .err  .badge{background:#450a0a;color:#fca5a5}
            .info .badge{background:#1e3a5f;color:#93c5fd}
            code{background:#334155}
        }
    </style></head><body>';
    echo '<div class="card">';
    echo '<div class="card-head"><h1>' . htmlspecialchars($title) . '</h1>';
    echo '<p>MD.Viewer · ' . htmlspecialchars(localVersion('md.php')) . '</p></div>';
    echo '<div class="rows">';

    foreach ($rows as $row) {
        if (isset($row['message'])) {
            // generic message row
            $cls = $row['type'] ?? 'info';
            echo '<div class="row ' . htmlspecialchars($cls) . '">';
            echo '<span class="badge">' . htmlspecialchars($cls) . '</span>';
            echo '<span class="file">' . $row['message'] . '</span>';
            echo '</div>';
            continue;
        }
        $status = $row['status'] ?? 'info';
        $cls    = $cssClass($status);
        echo '<div class="row ' . $cls . '">';
        echo '<span class="badge">' . htmlspecialchars($status) . '</span>';
        echo '<span class="file">' . htmlspecialchars($row['file'] ?? '') . '</span>';
        if (!empty($row['from']) || !empty($row['to'])) {
            echo '<span class="ver">';
            if ($row['from']) echo htmlspecialchars($row['from']);
            if ($row['from'] && $row['to']) echo ' → ';
            if ($row['to'])   echo htmlspecialchars($row['to']);
            echo '</span>';
        }
        if (!empty($row['error'])) {
            echo '<span class="ver" style="color:#dc2626">' . htmlspecialchars($row['error']) . '</span>';
        }
        echo '</div>';
    }

    echo '</div>';
    echo '<div class="footer"><a class="btn" href="/">← Back</a></div>';
    echo '</div></body></html>';
}

// ── JSON API ──────────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$reqOrigin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$serverHost = $_SERVER['HTTP_HOST']   ?? '';
if ($reqOrigin !== '') {
    $originHost = parse_url($reqOrigin, PHP_URL_HOST) ?? '';
    if ($originHost !== $serverHost) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden origin']);
        exit;
    }
}

$action = $_REQUEST['action'] ?? 'check';
$method = $_SERVER['REQUEST_METHOD'];
if (in_array($action, ['apply', 'restore', 'index_create', 'index_remove', 'upload_md', 'save_clipboard'], true) && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required for ' . $action]);
    exit;
}

match ($action) {
    'check'        => doCheck(),
    'apply'        => doApply(),
    'restore'      => doRestore(),
    'backups'      => doBackups(),
    'version'      => doVersion(),
    'index_status' => doIndexStatus(),
    'index_create' => doIndexCreate(),
    'index_remove' => doIndexRemove(),
    'upload_md'       => doUploadMd(),
    'save_clipboard'  => doSaveClipboard(),
    default           => jsonError(400, 'Unknown action'),
};

// ── Actions ───────────────────────────────────────────────────────────────────

function doVersion(): never
{
    echo json_encode(['version' => localVersion('md.php')]);
    exit;
}

function doBackups(): never
{
    $root = backupRoot();
    if (!is_dir($root)) { echo json_encode(['backups' => []]); exit; }

    $versions = [];
    foreach (scandir($root) as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.state') continue;
        $dir = $root . '/' . $entry;
        if (!is_dir($dir)) continue;
        $hasFiles = false;
        foreach (TRACKED_FILES as $f) {
            if (is_file($dir . '/' . $f)) { $hasFiles = true; break; }
        }
        if (!$hasFiles) continue;
        $versions[] = [
            'version' => $entry,
            'date'    => date('Y-m-d H:i', filemtime($dir)),
            'files'   => array_values(array_filter(
                TRACKED_FILES,
                fn(string $f) => is_file($dir . '/' . $f)
            )),
        ];
    }
    usort($versions, fn($a, $b) => version_compare($b['version'], $a['version']));
    echo json_encode(['backups' => $versions]);
    exit;
}

function doCheck(): never
{
    $results    = [];
    $hasUpdates = false;
    $localVer   = localVersion('md.php');
    $remoteVer  = '';

    foreach (TRACKED_FILES as $file) {
        $updater = makeUpdater($file);
        $check   = $updater->check();

        $locVer = localVersion($file);
        $remVer = '';

        // Extract remote version from downloaded body (200 responses only)
        if (isset($check['body'])) {
            $remVer = extractVersion(substr($check['body'], 0, 1000));
        }

        // For 304/current with known state, re-read version from local file
        // (it matches remote since SHA-256 confirmed equal)
        if ($check['status'] === 'current' && $remVer === '') {
            $remVer = $locVer;
        }

        if ($file === 'md.php' && $remVer !== '') $remoteVer = $remVer;

        $status = match ($check['status']) {
            'current' => 'up-to-date',
            'missing' => 'missing',
            'outdated'=> 'outdated',
            'error'   => 'error',
            default   => 'outdated',
        };

        if (in_array($status, ['missing', 'outdated'], true)) $hasUpdates = true;

        $results[] = [
            'path'          => $file,
            'status'        => $status,
            'localVersion'  => $locVer,
            'remoteVersion' => $remVer,
            'hasUpdate'     => in_array($status, ['missing', 'outdated'], true),
            'httpStatus'    => $check['httpStatus'],
            'error'         => $check['error'],
        ];
    }

    echo json_encode([
        'localVersion'  => $localVer,
        'remoteVersion' => $remoteVer ?: $localVer,
        'hasUpdates'    => $hasUpdates,
        'files'         => $results,
    ], JSON_PRETTY_PRINT);
    exit;
}

function doApply(): never
{
    requireAllowUpdate();
    $updated   = [];
    $skipped   = [];
    $failed    = [];
    $backupVer = localVersion('md.php');

    $docsFiles = ['README.md', 'LICENSE'];
    foreach (TRACKED_FILES as $file) {
        $isDoc        = in_array($file, $docsFiles, true);
        $locVerBefore = $isDoc ? null : localVersion($file); // capture BEFORE apply()
        $updater      = makeUpdater($file);
        $result       = $updater->apply(backupVersion: $isDoc ? '' : $backupVer);

        match ($result['status']) {
            'current' => $skipped[] = $file,
            'updated', 'created' => $updated[] = [
                'path'        => $file,
                'fromVersion' => $locVerBefore,
                'toVersion'   => $isDoc ? null : localVersion($file),
            ],
            default => $failed[] = [
                'path'   => $file,
                'reason' => $result['error'] ?? 'unknown error',
            ],
        };
    }

    echo json_encode([
        'success'    => empty($failed),
        'updated'    => $updated,
        'skipped'    => $skipped,
        'failed'     => $failed,
        'backupVer'  => $backupVer,
        'newVersion' => localVersion('md.php'),
    ], JSON_PRETTY_PRINT);
    exit;
}

function doRestore(): never
{
    $reqVersion = trim($_POST['version'] ?? ($_GET['version'] ?? ''));
    if ($reqVersion === '') jsonError(400, 'version parameter required');

    $restoreDir = backupDir($reqVersion);
    if (!is_dir($restoreDir)) jsonError(404, 'Backup not found: ' . $reqVersion);

    $currentVer = localVersion('md.php');
    $restored   = [];
    $skipped    = [];
    $failed     = [];

    foreach (TRACKED_FILES as $file) {
        $backupSrc = $restoreDir . '/' . $file;
        if (!is_file($backupSrc)) { $skipped[] = $file; continue; }

        $locPath = localPath($file);
        $locVer  = localVersion($file);

        if (is_file($locPath) && $currentVer !== '') backupFile($file, $currentVer);

        $content = file_get_contents($backupSrc);
        if ($content === false) {
            $failed[] = ['path' => $file, 'reason' => 'Cannot read backup file'];
            continue;
        }

        if (!atomicWrite($locPath, $content)) {
            $failed[] = ['path' => $file, 'reason' => 'Write/rename failed'];
            continue;
        }

        // Invalidate ETag state so next check does a full fetch
        $stateFile = stateFile($file);
        if (is_file($stateFile)) @unlink($stateFile);

        $restored[] = [
            'path'        => $file,
            'fromVersion' => $locVer,
            'toVersion'   => extractVersion(substr($content, 0, 1000)),
        ];
    }

    echo json_encode([
        'success'           => empty($failed),
        'restoredFrom'      => $reqVersion,
        'backedUpCurrent'   => $currentVer,
        'restored'          => $restored,
        'skipped'           => $skipped,
        'failed'            => $failed,
        'newVersion'        => localVersion('md.php'),
    ], JSON_PRETTY_PRINT);
    exit;
}

// ── Index file (hard link) actions ───────────────────────────────────────────

/** Resolve absolute path to index.php in the same dir as md.php. */
function indexPath(): string
{
    return dirname(localPath('md.php')) . '/index.php';
}

/**
 * Returns true when index.php and md.php share the same inode
 * (i.e. index.php is a hard link to md.php).
 */
function indexIsLinked(): bool
{
    $idx = indexPath();
    $mdp = localPath('md.php');
    if (!is_file($idx) || !is_file($mdp)) return false;
    return stat($idx)['ino'] === stat($mdp)['ino'];
}

function doIndexStatus(): never
{
    $idx    = indexPath();
    $linked = indexIsLinked();
    $inode  = $linked ? (int) stat($idx)['ino'] : null;
    echo json_encode([
        'linked' => $linked,
        'exists' => is_file($idx),
        'inode'  => $inode,
    ]);
    exit;
}

function doIndexCreate(): never
{
    $idx = indexPath();
    $mdp = localPath('md.php');

    if (!is_file($mdp)) jsonError(500, 'md.php not found');

    // If a regular (non-linked) index.php already exists — refuse; user must remove it manually
    if (is_file($idx) && !indexIsLinked()) {
        jsonError(409, 'index.php already exists as a regular file. Remove or rename it manually first.');
    }

    // Remove any existing (linked) index.php so link() doesn't fail
    if (is_file($idx)) @unlink($idx);

    if (!@link($mdp, $idx)) {
        // link() may fail on some hosts (cross-device, no permission)
        // Fall back to a tiny PHP wrapper that includes md.php
        $wrapper = "<?php\n// Auto-generated by MD.Viewer updater — includes md.php\nrequire __DIR__ . '/md.php';\n";
        if (!atomicWrite($idx, $wrapper)) jsonError(500, 'Both link() and file copy failed');
        echo json_encode(['success' => true, 'method' => 'include-wrapper']);
        exit;
    }

    echo json_encode(['success' => true, 'method' => 'hard-link', 'inode' => (int) stat($idx)['ino']]);
    exit;
}

function doIndexRemove(): never
{
    $idx = indexPath();
    if (!is_file($idx)) {
        echo json_encode(['success' => true, 'note' => 'index.php did not exist']);
        exit;
    }
    if (!@unlink($idx)) jsonError(500, 'Cannot remove index.php — check permissions');
    echo json_encode(['success' => true]);
    exit;
}

// ── .md File Upload ───────────────────────────────────────────────────────────

function doUploadMd(): never
{
    // ── 0. Check server-side disable flag from .md.ini ───────────────────────
    $iniPath = dirname(localPath('md.php')) . '/.md.ini';
    $ini     = is_file($iniPath) ? (@parse_ini_file($iniPath, false, INI_SCANNER_TYPED) ?: []) : [];
    if ((bool)($ini['DISABLE_UPLOAD'] ?? true)) {
        jsonError(403, 'File upload is disabled by server configuration (DISABLE_UPLOAD=true in .md.ini).');
    }

    // ── 1. Check upload was received ─────────────────────────────────────────
    if (empty($_FILES['md_file'])) {
        jsonError(400, 'No file received.');
    }

    $file  = $_FILES['md_file'];
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($error !== UPLOAD_ERR_OK) {
        $msgs = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload stopped by extension.',
        ];
        jsonError(500, $msgs[$error] ?? 'Upload error ' . $error);
    }

    // ── 2. Validate & sanitize filename ──────────────────────────────────────
    $raw = $file['name'] ?? '';

    // Keep only the basename (strip any directory component the browser may include)
    $raw = basename((string) $raw);

    // Must end with .md (case-insensitive)
    if (!preg_match('/\.md$/i', $raw)) {
        jsonError(400, 'Only .md files are allowed.');
    }

    // Reject traversal, null bytes, shell-special characters, leading dots
    if (
        str_contains($raw, '/')  ||
        str_contains($raw, '\\') ||
        str_contains($raw, '..')  ||
        str_contains($raw, "\x00") ||
        preg_match('/[\x00-\x1f<>:"|?*]/', $raw) ||
        preg_match('/^\./', $raw)  // leading dot
    ) {
        jsonError(400, 'Filename contains forbidden characters or patterns.');
    }

    if (strlen($raw) > 200) {
        jsonError(400, 'Filename too long (max 200 chars).');
    }

    // Normalise to lowercase .md extension
    $name = preg_replace('/\.md$/i', '.md', $raw);

    // ── 3. Validate MIME / content (must be plain text) ──────────────────────
    if ($file['size'] > 2 * 1024 * 1024) {
        jsonError(400, 'File too large (max 2 MB).');
    }

    // ── 4. Ensure uploads.md/ directory exists ──────────────────────────────
    $uploadsDir = dirname(localPath('md.php')) . '/uploads.md';
    if (!is_dir($uploadsDir)) {
        if (!@mkdir($uploadsDir, 0755, true)) {
            jsonError(500, 'Could not create uploads.md/ directory. Check permissions.');
        }
    }

    // ── 5. Destination path ───────────────────────────────────────────────────
    $dest = $uploadsDir . '/' . $name;

    if (is_file($dest)) {
        // Keep a backup of existing file
        $bak = $dest . '.bak.' . date('Ymd-His');
        @rename($dest, $bak);
    }

    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        jsonError(500, 'Could not save the file. Check directory permissions.');
    }

    echo json_encode(['success' => true, 'filename' => $name, 'path' => 'uploads.md/' . $name]);
    exit;
}

// ── Clipboard → File Save ─────────────────────────────────────────────────────

function doSaveClipboard(): never
{
    // ── 0. Check server-side disable flag ────────────────────────────────────
    $iniPath = dirname(localPath('md.php')) . '/.md.ini';
    $ini     = is_file($iniPath) ? (@parse_ini_file($iniPath, false, INI_SCANNER_TYPED) ?: []) : [];
    if ((bool)($ini['DISABLE_SAVE_CLIPBOARD_TO_FILE'] ?? true)) {
        jsonError(403, 'Save to File is disabled by server configuration (DISABLE_SAVE_CLIPBOARD_TO_FILE=true in .md.ini).');
    }

    // ── 1. Read and validate content ─────────────────────────────────────────
    $content = $_POST['content'] ?? '';
    if (!is_string($content) || trim($content) === '') {
        jsonError(400, 'Empty content.');
    }
    if (strlen($content) > 2 * 1024 * 1024) {
        jsonError(400, 'Content too large (max 2 MB).');
    }

    // ── 2. Validate filename ──────────────────────────────────────────────────
    $rawName = $_POST['filename'] ?? '';
    $rawName = basename((string) $rawName);

    if (!preg_match('/\.md$/i', $rawName)) {
        jsonError(400, 'Only .md filenames are allowed.');
    }
    if (
        str_contains($rawName, '/') ||
        str_contains($rawName, '\\') ||
        str_contains($rawName, '..') ||
        str_contains($rawName, "\x00") ||
        preg_match('/[\x00-\x1f<>:"|?*]/', $rawName) ||
        preg_match('/^\./', $rawName)
    ) {
        jsonError(400, 'Filename contains forbidden characters.');
    }
    if (strlen($rawName) > 200) {
        jsonError(400, 'Filename too long (max 200 chars).');
    }
    $name = preg_replace('/\.md$/i', '.md', $rawName);

    // ── 3. Ensure uploads.md/ directory exists ───────────────────────────────
    $uploadsDir = dirname(localPath('md.php')) . '/uploads.md';
    if (!is_dir($uploadsDir)) {
        if (!@mkdir($uploadsDir, 0755, true)) {
            jsonError(500, 'Could not create uploads.md/ directory. Check permissions.');
        }
    }

    $dest = $uploadsDir . '/' . $name;

    // ── 4. Write file ─────────────────────────────────────────────────────────
    if (file_put_contents($dest, $content, LOCK_EX) === false) {
        jsonError(500, 'Could not write file. Check directory permissions.');
    }

    echo json_encode(['success' => true, 'filename' => $name, 'path' => 'uploads.md/' . $name]);
    exit;
}



