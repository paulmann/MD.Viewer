<?php
/**
 * MD Viewer — Updater
 * Version: 1.0.0
 * Author: Mikhail Deynekin
 * Site: https://Deynekin.com
 *
 * Checks and applies updates from GitHub for tracked MD Viewer files.
 * Never deletes local files that are absent from the remote repository.
 *
 * Usage:
 *   updater.php?action=check           — returns JSON status of all tracked files
 *   updater.php?action=apply           — downloads and overwrites updated files
 *   updater.php?action=version         — returns current local version only
 *
 * Security: requests must include header X-Updater-Token matching UPDATER_TOKEN.
 * Set UPDATER_TOKEN to a secret value in your environment or edit below.
 *
 * Called from the Settings panel in md.php via fetch().
 */
declare(strict_types=1);

// ── Configuration ────────────────────────────────────────────────────────────

const GITHUB_OWNER  = 'paulmann';
const GITHUB_REPO   = 'MD.Viewer';
const GITHUB_BRANCH = 'main';

// Secret token — set via env var MDV_UPDATER_TOKEN or change default here.
// Must match the value sent in X-Updater-Token header from the browser.
const UPDATER_TOKEN = 'mdv-update-2025';

// Files to track (relative to document root = repo root).
// Never deleted locally even if absent from GitHub.
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

// CORS — allow same origin only
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $originHost = parse_url($origin, PHP_URL_HOST) ?? '';
    if ($originHost !== $host) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden origin']);
        exit;
    }
}

// ── Auth ──────────────────────────────────────────────────────────────────────
$token = $_SERVER['HTTP_X_UPDATER_TOKEN'] ?? ($_GET['token'] ?? '');
if (!hash_equals(UPDATER_TOKEN, $token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized — invalid updater token']);
    exit;
}

// ── Action routing ────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? 'check';

match ($action) {
    'check'   => doCheck(),
    'apply'   => doApply(),
    'version' => doVersion(),
    default   => badRequest('Unknown action'),
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function docRoot(): string
{
    return rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
}

function localPath(string $file): string
{
    return docRoot() . '/' . ltrim($file, '/');
}

function localSha(string $file): string
{
    $path = localPath($file);
    if (!is_file($path)) return '';
    // GitHub blob SHA: sha1("blob {size}\0{content}")
    $content = file_get_contents($path);
    if ($content === false) return '';
    return sha1('blob ' . strlen($content) . "\0" . $content);
}

function githubApiUrl(string $path): string
{
    return 'https://api.github.com/repos/' . GITHUB_OWNER . '/' . GITHUB_REPO
         . '/contents/' . ltrim($path, '/') . '?ref=' . GITHUB_BRANCH;
}

function githubGet(string $url): array|null
{
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "User-Agent: MDViewer-Updater/1.0\r\n"
                   . "Accept: application/vnd.github.v3+json\r\n",
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function githubRaw(string $url): string|null
{
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "User-Agent: MDViewer-Updater/1.0\r\n",
        'timeout' => 30,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return ($body !== false) ? $body : null;
}

function extractVersion(string $content): string
{
    if (preg_match('/\*\s+Version:\s*(\S+)/', $content, $m)) return $m[1];
    return 'unknown';
}

function localVersion(): string
{
    $path = localPath('md.php');
    if (!is_file($path)) return 'unknown';
    // Read only first 3 KB — version is in the header comment
    $fh = fopen($path, 'r');
    if (!$fh) return 'unknown';
    $head = fread($fh, 3072);
    fclose($fh);
    return extractVersion($head);
}

function remoteVersion(array $remoteInfo): string
{
    // remoteInfo is the GitHub API response for md.php
    $raw = base64_decode(str_replace(["\n", "\r"], '', $remoteInfo['content'] ?? ''));
    if ($raw === false || $raw === '') return 'unknown';
    return extractVersion(substr($raw, 0, 3072));
}

function badRequest(string $msg): never
{
    http_response_code(400);
    echo json_encode(['error' => $msg]);
    exit;
}

// ── Actions ───────────────────────────────────────────────────────────────────

function doVersion(): never
{
    echo json_encode(['version' => localVersion()]);
    exit;
}

function doCheck(): never
{
    $results      = [];
    $hasUpdates   = false;
    $remoteVer    = null;
    $localVer     = localVersion();

    foreach (TRACKED_FILES as $file) {
        $apiUrl   = githubApiUrl($file);
        $remote   = githubGet($apiUrl);
        $localSha = localSha($file);
        $exists   = is_file(localPath($file));

        if ($remote === null) {
            $results[] = [
                'path'      => $file,
                'status'    => 'error',
                'message'   => 'GitHub API unreachable',
                'localSha'  => $localSha,
                'remoteSha' => null,
                'hasUpdate' => false,
            ];
            continue;
        }

        if (isset($remote['message'])) {
            // File not found on GitHub — skip, never delete locally
            $results[] = [
                'path'      => $file,
                'status'    => $exists ? 'local-only' : 'missing',
                'localSha'  => $localSha,
                'remoteSha' => null,
                'hasUpdate' => false,
            ];
            continue;
        }

        $remoteSha = $remote['sha'] ?? '';
        $upToDate  = ($localSha !== '' && $localSha === $remoteSha);

        if (!$upToDate) $hasUpdates = true;

        // Extract remote version from md.php
        if ($file === 'md.php' && $remoteVer === null) {
            $remoteVer = remoteVersion($remote);
        }

        $results[] = [
            'path'        => $file,
            'status'      => $upToDate ? 'up-to-date' : ($exists ? 'outdated' : 'missing'),
            'localSha'    => $localSha,
            'remoteSha'   => $remoteSha,
            'hasUpdate'   => !$upToDate,
            'downloadUrl' => $remote['download_url'] ?? null,
        ];
    }

    echo json_encode([
        'localVersion'  => $localVer,
        'remoteVersion' => $remoteVer ?? $localVer,
        'hasUpdates'    => $hasUpdates,
        'files'         => $results,
    ]);
    exit;
}

function doApply(): never
{
    $updated = [];
    $failed  = [];
    $skipped = [];

    foreach (TRACKED_FILES as $file) {
        $apiUrl  = githubApiUrl($file);
        $remote  = githubGet($apiUrl);

        if ($remote === null || isset($remote['message'])) {
            // Not on GitHub — skip, never delete
            $skipped[] = $file;
            continue;
        }

        $remoteSha = $remote['sha'] ?? '';
        $localSha  = localSha($file);

        if ($localSha === $remoteSha && $localSha !== '') {
            $skipped[] = $file;
            continue;
        }

        $downloadUrl = $remote['download_url'] ?? null;
        if (!$downloadUrl) { $failed[] = $file; continue; }

        $content = githubRaw($downloadUrl);
        if ($content === null) { $failed[] = $file; continue; }

        $localFile = localPath($file);
        $dir = dirname($localFile);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $failed[] = $file;
            continue;
        }

        // Write to temp file then rename — atomic
        $tmp = $localFile . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content) === false) {
            @unlink($tmp);
            $failed[] = $file;
            continue;
        }
        if (!rename($tmp, $localFile)) {
            @unlink($tmp);
            $failed[] = $file;
            continue;
        }

        $updated[] = $file;
    }

    $success = empty($failed);
    echo json_encode([
        'success'   => $success,
        'updated'   => $updated,
        'skipped'   => $skipped,
        'failed'    => $failed,
        'newVersion'=> localVersion(), // re-read after update
    ]);
    exit;
}
