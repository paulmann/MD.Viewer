<?php
/**
 * Markdown Viewer — Self-Updater
 * Version: 2.1.0
 * Author: Mikhail Deynekin
 * Site: https://Deynekin.com
 * Email: Mikhail@Deynekin.com
 *
 * Pure PHP 8.3+ self-update engine. No GitHub API, no tokens.
 * Uses HTTP Range requests (bytes=0-999) to fetch only the first 1000 bytes
 * per file for version checking, then full downloads only when applying.
 *
 * Version standard (all tracked files):
 *   PHP / JS / CSS  :  /** ...  *  * Version: X.Y.Z  ...
 * Parsed with: preg_match('/\*\s+Version:\s*(\d[\w.\-]+)/m', $head, $m)
 *
 * Endpoints (GET/POST ?action=):
 *   check    GET  — compare local vs remote versions (Range 1000 bytes each)
 *   apply    POST — backup old → full download → atomic replace outdated files
 *   restore  POST — backup current → restore from md.backup/{version}/
 *   backups  GET  — list available backup versions in md.backup/
 *   version  GET  — local version of md.php only (Settings badge)
 *
 * Backup layout:
 *   md.backup/
 *     2.4.0/
 *       md.php
 *       assets/js/md.js
 *       ... (only files that were replaced)
 *     2.5.0/
 *       ...
 *
 * Rules:
 *   - Never deletes local files absent from remote.
 *   - Atomic write: temp file → rename().
 *   - Backup before every replace (apply & restore).
 *   - Same-origin CORS guard; POST required for mutating actions.
 *
 * v2.0.0: Rewrote from GitHub API to raw.githubusercontent.com Range requests.
 * v2.1.0: Added backup-before-replace, restore-from-backup, backups listing.
 */
declare(strict_types=1);

// ── Configuration ────────────────────────────────────────────────────────────

const RAW_BASE = 'https://raw.githubusercontent.com/paulmann/MD.Viewer/refs/heads/main';

const TRACKED_FILES = [
    'md.php',
    'assets/js/md.js',
    'assets/js/tooltips.js',
    'assets/css/md.css',
    'assets/css/tooltips.css',
];

// ── Bootstrap ────────────────────────────────────────────────────────────────

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

// ── Core helpers ──────────────────────────────────────────────────────────────

function docRoot(): string
{
    return rtrim($_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__), '/\\');
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
    // Sanitize version — only allow alphanumeric, dots, hyphens
    $safe = preg_replace('/[^a-zA-Z0-9.\-]/', '_', $version);
    return backupRoot() . '/' . $safe;
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
    return extractVersion((string)$head);
}

/**
 * Copy a local file into md.backup/{version}/{file}, creating dirs as needed.
 * Returns true on success, false on failure.
 */
function backupFile(string $file, string $version): bool
{
    $src = localPath($file);
    if (!is_file($src)) return true; // nothing to backup

    $dir  = backupDir($version) . '/' . dirname($file);
    $dest = backupDir($version) . '/' . $file;

    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;
    return copy($src, $dest);
}

/**
 * Atomic write: write to tmp then rename.
 */
function atomicWrite(string $destPath, string $content): bool
{
    $dir = dirname($destPath);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;

    $tmp = $destPath . '.upd.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content) === false) {
        @unlink($tmp);
        return false;
    }
    if (!rename($tmp, $destPath)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function remoteGet(string $url, int $limit = 0): string|false
{
    $headers = "User-Agent: MDViewer-Updater/2.1\r\n"
             . "Accept: */*\r\n"
             . "Connection: close\r\n";

    if ($limit > 0) {
        $headers .= "Range: bytes=0-" . ($limit - 1) . "\r\n";
    }

    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'header'          => $headers,
            'timeout'         => ($limit > 0 ? 8 : 30),
            'ignore_errors'   => true,
            'follow_location' => 1,
        ],
        'ssl'  => ['verify_peer' => true],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    return ($body !== false) ? $body : false;
}

function jsonError(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

// ── Actions ───────────────────────────────────────────────────────────────────

function doVersion(): never
{
    echo json_encode(['version' => localVersion('md.php')]);
    exit;
}

function doBackups(): never
{
    $root = backupRoot();
    if (!is_dir($root)) {
        echo json_encode(['backups' => []]);
        exit;
    }

    $versions = [];
    $entries  = scandir($root);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $dir = $root . '/' . $entry;
        if (!is_dir($dir)) continue;
        // Only include if contains at least one tracked file
        $hasFiles = false;
        foreach (TRACKED_FILES as $file) {
            if (is_file($dir . '/' . $file)) { $hasFiles = true; break; }
        }
        if (!$hasFiles) continue;

        $versions[] = [
            'version'  => $entry,
            'date'     => date('Y-m-d H:i', filemtime($dir)),
            'files'    => array_values(array_filter(
                TRACKED_FILES,
                fn(string $f) => is_file($dir . '/' . $f)
            )),
        ];
    }

    // Sort newest first (by version string descending)
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
        $url    = rawUrl($file);
        $remote = remoteGet($url, 1000);
        $exists = is_file(localPath($file));

        if ($remote === false) {
            $results[] = [
                'path'          => $file,
                'status'        => 'error',
                'localVersion'  => localVersion($file),
                'remoteVersion' => null,
                'hasUpdate'     => false,
                'error'         => 'Remote unreachable',
            ];
            continue;
        }

        $remVer   = extractVersion($remote);
        $locVer   = localVersion($file);
        $upToDate = ($locVer !== '' && $remVer !== '' && $locVer === $remVer);

        if ($file === 'md.php') $remoteVer = $remVer;

        $status = match (true) {
            !$exists                                       => 'missing',
            $locVer === ''                                 => 'no-version',
            $upToDate                                      => 'up-to-date',
            version_compare($remVer, $locVer, '>')         => 'outdated',
            version_compare($remVer, $locVer, '<')         => 'newer-local',
            default                                        => 'outdated',
        };

        if (in_array($status, ['missing', 'outdated', 'no-version'], true)) {
            $hasUpdates = true;
        }

        $results[] = [
            'path'          => $file,
            'status'        => $status,
            'localVersion'  => $locVer,
            'remoteVersion' => $remVer,
            'hasUpdate'     => in_array($status, ['missing', 'outdated', 'no-version'], true),
        ];
    }

    echo json_encode([
        'localVersion'  => $localVer,
        'remoteVersion' => $remoteVer,
        'hasUpdates'    => $hasUpdates,
        'files'         => $results,
    ], JSON_PRETTY_PRINT);
    exit;
}

function doApply(): never
{
    $updated    = [];
    $skipped    = [];
    $failed     = [];
    $backupVer  = localVersion('md.php'); // snapshot version before any writes

    foreach (TRACKED_FILES as $file) {
        $url     = rawUrl($file);
        $locPath = localPath($file);
        $exists  = is_file($locPath);

        // Quick version check (1000 bytes)
        $head = remoteGet($url, 1000);
        if ($head === false) {
            $failed[] = ['path' => $file, 'reason' => 'Remote unreachable'];
            continue;
        }

        $remVer = extractVersion($head);
        $locVer = localVersion($file);

        if ($exists && $remVer !== '' && $locVer === $remVer) {
            $skipped[] = $file;
            continue;
        }

        // Full download
        $content = remoteGet($url, 0);
        if ($content === false || strlen($content) < 64) {
            $failed[] = ['path' => $file, 'reason' => 'Download failed or empty'];
            continue;
        }

        // Backup old version before replacing
        if ($exists && $backupVer !== '') {
            backupFile($file, $backupVer);
        }

        if (!atomicWrite($locPath, $content)) {
            $failed[] = ['path' => $file, 'reason' => 'Write/rename failed'];
            continue;
        }

        $updated[] = [
            'path'        => $file,
            'fromVersion' => $locVer,
            'toVersion'   => $remVer ?: extractVersion($content),
        ];
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
    if (!is_dir($restoreDir)) {
        jsonError(404, 'Backup not found: ' . $reqVersion);
    }

    $currentVer = localVersion('md.php');
    $restored   = [];
    $skipped    = [];
    $failed     = [];

    foreach (TRACKED_FILES as $file) {
        $backupSrc = $restoreDir . '/' . $file;
        if (!is_file($backupSrc)) {
            $skipped[] = $file; // this file wasn't in that backup
            continue;
        }

        $locPath = localPath($file);
        $locVer  = localVersion($file);

        // Backup current version before restoring
        if (is_file($locPath) && $currentVer !== '') {
            backupFile($file, $currentVer);
        }

        $content = file_get_contents($backupSrc);
        if ($content === false) {
            $failed[] = ['path' => $file, 'reason' => 'Cannot read backup file'];
            continue;
        }

        if (!atomicWrite($locPath, $content)) {
            $failed[] = ['path' => $file, 'reason' => 'Write/rename failed'];
            continue;
        }

        $restored[] = [
            'path'        => $file,
            'fromVersion' => $locVer,
            'toVersion'   => extractVersion($content),
        ];
    }

    echo json_encode([
        'success'       => empty($failed),
        'restoredFrom'  => $reqVersion,
        'backedUpCurrent' => $currentVer,
        'restored'      => $restored,
        'skipped'       => $skipped,
        'failed'        => $failed,
        'newVersion'    => localVersion('md.php'),
    ], JSON_PRETTY_PRINT);
    exit;
}
