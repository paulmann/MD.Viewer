<?php
/**
 * Markdown Viewer — Self-Updater
 * Version: 3.0.1
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
 * v3.0.1: updater.php added to TRACKED_FILES — now self-updates.
 * v3.0.0: RawFileUpdater class — ETag + SHA-256 conditional updates.
 */
declare(strict_types=1);

// ── Configuration ─────────────────────────────────────────────────────────────

const RAW_BASE = 'https://raw.githubusercontent.com/paulmann/MD.Viewer/refs/heads/main';

const TRACKED_FILES = [
    'md.php',
    'updater.php',
    'assets/js/md.js',
    'assets/js/tooltips.js',
    'assets/css/md.css',
    'assets/css/tooltips.css',
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
if (in_array($action, ['apply', 'restore'], true) && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required for ' . $action]);
    exit;
}

match ($action) {
    'check'   => doCheck(),
    'apply'   => doApply(),
    'restore' => doRestore(),
    'backups' => doBackups(),
    'version' => doVersion(),
    default   => jsonError(400, 'Unknown action'),
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
    $updated   = [];
    $skipped   = [];
    $failed    = [];
    $backupVer = localVersion('md.php');

    foreach (TRACKED_FILES as $file) {
        $locVerBefore = localVersion($file); // capture BEFORE apply() replaces file
        $updater      = makeUpdater($file);
        $result       = $updater->apply(backupVersion: $backupVer);

        match ($result['status']) {
            'current' => $skipped[] = $file,
            'updated', 'created' => $updated[] = [
                'path'        => $file,
                'fromVersion' => $locVerBefore,
                'toVersion'   => localVersion($file), // re-read after write
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
