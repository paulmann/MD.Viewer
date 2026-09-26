<?php
/**
 * Markdown Viewer — Self-Updater
 * Version: 3.12.0
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
 * Machine API (JSON only, API-key protected — API_KEY in .md.ini):
 *   status    GET  — local install state, no network I/O
 *   conflicts GET  — pre-install conflict report, never writes
 *   install   POST — install/update with a pre-write conflict guard
 *
 *   updater.php?api_key=<API_KEY>&action=status|conflicts|install&format=json
 *   install also accepts &dry_run=1 (report only) and &force=1 (ignore local
 *   state: overwrite foreign files and bypass the ETag/SHA-256 cache).
 *   The key is generated on first run and stored in .md.ini. A valid key
 *   authorises the machine API; ALLOW_UPDATE / ALLOW_RESTORE keep controlling
 *   the browser modes (?update=true, ?restore=) exactly as before.
 *
 * Rules:
 *   - Never deletes local files absent from remote.
 *   - Foreign files sitting at a tracked path are never overwritten silently:
 *     they are skipped and reported until force=1 is requested.
 *   - Same-origin CORS guard; POST required for mutating actions.
 *   - cURL required (curl extension).
 *   - The machine API emits strictly JSON: no HTML, no PHP notices in the body.
 *   - updater.php updates itself safely: rename() is atomic on the same filesystem,
 *     and PHP has already loaded the current script into memory/opcache for the
 *     running request. The new version takes effect from the next request onward.
 *
 * v3.12.0: per-directory .md.ini. upload_md and save_clipboard accept an optional
 *          "dir" parameter naming a directory below the browse root. The
 *          DISABLE_* settings of that directory decide whether the action is
 *          allowed and the file is written there (the directory is created when
 *          missing). Only DISABLE_UPLOAD, DISABLE_CLIPBOARD and
 *          DISABLE_SAVE_CLIPBOARD_TO_FILE are read from such files;
 *          installation-level keys are ignored and reported. A directory whose
 *          realpath() escapes realpath(BROWSE_DIR), or a "dir" value carrying
 *          traversal segments, an absolute path or a null byte, is refused with
 *          400 before anything is read or written. Without the parameter the
 *          previous behaviour is untouched.
 * v3.11.0: every state-changing entry point - the legacy actions (apply, restore,
 *          index_create, index_remove, upload_md, save_clipboard), the machine
 *          install and the browser
 *          ?update=true / ?restore= modes - refuses cross-site requests: a request
 *          whose Origin (and, for these actions, Referer) names another host is
 *          answered with 403 before anything is read or written. Read-only
 *          requests keep the previous policy of checking Origin alone, so a page
 *          opened from a link on another site still works. Clients that send
 *          neither header (CLI tools, server-side integrations) are unaffected.
 *          The host comparison is now port-insensitive: the old machine-API check
 *          compared the port as well and rejected same-origin requests that used
 *          a non-standard port.
 * v3.10.1: .md.ini, md.php and the companion files are resolved in the directory
 *          that contains updater.php instead of DOCUMENT_ROOT, so an install in a
 *          subdirectory reads the very file the installer writes: upload_md and
 *          save_clipboard get the settings the panel shows and the machine API
 *          key matches again. A one-file install in the document root is unchanged.
 * v3.10.0: upload_md and save_clipboard now honour .md.ini BROWSE_DIR - uploaded
 *          and saved documents land in the directory the viewer browses instead
 *          of uploads.md/. Without a usable BROWSE_DIR the classic uploads.md/
 *          behaviour is unchanged.
 * v3.9.0: Machine JSON API — ?api_key=<key>&action=status|conflicts|install&format=json.
 *         Pre-write conflict report (exact path / same name elsewhere) with dry run
 *         and force override; API_KEY auto-created in .md.ini; assets relocated
 *         from assets/js|assets/css to js/md|css/md.
 * v2.0.0: Raw Range requests, no API/tokens.
 * v2.1.0: Backup-before-replace, restore-from-backup.
 * v3.8.2: doBackups() guarded with requireAllowRestore() — no info leak.
 * v3.8.1: ALLOW_CREATE_INDEX_PHP_LINK — guard index_create/remove/status actions.
 * v3.8.0: one-file install; readIni() creates .md.ini; landing page; full auto-append.
 * v3.7.0: &force=true; fix version display for all files; file links in HTML output.
 * v3.6.0: ALLOW_RESTORE flag; direct ?restore=latest|[version] browser mode.
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
    'js/md/md.js',
    'js/md/settings.js',
    'js/md/tooltips.js',
    'js/md/upload.js',
    // CSS
    'css/md/md.css',
    'css/md/settings.css',
    'css/md/tooltips.css',
    // Docs (read-only: never backed up, never force-replaced if local edits exist)
    'README.md',
    'LICENSE',
];

// Directories skipped when looking for "same file name, different place"
// conflicts. VCS metadata and the updater's own runtime directories are never
// treated as clashes.
const CONFLICT_SCAN_SKIP_DIRS = ['.git', 'md.backup', 'node_modules', 'vendor'];

// Upper bound for the conflict scan traversal (DoS guard, mirrors md.php).
const CONFLICT_SCAN_MAX_FILES = 20000;

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
     * Pre-write guard: a file that does not look like an MD.Viewer file is never
     * replaced unless $force is set, so installs cannot silently overwrite
     * foreign files (see writeBlockedReason()).
     *
     * @return array{status: string, error: ?string}
     */
    public function apply(string $backupVersion = '', bool $force = false): array
    {
        $blocked = writeBlockedReason($this->localPath, $force);
        if ($blocked !== null) {
            return ['status' => 'blocked', 'error' => $blocked];
        }

        $result = $this->check();

        if ($result['status'] === 'error') {
            return ['status' => 'error', 'error' => $result['error']];
        }
        if (!$force && $result['status'] === 'current') {
            return ['status' => 'current', 'error' => null];
        }

        // Body may already be available from check() to avoid double-download
        $body = $result['body'] ?? null;
        if ($body === null || $force) {
            // Force: always re-fetch ignoring ETag/hash cache
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

        $this->saveState($respHeaders['etag'] ?? null, hash('sha256', $body));

        return [
            'status' => ($result['status'] === 'missing' || (!isset($result['body']) && $force)) ? 'force-updated' : 'updated',
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

/**
 * Directory that holds md.php, .md.ini and the companion files (v3.10.1).
 *
 * The directory of updater.php comes first: the installer writes .md.ini next
 * to md.php, so reading it from DOCUMENT_ROOT silently used a different file
 * (or created a stray one there) whenever MD.Viewer was installed in a
 * subdirectory. When md.php is absent - a one-file install - the document root
 * is used, which keeps the classic layout working.
 */
function docRoot(): string
{
    static $root = null;
    if ($root !== null) {
        return $root;
    }
    $self = rtrim(dirname(__FILE__), '/\\');
    if (is_file($self . '/md.php')) {
        return $root = $self;
    }
    $doc = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
    if ($doc !== '' && is_file($doc . '/md.php')) {
        return $root = $doc;
    }
    return $root = $self;
}

function readIni(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $path = docRoot() . '/.md.ini';

    // Create .md.ini with safe defaults if it doesn't exist yet
    // (supports one-file install where only updater.php is present)
    if (!is_file($path)) {
        $default  = "; MD.Viewer server-side configuration\n";
        $default .= "; Generated automatically. Edit on the server to change settings.\n\n";
        $default .= "; Disable the Upload .md button in the file browser\n";
        $default .= "DISABLE_UPLOAD    = true\n\n";
        $default .= "; Disable the Clipboard Preview button\n";
        $default .= "DISABLE_CLIPBOARD = false\n\n";
        $default .= "; Disable the Save to File button in clipboard preview\n";
        $default .= "DISABLE_SAVE_CLIPBOARD_TO_FILE = true\n\n";
        $default .= "; Allow updating files via updater.php?update=true or the Settings panel\n";
        $default .= "; Set to true only on servers you control\n";
        $default .= "ALLOW_UPDATE = false\n\n";
        $default .= "; Allow restoring a backup via updater.php?restore=latest or ?restore=[version]\n";
        $default .= "ALLOW_RESTORE = false\n\n";
        $default .= "; Allow creating/removing the index.php hard link from the Settings panel\n";
        $default .= "ALLOW_CREATE_INDEX_PHP_LINK = true\n\n";
        $default .= "; Machine API key - used by external clients (e.g. a control panel) for\n";
        $default .= "; updater.php?api_key=<value>&action=status|conflicts|install&format=json\n";
        $default .= "; Keep it secret: it authorises file installs. Rotate by editing this line.\n";
        $default .= "API_KEY = " . generateApiKey() . "\n";
        @file_put_contents($path, $default, LOCK_EX);
    }

    $cache = @parse_ini_file($path, false, INI_SCANNER_TYPED) ?: [];

    // Auto-append any keys missing from older .md.ini files
    $appendIni = '';
    if (!array_key_exists('ALLOW_UPDATE', $cache)) {
        $appendIni .= "\n; Allow updating files via updater.php?update=true or the Settings panel\n";
        $appendIni .= "ALLOW_UPDATE = false\n";
        $cache['ALLOW_UPDATE'] = false;
    }
    if (!array_key_exists('ALLOW_RESTORE', $cache)) {
        $appendIni .= "\n; Allow restoring a backup via updater.php?restore=latest or ?restore=[version]\n";
        $appendIni .= "ALLOW_RESTORE = false\n";
        $cache['ALLOW_RESTORE'] = false;
    }
    if (!array_key_exists('ALLOW_CREATE_INDEX_PHP_LINK', $cache)) {
        $appendIni .= "\n; Allow creating/removing the index.php hard link from the Settings panel\n";
        $appendIni .= "ALLOW_CREATE_INDEX_PHP_LINK = true\n";
        $cache['ALLOW_CREATE_INDEX_PHP_LINK'] = true;
    }
    if (!array_key_exists('DISABLE_UPLOAD', $cache)) {
        $appendIni .= "\n; Disable the Upload .md button in the file browser\n";
        $appendIni .= "DISABLE_UPLOAD = true\n";
        $cache['DISABLE_UPLOAD'] = true;
    }
    if (!array_key_exists('DISABLE_CLIPBOARD', $cache)) {
        $appendIni .= "\n; Disable the Clipboard Preview button\n";
        $appendIni .= "DISABLE_CLIPBOARD = false\n";
        $cache['DISABLE_CLIPBOARD'] = false;
    }
    if (!array_key_exists('DISABLE_SAVE_CLIPBOARD_TO_FILE', $cache)) {
        $appendIni .= "\n; Disable the Save to File button in clipboard preview\n";
        $appendIni .= "DISABLE_SAVE_CLIPBOARD_TO_FILE = true\n";
        $cache['DISABLE_SAVE_CLIPBOARD_TO_FILE'] = true;
    }

    // Machine API key - created once, then reused as-is. It is never regenerated
    // silently, because a rotation would break every configured client.
    if (!array_key_exists('API_KEY', $cache) || !is_string($cache['API_KEY']) || $cache['API_KEY'] === '') {
        $iniRaw = @file_get_contents($path) ?: '';
        if (preg_match('/^[ \t]*API_KEY[ \t]*=/mi', $iniRaw)) {
            // The key is present in the file but unreadable (INI syntax error).
            // Do not append a second one - report the situation to the caller.
            $cache['API_KEY'] = '';
        } else {
            $newApiKey = generateApiKey();
            $appendIni .= "\n; Machine API key - used by external clients (e.g. a control panel) for\n";
            $appendIni .= "; updater.php?api_key=<value>&action=status|conflicts|install&format=json\n";
            $appendIni .= "; Keep it secret: it authorises file installs. Rotate by editing this line.\n";
            $appendIni .= "API_KEY = " . $newApiKey . "\n";
            $cache['API_KEY'] = $newApiKey;
        }
    }
    if ($appendIni !== '') {
        @file_put_contents($path, $appendIni, FILE_APPEND | LOCK_EX);
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

function requireAllowRestore(): void
{
    $ini = readIni();
    if (!(bool)($ini['ALLOW_RESTORE'] ?? false)) {
        jsonError(403, 'Restore is disabled. Set ALLOW_RESTORE = true in .md.ini to enable.');
    }
}

function requireAllowIndexLink(): void
{
    $ini = readIni();
    if (!(bool)($ini['ALLOW_CREATE_INDEX_PHP_LINK'] ?? true)) {
        jsonError(403, 'Index link management is disabled. Set ALLOW_CREATE_INDEX_PHP_LINK = true in .md.ini to enable.');
    }
}

function localPath(string $file): string
{
    return docRoot() . '/' . ltrim($file, '/\\');
}

/**
 * Normalised .md.ini BROWSE_DIR value (v3.10.0).
 *
 * Same rule as md.php: trimmed, optional surrounding quotes stripped, values
 * longer than 255 bytes or containing a null byte discarded. Returns '' when the
 * key is absent, empty or unusable.
 *
 * @param array<string, mixed> $ini Parsed .md.ini values.
 */
function browseDirValue(array $ini): string
{
    $value = $ini['BROWSE_DIR'] ?? '';
    if (is_array($value)) {
        $value = ''; // guard against key[] syntax in .md.ini
    }
    $value = trim((string) $value);
    if (strlen($value) >= 2) {
        $first = $value[0];
        $last  = $value[strlen($value) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $value = trim(substr($value, 1, -1));
        }
    }
    if ($value === '') return '';
    if (strlen($value) > 255 || str_contains($value, "\0")) {
        error_log('[MD.Viewer updater] .md.ini BROWSE_DIR ignored: value is too long or contains a null byte.');
        return '';
    }
    return $value;
}

/**
 * Destination directory configured through .md.ini BROWSE_DIR (v3.10.0).
 *
 * Resolution order mirrors md.php: an absolute value as-is, then
 * DOCUMENT_ROOT + value, then the md.php directory + value. The first candidate
 * that exists, is a directory and is writable wins. Returns the absolute
 * directory plus the client-facing label (the configured value with a trailing
 * "/"), or null when BROWSE_DIR is absent, empty or resolves to nothing usable.
 * The label is what the JSON answer reports - never a server absolute path.
 *
 * @param array<string, mixed> $ini Parsed .md.ini values.
 * @return array{0: string, 1: string}|null
 */
function resolveBrowseDir(array $ini): ?array
{
    $value = browseDirValue($ini);
    if ($value === '') return null;

    $candidates = [];
    if (str_starts_with($value, '/')
        || str_starts_with($value, '\\')
        || preg_match('#^[A-Za-z]:[\\\\/]#', $value) === 1) {
        $candidates[] = $value; // absolute path as-is
    }
    $docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($docRoot !== '') {
        $candidates[] = rtrim($docRoot, '/\\') . '/' . ltrim($value, '/\\');
    }
    $candidates[] = dirname(localPath('md.php')) . '/' . ltrim($value, '/\\');

    foreach ($candidates as $candidate) {
        $real = @realpath($candidate);
        if ($real !== false && is_dir($real) && is_writable($real)) {
            return [$real, rtrim($value, '/\\') . '/'];
        }
    }

    error_log('[MD.Viewer updater] .md.ini BROWSE_DIR "' . $value
        . '" cannot be resolved to a writable directory.');
    return null;
}

/**
 * Directory uploads and "Save to File" write into (v3.10.0).
 *
 * BROWSE_DIR resolved to a writable directory -> write there, creating it when
 * missing. BROWSE_DIR absent -> the classic uploads.md/ next to md.php, byte for
 * byte the previous code path. BROWSE_DIR set but unusable -> a clear JSON error
 * instead of silently dropping the document into a directory the viewer does not
 * list.
 *
 * @param array<string, mixed> $ini Parsed .md.ini values.
 * @return array{0: string, 1: string} Absolute destination directory and its label.
 */
function destinationDir(array $ini): array
{
    $browse = resolveBrowseDir($ini);

    if ($browse !== null) {
        $dir   = $browse[0];
        $label = $browse[1];
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            jsonError(500, 'Could not create ' . $label . ' directory. Check permissions.');
        }
        if (!is_writable($dir)) {
            jsonError(500, 'Directory ' . $label . ' is not writable. Check permissions.');
        }
    } elseif (browseDirValue($ini) === '') {
        $dir   = dirname(localPath('md.php')) . '/uploads.md';
        $label = 'uploads.md/';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                jsonError(500, 'Could not create uploads.md/ directory. Check permissions.');
            }
        }
    } else {
        jsonError(500, 'BROWSE_DIR in .md.ini does not point to a writable directory. Fix the value or its permissions.');
    }

    return [$dir, $label];
}

// ----------------------------------------------------------------------------
// Per-directory .md.ini (v3.12.0)
// ----------------------------------------------------------------------------
//
// The .md.ini next to md.php holds the installation-level settings. Any
// directory inside the browse root may add its own .md.ini: the effective
// settings of a directory are the base file with every .md.ini found from the
// browse root down to that directory applied on top, so a deeper file wins over
// a shallower one.
//
// upload_md and save_clipboard accept an optional "dir" parameter naming a
// directory below the browse root. The settings of that directory decide
// whether the action is allowed and the file is written there (the directory is
// created when missing). Without the parameter the classic rules are untouched.
//
// Only DISABLE_UPLOAD, DISABLE_CLIPBOARD and DISABLE_SAVE_CLIPBOARD_TO_FILE are
// honoured in a directory file. BROWSE_DIR, API_KEY, ALLOW_UPDATE,
// ALLOW_RESTORE and ALLOW_CREATE_INDEX_PHP_LINK stay installation-level: they
// are ignored and reported to the error log.
//
// A value is parsed with the same strictness as the base file (trimmed,
// optional paired quotes stripped, a value containing a null byte, a newline, a
// carriage return or more than 255 bytes discarded). An unreadable file or an
// unusable value is skipped with a log entry - never a fatal error.
//
// Security: a .md.ini is only read when its directory realpath() is still
// inside realpath(browse root); a "dir" value that climbs out with "..", an
// absolute path or a null byte is refused with 400 before anything is opened.

const MDV_PER_DIR_KEYS = ['DISABLE_UPLOAD', 'DISABLE_CLIPBOARD', 'DISABLE_SAVE_CLIPBOARD_TO_FILE'];

const MDV_INSTALL_KEYS = ['BROWSE_DIR', 'API_KEY', 'ALLOW_UPDATE', 'ALLOW_RESTORE', 'ALLOW_CREATE_INDEX_PHP_LINK'];

const MDV_FLAG_DEFAULTS = [
    'DISABLE_UPLOAD'                 => true,
    'DISABLE_CLIPBOARD'              => false,
    'DISABLE_SAVE_CLIPBOARD_TO_FILE' => true,
];

/** Longest accepted "dir" value, in bytes - mirrors the viewer's limit. */
const MDV_DIR_MAX_LENGTH = 255;

/** Deepest accepted "dir" value - mirrors the viewer's scan depth. */
const MDV_DIR_MAX_DEPTH = 3;

/** True when $path is $root itself or lives below it (both realpath'd). */
function insideRoot(string $path, string $root): bool
{
    if ($path === $root) {
        return true;
    }
    return str_starts_with($path, rtrim($root, '/\\') . DIRECTORY_SEPARATOR);
}

/**
 * Split a client-supplied relative directory into path segments (v3.12.0).
 * Returns [] for the browse root itself, null when the value must be refused.
 * Same defences as md.php validateRequestedFile(): no absolute path, no
 * traversal, no null byte or control character, a length and depth limit and a
 * strict whitelist.
 *
 * @return array<int, string>|null
 */
function dirSegments(string $dir): ?array
{
    $dir = trim($dir);
    if ($dir === '') {
        return [];
    }
    if (strlen($dir) > MDV_DIR_MAX_LENGTH || str_contains($dir, "\0")) {
        return null;
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $dir) === 1) {
        return null;
    }
    $decoded = urldecode($dir);
    if (preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1) {
        return null;
    }
    if (str_starts_with($decoded, '/') || str_starts_with($decoded, '\\')
        || str_starts_with($decoded, '//') || preg_match('/^[A-Za-z]:/', $decoded) === 1) {
        return null;
    }
    $normalized = str_replace(['\\', '//'], '/', $decoded);
    if (preg_match('#(?:^|/)\.\.(?:/|$)|\.\.$#', $normalized) === 1) {
        return null;
    }
    if (substr_count($normalized, '/') > MDV_DIR_MAX_DEPTH) {
        return null;
    }
    $normalized = trim($normalized, '/');
    if ($normalized === '' || $normalized === '.') {
        return [];
    }
    if (preg_match('#^[A-Za-z0-9._\-/]+$#', $normalized) !== 1) {
        return null;
    }
    $segments = [];
    foreach (explode('/', $normalized) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        $segments[] = $segment;
    }
    return $segments;
}

/**
 * Read one directory's .md.ini and return the per-directory keys it sets.
 * Never throws: a missing, unreadable or broken file yields an empty array.
 * Installation-level keys found here are ignored and reported.
 *
 * @return array<string, bool>
 */
function dirIniValues(string $dir): array
{
    $path = rtrim($dir, '/\\') . '/.md.ini';
    if (!is_file($path)) {
        return [];
    }
    if (!is_readable($path)) {
        error_log('[MD.Viewer updater] .md.ini in ' . $dir . ' is not readable; ignored.');
        return [];
    }
    $raw = @parse_ini_file($path, false, INI_SCANNER_TYPED);
    if (!is_array($raw)) {
        error_log('[MD.Viewer updater] .md.ini in ' . $dir . ' could not be parsed; ignored.');
        return [];
    }
    foreach (MDV_INSTALL_KEYS as $key) {
        if (array_key_exists($key, $raw)) {
            error_log('[MD.Viewer updater] .md.ini key ' . $key . ' in ' . $dir
                . ' ignored: installation-level setting.');
        }
    }

    $applied = [];
    foreach (MDV_PER_DIR_KEYS as $key) {
        if (!array_key_exists($key, $raw)) {
            continue;
        }
        $value = $raw[$key];
        if (is_bool($value)) {
            $applied[$key] = $value;
            continue;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            error_log('[MD.Viewer updater] .md.ini key ' . $key . ' in ' . $dir . ' ignored: invalid value.');
            continue;
        }
        $text = trim((string) $value);
        if (strlen($text) >= 2) {
            $first = $text[0];
            $last  = $text[strlen($text) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $text = trim(substr($text, 1, -1));
            }
        }
        if ($text === '' || strlen($text) > MDV_DIR_MAX_LENGTH
            || str_contains($text, "\0") || str_contains($text, "\n") || str_contains($text, "\r")) {
            error_log('[MD.Viewer updater] .md.ini key ' . $key . ' in ' . $dir . ' ignored: invalid value.');
            continue;
        }
        $applied[$key] = (bool) $text;
    }

    return $applied;
}

/**
 * Effective DISABLE_* flags for a directory inside the browse root (v3.12.0).
 *
 * The base .md.ini is read first, then every .md.ini found from the browse root
 * down to the target directory; each one shadows the previous. The directory
 * that holds the base file is skipped, because its file is already applied. A
 * directory whose realpath() escapes realpath($root) is skipped with a note,
 * and its file is never read.
 *
 * @param array<string, mixed> $baseIni  Base .md.ini values.
 * @param string               $root     Browse root directory.
 * @param array<int, string>   $segments Validated segments below the root.
 * @return array<string, bool>
 */
function effectiveFlags(array $baseIni, string $root, array $segments): array
{
    $flags = [];
    foreach (MDV_FLAG_DEFAULTS as $key => $default) {
        $flags[$key] = (bool) ($baseIni[$key] ?? $default);
    }

    $realRoot = realpath($root);
    if ($realRoot === false) {
        return $flags;
    }

    $realBaseDir = realpath(docRoot());
    $chain       = [$realRoot];

    // Collect the chain: the browse root itself, then every existing directory
    // below it down to the requested one. The root's own file is part of the
    // chain - it governs every directory that does not override it.
    $current = rtrim($realRoot, '/\\');

    foreach ($segments as $segment) {
        $current = $current . DIRECTORY_SEPARATOR . $segment;
        if (!is_dir($current)) {
            break;
        }
        $real = realpath($current);
        if ($real === false) {
            break;
        }
        if (!insideRoot($real, $realRoot)) {
            error_log('[MD.Viewer updater] .md.ini in ' . $real . ' ignored: outside the browse root.');
            break;
        }
        $chain[] = $real;
    }

    // Apply them in order, the deepest last, so it wins. The directory that
    // holds the base file is skipped - its file is already applied.
    foreach ($chain as $real) {
        if ($realBaseDir !== false && $real === $realBaseDir) {
            continue;
        }
        $flags = array_merge($flags, dirIniValues($real));
    }

    return $flags;
}

/**
 * Browse root used for uploads and clipboard saves (v3.12.0).
 *
 * A BROWSE_DIR that resolves to a writable directory wins. Without the key the
 * directory of md.php is the root - the directory the viewer browses in that
 * configuration. A BROWSE_DIR that is set but unusable is a configuration error
 * and answered with 500, exactly as destinationDir() already did.
 *
 * @param array<string, mixed> $ini Base .md.ini values.
 * @return array{0: string, 1: string} Absolute root directory and its label.
 */
function writeRoot(array $ini): array
{
    if (browseDirValue($ini) === '') {
        return [dirname(localPath('md.php')), ''];
    }
    $browse = resolveBrowseDir($ini);
    if ($browse === null) {
        jsonError(500, 'BROWSE_DIR in .md.ini does not point to a writable directory. Fix the value or its permissions.');
    }
    return [$browse[0], $browse[1]];
}

/**
 * The optional "dir" parameter of upload_md / save_clipboard (v3.12.0).
 *
 * Returns null when the parameter was not sent - the caller then keeps the
 * classic behaviour. A parameter that is not a usable relative directory below
 * the browse root is refused with 400 before anything is read or written.
 *
 * @return array<int, string>|null Validated segments; null = parameter absent.
 */
function requestDirSegments(): ?array
{
    static $checked  = false;
    static $segments = null;

    if (!$checked) {
        $checked = true;
        $value   = $_REQUEST['dir'] ?? null;
        if ($value !== null) {
            if (!is_string($value)) {
                jsonError(400, 'Invalid dir parameter.');
            }
            $segments = dirSegments($value);
            if ($segments === null) {
                jsonError(400, 'Invalid dir parameter.');
            }
        }
    }

    return $segments;
}

/**
 * Absolute directory a "dir" request writes into, created when missing and
 * checked for writability like the classic destinations (v3.12.0).
 *
 * Never leaves the browse root: the segments were validated against traversal
 * and the root is the resolved BROWSE_DIR or the md.php directory - the same
 * root the viewer browses.
 *
 * @param array<string, mixed> $ini      Base .md.ini values.
 * @param array<int, string>   $segments Validated segments below the root.
 * @return array{0: string, 1: string}   Absolute directory and client label.
 */
function dirInRoot(array $ini, array $segments): array
{
    [$root, $label] = writeRoot($ini);
    $suffix         = $segments === [] ? '' : implode('/', $segments) . '/';
    $dir            = rtrim($root, '/\\') . ($segments === [] ? '' : '/' . implode('/', $segments));

    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        jsonError(500, 'Could not create ' . $label . $suffix . ' directory. Check permissions.');
    }
    if (!is_writable($dir)) {
        jsonError(500, 'Directory ' . $label . $suffix . ' is not writable. Check permissions.');
    }

    // A symlink inside the root must not redirect the write outside it.
    $realDir  = realpath($dir);
    $realRoot = realpath($root);
    if ($realDir === false || $realRoot === false || !insideRoot($realDir, $realRoot)) {
        jsonError(400, 'Invalid dir parameter.');
    }

    return [$dir, $label . $suffix];
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
    // Convert path to flat filename: "js/md/md.js" → "js_md_md.js.json"
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

// ── Install manifest, conflict detection, write guard ─────────────────────────

/**
 * Fresh machine-API credential: "mdv_" + 48 hex characters (192 bits).
 *
 * The "mdv_" prefix keeps the value a string for INI_SCANNER_TYPED — a bare hex
 * value made only of digits would otherwise be parsed as an integer.
 */
function generateApiKey(): string
{
    return 'mdv_' . bin2hex(random_bytes(24));
}

/** Requested API key: ?api_key=, POST api_key, or the X-API-Key request header. */
function apiKeyFromRequest(): string
{
    foreach ([$_GET['api_key'] ?? null, $_POST['api_key'] ?? null, $_SERVER['HTTP_X_API_KEY'] ?? null] as $candidate) {
        if (is_string($candidate) && $candidate !== '') return $candidate;
    }
    return '';
}

/**
 * True when the request belongs to the JSON machine API: format=json was asked
 * for, or a key was sent. Legacy actions are unaffected by this test.
 */
function jsonApiRequested(): bool
{
    if (strtolower(trim((string)($_GET['format'] ?? ''))) === 'json') return true;
    return apiKeyFromRequest() !== '';
}

/**
 * GET/POST parameter for the machine API. Cookies and the environment are never
 * consulted, so a stray cookie cannot flip dry_run or force.
 */
function apiParam(string $name): mixed
{
    if (array_key_exists($name, $_POST)) return $_POST[$name];
    if (array_key_exists($name, $_GET)) return $_GET[$name];
    return null;
}

/** Truthy parser for 1/true/yes/on (query and POST values). */
function isTruthy(mixed $value): bool
{
    if (is_bool($value)) return $value;
    if (is_int($value)) return $value !== 0;
    if (!is_string($value)) return false;
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

/** Viewer version reported by the API (falls back to updater.php's own version). */
function mdViewerVersion(): string
{
    $version = localVersion('md.php');
    return $version !== '' ? $version : localVersion('updater.php');
}

/** Files the installer would write, relative to the target directory. */
function installManifest(): array
{
    return TRACKED_FILES;
}

/**
 * Ownership test for a file that already exists in the target directory.
 *
 * Every MD.Viewer file carries a " * Version: X.Y.Z" marker in its first 1000
 * bytes — the same marker localVersion() reads — so the version extractor doubles
 * as the ownership check. Anything else is a foreign file: the write paths refuse
 * to replace it until force is requested.
 */
function isManagedFile(string $absPath): bool
{
    if (!is_file($absPath)) return false;
    $fh = @fopen($absPath, 'rb');
    if ($fh === false) return false;
    $head = (string) @fread($fh, 1000);
    @fclose($fh);
    return extractVersion($head) !== '';
}

/**
 * Pre-write guard shared by every write path (install, action=apply, ?update=true).
 * Never deletes or moves anything; it only refuses a replacement.
 *
 * @param string $absPath Absolute path of the destination file.
 * @return string|null Reason why the write must be refused, or null when allowed.
 */
function writeBlockedReason(string $absPath, bool $force): ?string
{
    if ($force || !file_exists($absPath)) return null;
    $name = basename($absPath);
    if (!is_file($absPath)) {
        return 'Target path ' . $name . ' is a directory — skipped';
    }
    if (isManagedFile($absPath)) return null;
    return 'Existing ' . $name . ' is not an MD.Viewer file — skipped (force to overwrite)';
}

/**
 * Index every file below the target directory by lower-case basename.
 *
 * Used to spot "same file name, different place" clashes before writing.
 * Dot-directories (.git, .state...), the updater's runtime directories and
 * symlinked entries are skipped: they cannot be a meaningful clash.
 *
 * @return array<string, list<string>> basename (lower-case) => relative paths
 */
function indexTargetFileNames(): array
{
    $root  = docRoot();
    $index = [];
    if (!is_dir($root)) return $index;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
            ),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        $seen = 0;
        foreach ($iterator as $entry) {
            /** @var SplFileInfo $entry */
            if (!$entry->isFile() || $entry->isLink()) continue;
            if (++$seen > CONFLICT_SCAN_MAX_FILES) break;   // DoS guard, same idea as md.php

            // Never count hidden files or files inside dot-directories
            $subPathname = $iterator->getSubPathname();
            if (preg_match('#(^|[\\\\/])\.#', $subPathname)) continue;

            // Skip the updater's own runtime directories (md.backup, ...)
            $parts = explode('/', str_replace('\\', '/', $subPathname));
            if (array_intersect(array_slice($parts, 0, -1), CONFLICT_SCAN_SKIP_DIRS) !== []) continue;

            $index[strtolower($entry->getFilename())][] = str_replace('\\', '/', $subPathname);
        }
    } catch (UnexpectedValueException) {
        // Unreadable subtree — index whatever could be read.
    }

    return $index;
}

/**
 * Compare the install manifest with what already exists in the target directory.
 *
 * Read-only by design: it backs both the dry-run report (action=conflicts) and the
 * decisions taken by action=install. Nothing is ever written here.
 *
 * @return list<array{path: string, status: string, existing_path: string,
 *                    size_bytes: int, managed: bool}>
 *   status: ok                  target path is free and the basename is unique
 *           exists              the exact relative path exists and would be replaced
 *           same_name_elsewhere target path is free, but the same file name exists
 *                               somewhere else in the target directory
 *   existing_path  relative path of the file that was found ('' when status=ok)
 *   size_bytes     size of that file in bytes (0 when status=ok)
 *   managed        true when the existing file looks like an MD.Viewer file
 */
function detectInstallConflicts(): array
{
    $root   = docRoot();
    $index  = indexTargetFileNames();
    $report = [];

    foreach (installManifest() as $file) {
        $abs      = localPath($file);
        $status   = 'ok';
        $existing = '';
        $size     = 0;
        $managed  = false;

        if (file_exists($abs)) {
            $status   = 'exists';
            $existing = $file;
            $managed  = isManagedFile($abs);
            $size     = is_file($abs) ? (int) (@filesize($abs) ?: 0) : 0;
        } else {
            $clashes = $index[strtolower(basename($file))] ?? [];
            if ($clashes !== []) {
                $status   = 'same_name_elsewhere';
                $existing = $clashes[0];
                $size     = (int) (@filesize($root . '/' . $clashes[0]) ?: 0);
            }
        }

        $report[] = [
            'path'          => $file,
            'status'        => $status,
            'existing_path' => $existing,
            'size_bytes'    => $size,
            'managed'       => $managed,
        ];
    }

    return $report;
}

/** Per-status counters for a conflict report. */
function conflictCounts(array $report): array
{
    $counts = ['total' => count($report), 'ok' => 0, 'exists' => 0, 'same_name_elsewhere' => 0];
    foreach ($report as $entry) {
        $status = (string)($entry['status'] ?? 'ok');
        $counts[$status] = ($counts[$status] ?? 0) + 1;
    }
    return $counts;
}

/** One-line conflict summary, e.g. "11 entries: 7 ok, 1 exists, 3 same_name_elsewhere". */
function conflictSummary(array $counts): string
{
    return sprintf(
        '%d entries: %d ok, %d exists, %d same_name_elsewhere',
        $counts['total'] ?? 0,
        $counts['ok'] ?? 0,
        $counts['exists'] ?? 0,
        $counts['same_name_elsewhere'] ?? 0
    );
}

/**
 * Reporting counterpart of writeBlockedReason(): what install() will do with one
 * manifest entry. Foreign files are never replaced silently — they are skipped
 * until force is requested.
 *
 * @param array $entry Conflict report entry from detectInstallConflicts().
 * @return array{write: bool, decision: string, reason: string}
 */
function installDecision(array $entry, bool $force): array
{
    $status = (string)($entry['status'] ?? 'ok');

    if ($status === 'ok') {
        return ['write' => true, 'decision' => 'create', 'reason' => 'Target path is free'];
    }

    if ($status === 'same_name_elsewhere') {
        return [
            'write'    => true,
            'decision' => 'create_name_clash',
            'reason'   => 'Same file name already exists at ' . (string)($entry['existing_path'] ?? ''),
        ];
    }

    // status === 'exists' — the exact path is occupied.
    if (!empty($entry['managed'])) {
        return ['write' => true, 'decision' => 'update', 'reason' => 'Existing MD.Viewer file at the same path'];
    }
    if ($force) {
        return ['write' => true, 'decision' => 'overwrite_forced', 'reason' => 'Foreign file replaced because force=1'];
    }

    return [
        'write'    => false,
        'decision' => 'skipped_conflict',
        'reason'   => 'Existing file is not an MD.Viewer file — skipped (force=1 to overwrite)',
    ];
}

/** One machine-API step record: {step, status, ms, detail}. */
function apiStep(string $step, string $status, string $detail, int $ms = 0): array
{
    return ['step' => $step, 'status' => $status, 'ms' => $ms, 'detail' => $detail];
}

/** Milliseconds elapsed since $startedAt (a microtime(true) value). */
function elapsedMs(float $startedAt): int
{
    return (int) round((microtime(true) - $startedAt) * 1000);
}

// ── Machine API (JSON only, API-key protected) ────────────────────────────────

// Contract (stable, client-facing):
//   GET  updater.php?api_key=K&action=status&format=json
//   GET  updater.php?api_key=K&action=conflicts&format=json
//   POST updater.php?api_key=K&action=install&format=json[&dry_run=1][&force=1]
//
//   {"ok":bool,"action":"...","version":"...",
//    "steps":[{"step","status","ms","detail"}],
//    "files":[{"path","status","version"}],
//    "conflicts":[{"path","status","existing_path","size_bytes"}],
//    "errors":[{"path","error"}]}
//
//   Envelope extras: dry_run, force, counts, installed, updater_version, ini,
//                    allowed, error, detail, from_version, conflict_status,
//                    decision, managed.
//   step status: ok | dry-run | skip | warn | error  (+ file outcome names)
//   file status: present | missing | updated | created | force-updated | current |
//                skipped | would_write | error
//   HTTP codes:  200 completed (per-file failures keep "ok": false in the body),
//                400 unknown action, 403 unauthorized / api_key_unavailable,
//                405 install without POST, 500 internal error.

/** Emit a machine-API response. Stray output (notices, warnings) is discarded. */
function jsonApiSend(array $payload, int $code = 200): never
{
    while (ob_get_level() > 0) { @ob_end_clean(); }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        http_response_code($code);
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Machine-API entry point. Always answers JSON: HTML, notices and warnings can
 * never reach the body. Never returns.
 */
function handleJsonApi(): never
{
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
    @ob_start();

    try {
        [$payload, $code] = jsonApiDispatch();
    } catch (Throwable $e) {
        @error_log('MD.Viewer machine API error: ' . $e->getMessage());
        [$payload, $code] = [[
            'ok'        => false,
            'error'     => 'internal_error',
            'action'    => 'unknown',
            'version'   => '',
            'steps'     => [],
            'files'     => [],
            'conflicts' => [],
            'errors'    => [['path' => '', 'error' => 'internal_error']],
        ], 500];
    }

    jsonApiSend($payload, $code);
}

/**
 * Authenticate and route a machine-API request.
 *
 * @return array{0: array<string, mixed>, 1: int} JSON payload and HTTP status.
 */
function jsonApiDispatch(): array
{
    $configured = readIni()['API_KEY'] ?? '';
    $configured = is_string($configured) ? $configured : '';
    $provided   = apiKeyFromRequest();

    // .md.ini is missing, unreadable, or its API_KEY line is broken. Say so
    // explicitly instead of failing silently; no paths or versions are disclosed.
    if ($configured === '') {
        return [['ok' => false, 'error' => 'api_key_unavailable'], 403];
    }

    // Wrong or absent key: identical answer either way, nothing else is revealed.
    if ($provided === '' || !hash_equals($configured, $provided)) {
        return [['ok' => false, 'error' => 'unauthorized'], 403];
    }

    $action = strtolower(trim((string)(apiParam('action') ?? 'status')));

    if (!in_array($action, ['status', 'conflicts', 'install'], true)) {
        return [[
            'ok'        => false,
            'error'     => 'unknown_action',
            'action'    => $action,
            'allowed'   => ['status', 'conflicts', 'install'],
            'version'   => mdViewerVersion(),
            'steps'     => [],
            'files'     => [],
            'conflicts' => [],
            'errors'    => [],
        ], 400];
    }

    // install is the only mutating action, so it always requires POST.
    if ($action === 'install' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return [[
            'ok'        => false,
            'error'     => 'method_not_allowed',
            'action'    => $action,
            'detail'    => 'install requires POST (dry_run included)',
            'version'   => mdViewerVersion(),
            'steps'     => [],
            'files'     => [],
            'conflicts' => [],
            'errors'    => [],
        ], 405];
    }

    $envelope = [
        'ok'        => true,
        'action'    => $action,
        'version'   => mdViewerVersion(),
        'steps'     => [],
        'files'     => [],
        'conflicts' => [],
        'errors'    => [],
    ];

    return match ($action) {
        'status'    => jsonApiStatus($envelope),
        'conflicts' => jsonApiConflicts($envelope),
        default     => jsonApiInstall($envelope),
    };
}

/** Local install state. No network I/O, so it answers instantly and offline. */
function jsonApiStatus(array $envelope): array
{
    $started = microtime(true);
    $ini     = readIni();
    $files   = [];
    $missing = 0;

    foreach (installManifest() as $file) {
        $exists = is_file(localPath($file));
        if (!$exists) $missing++;
        $files[] = [
            'path'    => $file,
            'status'  => $exists ? 'present' : 'missing',
            'version' => $exists ? localVersion($file) : '',
        ];
    }

    $envelope['files']           = $files;
    $envelope['updater_version'] = localVersion('updater.php');
    $envelope['installed']       = $missing === 0;
    $envelope['counts']          = [
        'tracked' => count($files),
        'present' => count($files) - $missing,
        'missing' => $missing,
    ];
    $envelope['ini'] = [
        'file'          => '.md.ini',
        'api_key'       => 'configured',
        'allow_update'  => (bool)($ini['ALLOW_UPDATE'] ?? false),
        'allow_restore' => (bool)($ini['ALLOW_RESTORE'] ?? false),
        // A valid API_KEY authorises action=install even while ALLOW_UPDATE stays
        // false (that flag keeps governing the browser modes ?update=/?restore=).
        'api_install'   => true,
    ];
    $envelope['steps'][] = apiStep(
        'status',
        'ok',
        $missing === 0 ? 'All tracked files present' : $missing . ' tracked file(s) missing',
        elapsedMs($started)
    );

    return [$envelope, 200];
}

/** Pre-install conflict report. Always a dry run — nothing is ever written. */
function jsonApiConflicts(array $envelope): array
{
    $started = microtime(true);
    $report  = detectInstallConflicts();
    $counts  = conflictCounts($report);
    $clashes = $counts['exists'] + $counts['same_name_elsewhere'];

    $envelope['conflicts'] = $report;
    $envelope['counts']    = $counts;
    $envelope['dry_run']   = true;
    $envelope['force']     = false;
    $envelope['steps'][]   = apiStep(
        'conflicts',
        $clashes > 0 ? 'warn' : 'ok',
        conflictSummary($counts),
        elapsedMs($started)
    );

    return [$envelope, 200];
}

/**
 * Install or update every tracked file with a pre-write conflict guard.
 *
 * dry_run=1 stops after the conflict report: no file is fetched and nothing is
 * written. force=1 replaces files that do not look like MD.Viewer files and
 * bypasses the ETag/SHA-256 cache for this run.
 */
function jsonApiInstall(array $envelope): array
{
    $started = microtime(true);
    $dryRun  = isTruthy(apiParam('dry_run'));
    $force   = isTruthy(apiParam('force'));

    $report = detectInstallConflicts();
    $counts = conflictCounts($report);

    $envelope['dry_run']   = $dryRun;
    $envelope['force']     = $force;
    $envelope['conflicts'] = array_values(array_filter(
        $report,
        static fn(array $entry): bool => ($entry['status'] ?? 'ok') !== 'ok'
    ));
    $envelope['steps'][] = apiStep(
        'scan',
        ($counts['exists'] + $counts['same_name_elsewhere']) > 0 ? 'warn' : 'ok',
        conflictSummary($counts),
        elapsedMs($started)
    );

    $backupVer = localVersion('md.php');
    $docsFiles = ['README.md', 'LICENSE'];
    $written   = 0;
    $skipped   = 0;

    foreach ($report as $entry) {
        $file      = (string)$entry['path'];
        $decision  = installDecision($entry, $force);
        $isDoc     = in_array($file, $docsFiles, true);
        $before    = localVersion($file);
        $stepStart = microtime(true);

        if (!$decision['write']) {
            $skipped++;
            $envelope['files'][] = [
                'path'            => $file,
                'status'          => 'skipped',
                'version'         => $before,
                'conflict_status' => $entry['status'],
                'decision'        => $decision['decision'],
                'existing_path'   => $entry['existing_path'],
            ];
            $envelope['steps'][] = apiStep(
                'install:' . $file,
                'skip',
                $decision['reason'],
                elapsedMs($stepStart)
            );
            continue;
        }

        if ($dryRun) {
            // Report only — the file is neither fetched nor written.
            $envelope['files'][] = [
                'path'            => $file,
                'status'          => 'would_write',
                'version'         => $before,
                'conflict_status' => $entry['status'],
                'decision'        => $decision['decision'],
                'existing_path'   => $entry['existing_path'],
            ];
            $envelope['steps'][] = apiStep(
                'install:' . $file,
                'dry-run',
                $decision['reason'],
                elapsedMs($stepStart)
            );
            continue;
        }

        try {
            $updater = makeUpdater($file);
            $res     = $updater->apply(backupVersion: $isDoc ? '' : $backupVer, force: $force);
            $status  = (string)($res['status'] ?? 'error');
            $after   = localVersion($file);
            $error   = $res['error'] ?? null;

            if ($status === 'error' || $status === 'blocked') {
                $envelope['errors'][] = ['path' => $file, 'error' => (string)($error ?? 'unknown error')];
                $envelope['steps'][]  = apiStep(
                    'install:' . $file,
                    'error',
                    (string)($error ?? 'unknown error'),
                    elapsedMs($stepStart)
                );
            } else {
                if (in_array($status, ['updated', 'created', 'force-updated'], true)) $written++;
                if ($status === 'current') $skipped++;

                $detail = ($before !== '' && $after !== '' && $before !== $after)
                    ? $before . ' → ' . $after
                    : $decision['reason'] . ' — ' . $status;

                $envelope['steps'][] = apiStep(
                    'install:' . $file,
                    $status === 'current' ? 'skip' : 'ok',
                    $detail,
                    elapsedMs($stepStart)
                );
            }

            $envelope['files'][] = [
                'path'            => $file,
                'status'          => $status,
                'version'         => $after,
                'from_version'    => $before,
                'conflict_status' => $entry['status'],
                'decision'        => $decision['decision'],
                'error'           => $error,
            ];
        } catch (Throwable $e) {
            $envelope['errors'][] = ['path' => $file, 'error' => $e->getMessage()];
            $envelope['steps'][]  = apiStep('install:' . $file, 'error', $e->getMessage(), elapsedMs($stepStart));
            $envelope['files'][]  = [
                'path'            => $file,
                'status'          => 'error',
                'version'         => $before,
                'from_version'    => $before,
                'conflict_status' => $entry['status'],
                'decision'        => $decision['decision'],
                'error'           => $e->getMessage(),
            ];
        }
    }

    $envelope['ok']      = $envelope['errors'] === [];
    $envelope['version'] = mdViewerVersion();
    $envelope['counts']  = $counts + [
        'written' => $written,
        'skipped' => $skipped,
        'errors'  => count($envelope['errors']),
    ];
    $envelope['steps'][] = apiStep(
        $dryRun ? 'dry-run' : 'install',
        $envelope['ok'] ? 'ok' : 'error',
        sprintf(
            '%s: %d written, %d skipped, %d error(s)',
            $dryRun ? 'Dry run' : 'Install',
            $written,
            $skipped,
            count($envelope['errors'])
        ),
        elapsedMs($started)
    );

    return [$envelope, 200];
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
    requireSameOrigin(false, true);

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
        $force       = isset($_GET['force']) && $_GET['force'] === 'true';
        $result      = $selfUpdater->apply(backupVersion: localVersion('updater.php'), force: $force);

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

        // updater.php was already current — or the existing file is not an
        // MD.Viewer file and was therefore skipped — fall through to phase 2,
        // which reports it as 'skipped (conflict)'.
        $phase = 2;
    }

    if ($phase === 2) {
        // ── Phase 2: update all tracked files ─────────────────────────────────
        $backupVer = localVersion('md.php');
        $rows      = [];

        $docsFiles = ['README.md', 'LICENSE']; // no backup needed, but still version-tracked
        $force     = isset($_GET['force']) && $_GET['force'] === 'true';
        foreach (TRACKED_FILES as $file) {
            if ($file === 'updater.php') {
                // Already handled in phase 1 — show its current local version,
                // or the reason phase 1 refused to replace it.
                $selfNote = writeBlockedReason(localPath($file), $force);
                $rows[]   = [
                    'file'   => $file,
                    'status' => $selfNote === null ? 'current (updated in phase 1)' : 'skipped (conflict)',
                    'to'     => $selfNote === null ? localVersion($file) : null,
                    'error'  => $selfNote,
                ];
                continue;
            }
            $isDoc     = in_array($file, $docsFiles, true);
            $verBefore = localVersion($file); // always read — works for README/LICENSE too
            $updater   = makeUpdater($file);
            // Docs: no backup; code files: backup to versioned dir
            $res       = $updater->apply(backupVersion: $isDoc ? '' : $backupVer, force: $force);
            $rows[]    = [
                'file'   => $file,
                'status' => $res['status'],
                'from'   => $verBefore,
                'to'     => in_array($res['status'], ['updated', 'created', 'force-updated'], true)
                             ? localVersion($file) : null,
                'error'  => $res['error'] ?? null,
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
// ── Direct-access restore mode ────────────────────────────────────────────────
// Triggered by opening updater.php?restore=latest OR ?restore=[version] in a browser.
// Requires ALLOW_RESTORE = true in .md.ini.
//
// ?restore=latest  → finds the most recent backup version and restores it.
// ?restore=X.Y.Z   → restores the specified backup version.
//
// Outputs a standalone HTML result page.

if (isset($_GET['restore'])) {
    requireSameOrigin(false, true);

    $ini = readIni();
    if (!(bool)($ini['ALLOW_RESTORE'] ?? false)) {
        http_response_code(403);
        outputUpdatePage('Access denied', [[
            'type'    => 'error',
            'message' => 'Restore is disabled. Set <code>ALLOW_RESTORE = true</code> in <code>.md.ini</code> to enable.',
        ]]);
        exit;
    }

    // ── Resolve version ───────────────────────────────────────────────────────
    $reqRestore = trim((string)$_GET['restore']);
    $backupRoot = backupRoot();

    if ($reqRestore === 'latest') {
        // Find the highest versioned backup directory that contains at least one tracked file
        $available = [];
        if (is_dir($backupRoot)) {
            foreach (scandir($backupRoot) as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === '.state') continue;
                $dir = $backupRoot . '/' . $entry;
                if (!is_dir($dir)) continue;
                foreach (TRACKED_FILES as $f) {
                    if (is_file($dir . '/' . $f)) { $available[] = $entry; break; }
                }
            }
        }
        if (empty($available)) {
            outputUpdatePage('No backups found', [[
                'type'    => 'error',
                'message' => 'No backup versions found in <code>md.backup/</code>. Run an update first to create a backup.',
            ]]);
            exit;
        }
        usort($available, fn($a, $b) => version_compare($b, $a));
        $resolvedVersion = $available[0];
    } else {
        // Validate the version string (only alphanumeric, dots, dashes)
        if (!preg_match('/^[a-zA-Z0-9.\-]+$/', $reqRestore)) {
            http_response_code(400);
            outputUpdatePage('Invalid version', [[
                'type'    => 'error',
                'message' => 'Version string contains invalid characters: ' . htmlspecialchars($reqRestore),
            ]]);
            exit;
        }
        $resolvedVersion = $reqRestore;
    }

    // ── Check backup dir exists ───────────────────────────────────────────────
    $restoreDir = backupDir($resolvedVersion);
    if (!is_dir($restoreDir)) {
        http_response_code(404);
        outputUpdatePage('Backup not found', [[
            'type'    => 'error',
            'message' => 'No backup directory found for version <strong>' . htmlspecialchars($resolvedVersion) . '</strong>. '
                        . 'Available backups are in <code>md.backup/</code>.',
        ]]);
        exit;
    }

    // ── Perform restore ───────────────────────────────────────────────────────
    $currentVer = localVersion('md.php');
    $rows       = [];

    foreach (TRACKED_FILES as $file) {
        $backupSrc = $restoreDir . '/' . $file;

        if (!is_file($backupSrc)) {
            $rows[] = ['file' => $file, 'status' => 'skipped (not in backup)'];
            continue;
        }

        $locPath = localPath($file);
        $verBefore = localVersion($file);

        // Backup current file before overwriting
        if (is_file($locPath) && $currentVer !== '') {
            backupFile($file, $currentVer . '-pre-restore');
        }

        $content = file_get_contents($backupSrc);
        if ($content === false) {
            $rows[] = ['file' => $file, 'status' => 'error', 'error' => 'Cannot read backup file'];
            continue;
        }

        if (!atomicWrite($locPath, $content)) {
            $rows[] = ['file' => $file, 'status' => 'error', 'error' => 'Write failed — check permissions'];
            continue;
        }

        // Invalidate ETag state so next update check does a full fetch
        $sf = stateFile($file);
        if (is_file($sf)) @unlink($sf);

        $rows[] = [
            'file'   => $file,
            'status' => 'restored',
            'from'   => $verBefore,
            'to'     => extractVersion(substr($content, 0, 1000)),
        ];
    }

    $title = 'Restored from v' . htmlspecialchars($resolvedVersion);
    outputUpdatePage($title, $rows);
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
        str_starts_with($s, 'updated'), str_starts_with($s, 'created'),
        str_starts_with($s, 'force')   => 'ok',
        str_starts_with($s, 'current') => 'skip',
        str_starts_with($s, 'error')   => 'err',
        default                         => 'info',
    };
    $rawBase = RAW_BASE;

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
        .file{font-family:monospace;font-size:.85rem;flex:1;word-break:break-all;color:inherit;text-decoration:none}
        .file:hover{text-decoration:underline}
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
        $status  = $row['status'] ?? 'info';
        $cls     = $cssClass($status);
        $file    = $row['file'] ?? '';
        $fileUrl = $rawBase . '/' . ltrim($file, '/');
        echo '<div class="row ' . $cls . '">';
        echo '<span class="badge">' . htmlspecialchars($status) . '</span>';
        if ($file !== '') {
            echo '<a class="file" href="' . htmlspecialchars($fileUrl) . '" target="_blank" rel="noopener">'
               . htmlspecialchars($file) . '</a>';
        }
        if (!empty($row['from']) || !empty($row['to'])) {
            echo '<span class="ver">';
            if (!empty($row['from'])) echo htmlspecialchars($row['from']);
            if (!empty($row['from']) && !empty($row['to'])) echo ' → ';
            if (!empty($row['to']))   echo htmlspecialchars($row['to']);
            echo '</span>';
        }
        if (!empty($row['error'])) {
            echo '<span class="ver" style="color:#dc2626">' . htmlspecialchars($row['error']) . '</span>';
        }
        echo '</div>';
    }

    echo '</div>';
    $isForcePage = isset($_GET['force']) && $_GET['force'] === 'true';
    $forceUrl    = strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query(
        array_merge(
            array_filter(['update' => $_GET['update'] ?? null, 'restore' => $_GET['restore'] ?? null, '_phase' => $_GET['_phase'] ?? null]),
            ['force' => 'true']
        )
    );
    echo '<div class="footer" style="display:flex;gap:.5rem;justify-content:flex-end;align-items:center">';
    if (!$isForcePage && (isset($_GET['update']))) {
        echo '<a class="btn" style="background:#b45309" href="' . htmlspecialchars($forceUrl) . '">↺ Force reinstall all</a>';
    }
    echo '<a class="btn" href="/">← Back</a>';
    echo '</div>';
    echo '</div></body></html>';
}

// ── JSON API ──────────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/**
 * Cross-site guard shared by every state-changing entry point (v3.11.0).
 *
 * A browser request carries Origin (fetch/XHR, form POST) and usually Referer.
 * When that host differs from the host this script was reached on, the request
 * comes from another site and is refused before anything is read or written.
 * Clients that send neither header - CLI tools, server-side integrations, plain
 * links opened from the panel - are not cross-site browser requests and stay
 * allowed.
 */
function hostFromUrl(string $url): string
{
    if ($url === '') {
        return '';
    }
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) ? strtolower($host) : '';
}

/** Request host without a port; an IPv6 literal loses its brackets. */
function requestHost(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        $host = (string) ($_SERVER['SERVER_NAME'] ?? '');
    }
    if (strncmp($host, '[', 1) === 0) {
        $end = strpos($host, ']');
        if ($end !== false) {
            $host = substr($host, 1, $end - 1);
        }
    } elseif (($colon = strpos($host, ':')) !== false) {
        $host = substr($host, 0, $colon);
    }
    return strtolower($host);
}

function sameOriginViolation(bool $strict): bool
{
    // A browser sends Origin on cross-site requests; Referer catches the same
    // attempt from older clients. The Referer check is only safe for
    // state-changing requests: a plain page view may legitimately arrive with a
    // foreign Referer, for example from a link on another site.
    $headers = $strict ? ['HTTP_ORIGIN', 'HTTP_REFERER'] : ['HTTP_ORIGIN'];
    foreach ($headers as $header) {
        $value = (string) ($_SERVER[$header] ?? '');
        if ($value === '') {
            continue;
        }
        $host = hostFromUrl($value);
        if ($host === '') {
            continue; // opaque origin ("null") - nothing to compare with
        }
        return $host !== requestHost();
    }
    return false;
}

/**
 * Refuse a cross-site request. $json picks the error representation, $strict
 * adds the Referer check, which only state-changing entry points use.
 */
function requireSameOrigin(bool $json = true, bool $strict = false): void
{
    if (!sameOriginViolation($strict)) {
        return;
    }
    http_response_code(403);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Forbidden origin']);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<title>Forbidden origin</title></head><body>'
        . '<h1>Forbidden origin</h1>'
        . '<p>This request came from another site and was refused.</p>'
        . '</body></html>';
    exit;
}

requireSameOrigin();

// Installing writes files, so the machine install gets the strict check too.
if (jsonApiRequested() && ($_REQUEST['action'] ?? '') === 'install') {
    requireSameOrigin(true, true);
}

// Machine API — JSON only, API-key protected. It owns its own actions and always
// answers JSON, so it must run before the legacy dispatch below. Legacy actions
// (check/apply/backups/version/index_*/upload_md/save_clipboard) are untouched:
// they are only routed here when format=json or an api_key is present.
if (jsonApiRequested()) {
    handleJsonApi();
}

// Explicit action parameter: the legacy JSON API (check/apply/backups/version/
// index_*/upload_md/save_clipboard). A request without an action parameter is a
// plain browser hit and falls through to the default landing page at the end of
// this file, which is the documented behaviour for opening updater.php directly.
if (isset($_REQUEST['action'])) {
    $action = (string) $_REQUEST['action'];
    $method = $_SERVER['REQUEST_METHOD'];
    $mutating = ['apply', 'restore', 'index_create', 'index_remove', 'upload_md', 'save_clipboard'];
    if (in_array($action, $mutating, true)) {
        // Refuse a cross-site request before the action reads or writes anything.
        requireSameOrigin(true, true);
    }
    if (in_array($action, $mutating, true) && $method !== 'POST') {
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
}

// ── Actions ───────────────────────────────────────────────────────────────────

function doVersion(): never
{
    echo json_encode(['version' => localVersion('md.php')]);
    exit;
}

function doBackups(): never
{
    requireAllowRestore();
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
    $conflicts = [];
    $backupVer = localVersion('md.php');

    $docsFiles = ['README.md', 'LICENSE'];
    $force     = !empty($_POST['force']) || !empty($_GET['force']);
    foreach (TRACKED_FILES as $file) {
        $isDoc        = in_array($file, $docsFiles, true);
        $locVerBefore = localVersion($file); // always read — works for README/LICENSE too
        $updater      = makeUpdater($file);
        $result       = $updater->apply(backupVersion: $isDoc ? '' : $backupVer, force: $force);

        if ($result['status'] === 'blocked') {
            // Foreign file at a tracked path - reported, never replaced silently.
            $skipped[]   = $file;
            $conflicts[] = ['path' => $file, 'reason' => $result['error'] ?? 'conflict'];
            continue;
        }

        match ($result['status']) {
            'current' => $skipped[] = $file,
            'updated', 'created', 'force-updated' => $updated[] = [
                'path'        => $file,
                'fromVersion' => $locVerBefore,
                'toVersion'   => localVersion($file),
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
        'conflicts'  => $conflicts,
        'failed'     => $failed,
        'backupVer'  => $backupVer,
        'newVersion' => localVersion('md.php'),
    ], JSON_PRETTY_PRINT);
    exit;
}

function doRestore(): never
{
    requireAllowRestore();
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
    $ini = readIni();
    if (!(bool)($ini['ALLOW_CREATE_INDEX_PHP_LINK'] ?? true)) {
        echo json_encode(['disabled' => true]);
        exit;
    }
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
    requireAllowIndexLink();
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
    requireAllowIndexLink();
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

    // v3.12.0 - per-directory settings. The optional "dir" parameter names a
    // directory below the browse root; traversal is answered with 400 before
    // anything is read, and the .md.ini chain of that directory decides the
    // flag below (the browse root governs when the parameter is absent).
    $segments = requestDirSegments();
    $flags    = effectiveFlags($ini, writeRoot($ini)[0], $segments ?? []);

    if ($flags['DISABLE_UPLOAD']) {
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

    // ── 4. Ensure destination directory exists ──────────────────────────────
    if ($segments === null) {
        // No "dir" parameter: the classic destination, byte for byte.
        [$uploadsDir, $uploadsLabel] = destinationDir($ini);
    } else {
        [$uploadsDir, $uploadsLabel] = dirInRoot($ini, $segments);
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

    echo json_encode(['success' => true, 'filename' => $name, 'path' => $uploadsLabel . $name]);
    exit;
}

// ── Clipboard → File Save ─────────────────────────────────────────────────────

function doSaveClipboard(): never
{
    // ── 0. Check server-side disable flag ────────────────────────────────────
    $iniPath = dirname(localPath('md.php')) . '/.md.ini';
    $ini     = is_file($iniPath) ? (@parse_ini_file($iniPath, false, INI_SCANNER_TYPED) ?: []) : [];

    // v3.12.0 - per-directory settings, see doUploadMd().
    $segments = requestDirSegments();
    $flags    = effectiveFlags($ini, writeRoot($ini)[0], $segments ?? []);

    if ($flags['DISABLE_SAVE_CLIPBOARD_TO_FILE']) {
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

    // ── 3. Ensure destination directory exists ───────────────────────────────
    if ($segments === null) {
        // No "dir" parameter: the classic destination, byte for byte.
        [$uploadsDir, $uploadsLabel] = destinationDir($ini);
    } else {
        [$uploadsDir, $uploadsLabel] = dirInRoot($ini, $segments);
    }

    $dest = $uploadsDir . '/' . $name;

    // ── 4. Write file ─────────────────────────────────────────────────────────
    if (file_put_contents($dest, $content, LOCK_EX) === false) {
        jsonError(500, 'Could not write file. Check directory permissions.');
    }

    echo json_encode(['success' => true, 'filename' => $name, 'path' => $uploadsLabel . $name]);
    exit;
}

// ── Default landing page ──────────────────────────────────────────────────────
// Shown when updater.php is opened with no recognised action parameter.
// Provides one-file install instructions and a link to run the update.

$ini          = readIni();
$allowUpdate  = (bool)($ini['ALLOW_UPDATE']  ?? false);
$allowRestore = (bool)($ini['ALLOW_RESTORE'] ?? false);
$mdExists     = is_file(docRoot() . '/md.php');
$iniPath      = docRoot() . '/.md.ini';

$statusRows = [];
foreach (TRACKED_FILES as $f) {
    $exists = is_file(localPath($f));
    $ver    = $exists ? localVersion($f) : null;
    $statusRows[] = ['file' => $f, 'exists' => $exists, 'version' => $ver];
}

http_response_code(200);
header('Content-Type: text/html; charset=UTF-8');
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
    .section{padding:1.25rem 1.5rem;border-bottom:1px solid #f1f5f9}
    .section:last-child{border:none}
    .section h2{font-size:.95rem;font-weight:700;margin-bottom:.75rem;color:#1e293b}
    .rows{padding:.25rem 0}
    .row{display:flex;align-items:baseline;gap:.75rem;padding:.5rem 1.5rem;border-bottom:1px solid #f1f5f9}
    .row:last-child{border:none}
    .badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:.72rem;font-weight:700;white-space:nowrap}
    .ok   .badge{background:#dcfce7;color:#166534}
    .miss .badge{background:#fee2e2;color:#991b1b}
    .file{font-family:monospace;font-size:.85rem;flex:1;word-break:break-all;color:inherit;text-decoration:none}
    .file:hover{text-decoration:underline}
    .ver{font-size:.75rem;color:#64748b}
    .actions{padding:1.25rem 1.5rem;display:flex;flex-wrap:wrap;gap:.75rem;background:#f8fafc;border-top:1px solid #e2e8f0}
    .btn{display:inline-block;padding:.55rem 1.25rem;background:#1e293b;color:#f8fafc;
         border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:600}
    .btn:hover{background:#334155}
    .btn-green{background:#15803d}.btn-green:hover{background:#166534}
    .btn-amber{background:#b45309}.btn-amber:hover{background:#92400e}
    .btn-blue{background:#1d4ed8}.btn-blue:hover{background:#1e40af}
    .notice{background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:.75rem 1rem;font-size:.85rem;margin:.5rem 0;color:#713f12}
    .notice code{background:#fef08a;padding:1px 4px;border-radius:3px}
    code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-size:.85em}
    @media(prefers-color-scheme:dark){
        body{background:#0f172a;color:#e2e8f0}
        .card{background:#1e293b;box-shadow:0 4px 32px rgba(0,0,0,.4)}
        .row,.section{border-color:#334155}
        .actions{background:#0f172a;border-color:#334155}
        .ok   .badge{background:#14532d;color:#bbf7d0}
        .miss .badge{background:#450a0a;color:#fca5a5}
        code{background:#334155}
        .notice{background:#422006;border-color:#92400e;color:#fde68a}
        .notice code{background:#78350f}
    }
</style></head><body>';

echo '<div class="card">';
echo '<div class="card-head"><h1>MD.Viewer Updater</h1>';
echo '<p>v' . htmlspecialchars(localVersion('updater.php') ?: '—') . '</p>';
echo '</div>';

// ── File status table ─────────────────────────────────────────────────────────
echo '<div class="section"><h2>File status</h2></div>';
echo '<div class="rows">';
$rawBase = RAW_BASE;
foreach ($statusRows as $r) {
    $cls = $r['exists'] ? 'ok' : 'miss';
    $badge = $r['exists'] ? 'present' : 'missing';
    $fileUrl = $rawBase . '/' . ltrim($r['file'], '/');
    echo '<div class="row ' . $cls . '">';
    echo '<span class="badge">' . $badge . '</span>';
    echo '<a class="file" href="' . htmlspecialchars($fileUrl) . '" target="_blank" rel="noopener">'
       . htmlspecialchars($r['file']) . '</a>';
    if ($r['version']) {
        echo '<span class="ver">v' . htmlspecialchars($r['version']) . '</span>';
    }
    echo '</div>';
}
echo '</div>';

// ── .md.ini status ────────────────────────────────────────────────────────────
echo '<div class="section"><h2>.md.ini</h2>';
if ($allowUpdate) {
    echo '<p style="font-size:.85rem;color:#15803d">✓ ALLOW_UPDATE = true — update system enabled</p>';
} else {
    echo '<div class="notice">⚠ <strong>ALLOW_UPDATE = false</strong> in <code>.md.ini</code>. ';
    echo 'To enable updates, edit <code>.md.ini</code> and set <code>ALLOW_UPDATE = true</code>.</div>';
}
if ($allowRestore) {
    echo '<p style="font-size:.85rem;color:#15803d;margin-top:.5rem">✓ ALLOW_RESTORE = true — restore system enabled</p>';
}
echo '</div>';

// ── Machine API status ─────────────────────────────────────────────────────────
// The key value itself is never printed — only whether it is configured.
$apiReady = false;
if (is_file($iniPath)) {
    $iniRaw   = (string) @file_get_contents($iniPath);
    $apiReady = (bool) preg_match('/^[ \t]*API_KEY[ \t]*=[ \t]*\S/mi', $iniRaw);
}
echo '<div class="section"><h2>Machine API (JSON)</h2>';
if ($apiReady) {
    echo '<p style="font-size:.85rem;color:#15803d">✓ API_KEY is configured in <code>.md.ini</code> — installs via the JSON API are enabled</p>';
} else {
    echo '<div class="notice">⚠ No <strong>API_KEY</strong> found in <code>.md.ini</code>. ';
    echo 'Open this page once to generate it, or set <code>API_KEY = your-value</code> yourself.</div>';
}
echo '<p style="font-size:.8rem;color:#64748b;margin-top:.5rem;line-height:1.6">';
echo '<code>?api_key=&lt;key&gt;&amp;action=status&amp;format=json</code><br>';
echo '<code>?api_key=&lt;key&gt;&amp;action=conflicts&amp;format=json</code><br>';
echo '<code>POST ?api_key=&lt;key&gt;&amp;action=install&amp;format=json&amp;dry_run=1</code>';
echo '</p>';
echo '<p style="font-size:.8rem;color:#64748b;margin-top:.5rem">';
echo 'The API answers JSON only and needs no <code>ALLOW_UPDATE</code> flag — the key itself is the install credential. ';
echo 'Browser modes above keep honouring <code>ALLOW_UPDATE</code> / <code>ALLOW_RESTORE</code>.';
echo '</p>';
echo '</div>';

// ── Actions ───────────────────────────────────────────────────────────────────
echo '<div class="actions">';
if ($allowUpdate) {
    echo '<a class="btn btn-green" href="?update=true">↓ Check &amp; Apply Updates</a>';
    echo '<a class="btn btn-amber" href="?update=true&force=true">↺ Force Reinstall All</a>';
}
if ($allowRestore) {
    echo '<a class="btn btn-blue" href="?restore=latest">⟲ Restore Latest Backup</a>';
}
if (!$allowUpdate && !$allowRestore) {
    echo '<span style="font-size:.85rem;color:#64748b">Enable <code>ALLOW_UPDATE</code> or <code>ALLOW_RESTORE</code> in <code>.md.ini</code> to see actions here.</span>';
}
echo '<a class="btn" href="/">← Back</a>';
echo '</div>';

echo '</div></body></html>';
exit;

