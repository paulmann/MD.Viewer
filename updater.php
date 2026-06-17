<?php
/**
 * Markdown Viewer — Self-Updater
 * Version: 2.0.0
 * Author: Mikhail Deynekin
 * Site: https://Deynekin.com
 * Email: Mikhail@Deynekin.com
 *
 * Pure PHP 8.3+ self-update engine. No GitHub API, no tokens.
 * Uses HTTP Range requests to fetch only the first 1000 bytes per file
 * for version checking, then full downloads only when applying updates.
 *
 * Version standard (all tracked files):
 *   PHP / JS  :  /** ... \n *  * Version: X.Y.Z\n *  ...
 *   CSS       :  /* ...  \n *  * Version: X.Y.Z\n *  ...
 * The updater reads the first 1000 bytes and looks for " * Version: X.Y.Z".
 *
 * Endpoints (GET ?action=):
 *   check   — compare local vs remote versions (Range: bytes=0-999 per file)
 *   apply   — full download + atomic replace of outdated files
 *   version — local version of md.php only (used by Settings badge)
 *
 * Never deletes local files that are absent from the remote.
 * Atomic write: temp file → rename() to prevent partial writes.
 *
 * v2.0.0: Rewrote from GitHub API to raw.githubusercontent.com Range requests.
 *         Unified version format across PHP/JS/CSS (" * Version: X.Y.Z").
 *         Removed token auth — runs purely via HTTP.
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

// Same-origin guard
$reqOrigin   = $_SERVER['HTTP_ORIGIN'] ?? '';
$serverHost  = $_SERVER['HTTP_HOST']   ?? '';
if ($reqOrigin !== '') {
    $originHost = parse_url($reqOrigin, PHP_URL_HOST) ?? '';
    if ($originHost !== $serverHost) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden origin']);
        exit;
    }
}

// Only allow POST for mutating actions, GET for read-only
$action = $_REQUEST['action'] ?? 'check';
if ($action === 'apply' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required for apply']);
    exit;
}

match ($action) {
    'check'   => doCheck(),
    'apply'   => doApply(),
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

function rawUrl(string $file): string
{
    return RAW_BASE . '/' . ltrim($file, '/');
}

/**
 * Extract " * Version: X.Y.Z" from a string (first 1000 bytes is enough).
 */
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
 * Fetch remote content with HTTP Range: bytes=0-{limit-1}.
 * Falls back to full fetch if server does not honour Range (206).
 *
 * @param  int  $limit  0 = full file
 * @return string|false
 */
function remoteGet(string $url, int $limit = 0): string|false
{
    $headers = "User-Agent: MDViewer-Updater/2.0\r\n"
             . "Accept: */*\r\n"
             . "Connection: close\r\n";

    if ($limit > 0) {
        $headers .= "Range: bytes=0-" . ($limit - 1) . "\r\n";
    }

    $ctx = stream_context_create([
        'http' => [
            'method'         => 'GET',
            'header'         => $headers,
            'timeout'        => ($limit > 0 ? 8 : 30),
            'ignore_errors'  => true,
            'follow_location'=> 1,
        ],
        'ssl'  => ['verify_peer' => true],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return false;

    // If Range was honoured, $http_response_header[0] has "HTTP/... 206"
    // Either way we have the content we need.
    return $body;
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

function doCheck(): never
{
    $results    = [];
    $hasUpdates = false;
    $localVer   = localVersion('md.php');
    $remoteVer  = '';

    foreach (TRACKED_FILES as $file) {
        $url      = rawUrl($file);
        $remote   = remoteGet($url, 1000);
        $exists   = is_file(localPath($file));

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

        $remVer  = extractVersion($remote);
        $locVer  = localVersion($file);

        // Version comparison: semver-aware where possible
        $upToDate = ($locVer !== '' && $remVer !== '' && $locVer === $remVer);
        if (!$upToDate && $locVer !== '' && $remVer !== '') {
            $hasUpdates = true;
        }

        if ($file === 'md.php') $remoteVer = $remVer;

        $status = match(true) {
            !$exists                  => 'missing',
            $locVer === ''            => 'no-version',
            $upToDate                 => 'up-to-date',
            version_compare($remVer, $locVer, '>') => 'outdated',
            version_compare($remVer, $locVer, '<') => 'newer-local',
            default                   => 'outdated',
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
    $updated = [];
    $skipped = [];
    $failed  = [];

    foreach (TRACKED_FILES as $file) {
        $url     = rawUrl($file);
        $locPath = localPath($file);
        $exists  = is_file($locPath);

        // Quick version check first (1000 bytes)
        $head = remoteGet($url, 1000);
        if ($head === false) {
            $failed[] = ['path' => $file, 'reason' => 'Remote unreachable'];
            continue;
        }

        $remVer = extractVersion($head);
        $locVer = localVersion($file);

        // Skip if same version (and file exists)
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

        // Ensure directory exists
        $dir = dirname($locPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $failed[] = ['path' => $file, 'reason' => 'Cannot create directory'];
            continue;
        }

        // Atomic write: tmp → rename
        $tmp = $locPath . '.upd.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content) === false) {
            @unlink($tmp);
            $failed[] = ['path' => $file, 'reason' => 'Write failed'];
            continue;
        }
        if (!rename($tmp, $locPath)) {
            @unlink($tmp);
            $failed[] = ['path' => $file, 'reason' => 'Rename failed'];
            continue;
        }

        $updated[] = [
            'path'       => $file,
            'fromVersion'=> $locVer,
            'toVersion'  => $remVer ?: extractVersion($content),
        ];
    }

    echo json_encode([
        'success'    => empty($failed),
        'updated'    => $updated,
        'skipped'    => $skipped,
        'failed'     => $failed,
        'newVersion' => localVersion('md.php'),
    ], JSON_PRETTY_PRINT);
    exit;
}
