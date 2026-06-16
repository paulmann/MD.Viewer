<?php
/**
 * Markdown Viewer
 * Version: 2.2.9
 * Author: Mikhail Deynekin
 * Site: https://Deynekin.com
 * Email: Mikhail@Deynekin.com
 *
 * Changelog v2.2.9:
 * - FIXED: Duplicate paragraph content — regular paragraph lines were added to
 *   $para[] twice due to a leftover assignment after the Raw HTML block handler.
 *   The Raw HTML block uses `continue`, so plain text lines fell through to two
 *   consecutive `$para[] = trim($line)` statements in the main parsing loop.
 * - FIXED: Footer "Created with ♥" text and other inline HTML content appearing
 *   doubled in rendered output as a result of the above duplication bug.
 *
 * Changelog v2.2.8:
 * - FIXED: Nested fenced code blocks parsed incorrectly — stripCodeBlocks() and
 *   renderMarkdown() stored only the fence CHARACTER (`\`` or ~), ignoring LENGTH.
 *   An inner ``` fence would wrongly close an outer ```` fence (CommonMark §4.5
 *   violation). Now $fenceLen is tracked; closing requires $markerLen >= $fenceLen.
 * - FIXED: collectHeadings() fence detection aligned with the same length-aware
 *   logic for consistency across all parsing passes.
 * - FIXED: removeFootnoteDefinitions() — placeholder lines (\\x02CB…\\x03) no
 *   longer reset IN_FOOTNOTE state; a code-block placeholder inside a multi-line
 *   footnote body no longer prematurely terminates footnote consumption.
 * - FIXED: Lone backtick artifact between adjacent inline-code placeholders when
 *   rendering ` ```lang ` patterns; spurious `` ` `` is now removed post-extraction.
 * - NEW:   removeSourcesSection() function — strips Sources/Bibliography H1/H2
 *   sections before rendering, with full code-block protection via stripCodeBlocks().
 * - IMPROVED: inlineMarkdown() now protects raw HTML tags via \\u{FFFE} placeholders
 *   (step 2b) so inline HTML is never double-escaped or mangled by emphasis regex.
 *
 * Changelog v2.2.7:
 * - CRITICAL FIX: Replaced DOTALL regex in footnote removal with state machine
 *   to prevent capturing placeholders and subsequent content
 * - FIXED: Added explicit placeholder protection (\x02CB{n}\x03) in all regex operations
 * - REFACTORED: Extracted removeReferenceLinkDefinitions() and removeFootnoteDefinitions()
 *   as standalone functions for clarity and testability
 * - IMPROVED: renderMarkdown() now uses explicit state tracking instead of complex lookahead
 * - IMPROVED: Added comprehensive PHPDoc with processing pipeline documentation
 * - IMPROVED: Better error handling for mismatched code fences
 *
 * Changelog v2.2.5  Fixed setext heading rendering (sync with collectHeadings),
 *               ATX regex aligned, closures capture by reference where mutable.
 *
 * Changelog v2.2.3: Robust numeric/Roman prefix detection. Fixes short-title false
 *         negatives (e.g. "5 A"), rejects years/IDs/tech labels, and validates
 *         canonical Roman numerals to avoid matching plain words.
 *
 * Changelog v2.2.2:
 * - FIXED: Duplicate numbering in headings with manual prefix (e.g. "1. Title")
 *          â€” added manual numbering detector that skips auto-numbering for such headings
 * - FIXED: Heading counter no longer increments for manually-numbered headings,
 *          preserving correct sequence for subsequent auto-numbered headings
 * - FIXED: TOC respects manual numbering flag â€” no duplicate prefixes in navigation
 *
 * Changelog v2.2.1:
 * - FIXED: "No .md files found" bug â€” RecursiveDirectoryIterator lacks getDepth() method;
 *          replaced RecursiveCallbackFilterIterator with depth checks via RecursiveIteratorIterator
 * - FIXED: Silent exception swallowing â€” errors now logged via error_log() for diagnostics
 * - SECURITY: Added symlink escape protection in file scanner
 * - IMPROVED: Hidden file filtering now covers files inside hidden directories (e.g. sub/.hidden.md)
 *
 * Changelog v2.2.0:
 * - SECURITY: 8-layer path traversal protection for ?file= parameter
 * - SECURITY: Null-byte, control-char, backspace, URL-encoded attacks rejected
 * - SECURITY: realpath() whitelist validation against base directory
 * - SECURITY: Symlink resolution protection
 * - FEATURE: File browser table when requested .md is not found
 * - FEATURE: Sortable columns (File, Dir, Created, Modified, Size)
 * - FEATURE: Instant search with debounce
 * - FEATURE: Click-to-open in new tab via GET parameter
 * - REFACTOR: All JavaScript moved to /assets/js/md.js
 *
 * Changelog v2.1.0:
 * - FEATURE: Dynamic markdown file loading from PHP script name
 * - FEATURE: Professional copy-to-clipboard for fenced code blocks
 * - IMPROVED: Editor-style code block UI with traffic-light header
 */

declare(strict_types=1);

// Feature toggles
const AUTO_NUMBERING = true;
const AUTO_TOC = true;
const AUTO_FOOTNOTES_LINKS = true;
const DOUBLE_LINE_BREAKS = true;
const CYPHER_PATTERNS = true;
const UNIVERSAL_PATTERNS = true;
const FEATURE_IMAGES = true;
const FEATURE_REF_LINKS = true;
const FEATURE_TASK_LISTS = true;
const FEATURE_FOOTNOTES = true;
const FEATURE_SUBSUP = true;
const FEATURE_EMOJI = true;

const PARAGRAPH_BREAK_STYLE = 'double-br';

// Security limits
const MAX_FILE_PARAM_LENGTH = 255;
const MAX_SCAN_DEPTH = 3;
const MAX_FILES_SCAN = 10000;

$baseDir = __DIR__;
$baseName = pathinfo(__FILE__, PATHINFO_FILENAME);
$defaultMarkdownFile = $baseDir . '/' . $baseName . '.md';

// ============================================================
// SECURITY: Multi-layer file validation
// ============================================================

/**
 * Validate user-supplied file path with defense-in-depth security.
 * Returns absolute real path on success, null on any security violation.
 */
function validateRequestedFile(string $file, string $baseDir): ?string
{
    $file = trim($file);
    
    // Layer 1: Basic length checks
    if ($file === '' || strlen($file) > MAX_FILE_PARAM_LENGTH) {
        return null;
    }
    
    // Layer 2: Null-byte injection prevention
    if (str_contains($file, "\0")) {
        return null;
    }
    
    // Layer 3: Control characters rejection (ASCII 0-31, 127, including backspace \x08)
    if (preg_match('/[\x00-\x1F\x7F]/', $file)) {
        return null;
    }
    
    // Layer 4: URL-decode to prevent encoded traversal attacks (%2e%2e%2f etc.)
    $decoded = urldecode($file);
    if (preg_match('/[\x00-\x1F\x7F]/', $decoded)) {
        return null;
    }
    
    // Layer 5: Reject absolute paths (Unix-style, Windows-style, UNC)
    if (str_starts_with($decoded, '/') || str_starts_with($decoded, '\\') 
        || preg_match('/^[A-Za-z]:/', $decoded) || str_starts_with($decoded, '//')) {
        return null;
    }
    
    // Layer 6: Normalize separators and reject path traversal sequences
    $normalized = str_replace(['\\', '//'], '/', $decoded);
    if (preg_match('#(?:^|/)\.\.(?:/|$)|\.\.$#', $normalized)) {
        return null;
    }
    
    // Layer 7: Strict character whitelist (only safe path characters)
    if (!preg_match('#^[A-Za-z0-9._\-/]+$#', $normalized)) {
        return null;
    }
    
    // Layer 8: Limit directory depth to prevent DoS via deep paths
    $depth = substr_count($normalized, '/');
    if ($depth > MAX_SCAN_DEPTH) {
        return null;
    }
    
    // Layer 9: Must be .md file (case-insensitive)
    if (!str_ends_with(mb_strtolower($normalized), '.md')) {
        return null;
    }
    
    // Layer 10: Resolve base directory real path
    $realBase = realpath($baseDir);
    if ($realBase === false || !is_dir($realBase)) {
        return null;
    }
    
    // Layer 11: Resolve target real path (must exist)
    $fullPath = $realBase . DIRECTORY_SEPARATOR . $normalized;
    $realPath = realpath($fullPath);
    if ($realPath === false) {
        return null;
    }
    
    // Layer 12: Final containment check â€” real path MUST be inside base directory
    $realBasePrefix = $realBase . DIRECTORY_SEPARATOR;
    if ($realPath !== $realBase && !str_starts_with($realPath, $realBasePrefix)) {
        return null;
    }
    
    // Layer 13: Must be a regular file (not directory, not device)
    if (!is_file($realPath)) {
        return null;
    }
    
    // Layer 14: Double-check extension on the real path (post-symlink resolution)
    if (mb_strtolower(pathinfo($realPath, PATHINFO_EXTENSION)) !== 'md') {
        return null;
    }
    
    return $realPath;
}

/**
 * Replace fenced code block contents with inert byte-safe placeholders.
 *
 * Placeholder format: \x02CB{n}\x03 — bytes that never appear in valid UTF-8 Markdown.
 *
 * CommonMark §4.5 compliance:
 *   - Closing fence MUST use the same character as the opening fence.
 *   - Closing fence length MUST be >= opening fence length.
 *   - Closing fence indent MUST be <= opening fence indent.
 *
 * FIXED (v2.2.8): Previous implementation stored only the fence CHARACTER (\`or ~),
 * ignoring the LENGTH. This caused an inner ``` fence to incorrectly close an outer
 * ```` fence, breaking nested code examples such as:
 *
 *   ````markdown        ← opens with 4 backticks
 *   ```mermaid          ← was wrongly treated as closing fence (same char!)
 *   graph TD …
 *   ```
 *   ````
 *
 * Now $fenceLen is tracked and the closing check requires $markerLen >= $fenceLen.
 *
 * @param  string $md  Raw Markdown source.
 * @return array{string, array<string,string>}  [stripped_md, placeholder_map]
 *
 * @since 2.2.7  Initial state-machine implementation.
 * @since 2.2.8  Fixed: track fence length per CommonMark §4.5.
 */
function stripCodeBlocks(string $md): array
{
    $map        = [];
    $lines      = explode("\n", $md);
    $result     = [];
    $inCode     = false;
    $fenceChar  = '';   // '`' or '~'
    $fenceLen   = 0;    // length of opening fence (e.g. 3 for ```, 4 for ````)
    $fenceIndent = 0;   // indent of opening fence
    $codeLines  = [];

    $fencePat = '/^([ \t]*)(`{3,}|~{3,})/u';

    foreach ($lines as $line) {
        $fm = [];
        if (preg_match($fencePat, $line, $fm) === 1) {
            $indent    = strlen($fm[1]);
            $markerChar = substr($fm[2], 0, 1);
            $markerLen  = strlen($fm[2]);

            if (!$inCode) {
                // ── Opening fence ──────────────────────────────────────────
                $inCode      = true;
                $fenceChar   = $markerChar;
                $fenceLen    = $markerLen;   // remember LENGTH, not just char
                $fenceIndent = $indent;
                $codeLines   = [$line];
                continue;
            }

            // ── Potential closing fence ────────────────────────────────────
            // CommonMark §4.5: same char, length >= opening, indent <= opening.
            if ($markerChar === $fenceChar
                && $markerLen  >= $fenceLen
                && $indent     <= $fenceIndent
            ) {
                $codeLines[] = $line;
                $ph = "\x02CB" . count($map) . "\x03";
                $map[$ph] = implode("\n", $codeLines);
                $result[]  = $ph;
                $inCode     = false;
                $fenceChar  = '';
                $fenceLen   = 0;
                $fenceIndent = 0;
                $codeLines  = [];
                continue;
            }

            // Shorter / wrong fence char → treat as regular code content.
        }

        if ($inCode) {
            $codeLines[] = $line;
        } else {
            $result[] = $line;
        }
    }

    // ── Unclosed fence (invalid Markdown, but be safe) ─────────────────────
    if ($inCode && $codeLines !== []) {
        $ph = "\x02CB" . count($map) . "\x03";
        $map[$ph] = implode("\n", $codeLines);
        $result[]  = $ph;
    }

    return [implode("\n", $result), $map];
}

/**
 * Restore placeholders produced by stripCodeBlocks().
 *
 * @since 2.2.6
 */
function restoreCodeBlocks(string $md, array $map): string
{
    return $map !== [] ? strtr($md, $map) : $md;
}

/**
 * Recursively scan for .md files within base directory with safety limits.
 *
 * v2.2.1: Fixed "No .md files found" bug â€” replaced RecursiveCallbackFilterIterator
 *         with manual depth/hidden-file checks inside RecursiveIteratorIterator loop.
 *         Root cause: RecursiveDirectoryIterator lacks getDepth() method (only exists
 *         on RecursiveIteratorIterator), causing silent Error exceptions.
 *         Added error_log() for diagnostics instead of silent swallow.
 *
 * @param string $baseDir  Base directory to scan (absolute path)
 * @param int    $maxDepth Maximum directory depth (0 = base only, 1 = base+1 sublevel)
 * @param int    $maxFiles Safety limit to prevent DoS
 * @return array           Array of file metadata
 */
function scanMarkdownFiles(string $baseDir, int $maxDepth = MAX_SCAN_DEPTH, int $maxFiles = MAX_FILES_SCAN): array
{
    $files = [];
    $realBase = realpath($baseDir);
    
    if ($realBase === false || !is_dir($realBase)) {
        error_log("[MarkdownViewer] scanMarkdownFiles: base directory not found or not readable: {$baseDir}");
        return $files;
    }
    
    try {
        $dirIterator = new RecursiveDirectoryIterator(
            $realBase,
            FilesystemIterator::SKIP_DOTS 
            | FilesystemIterator::CURRENT_AS_FILEINFO 
            | FilesystemIterator::FOLLOW_SYMLINKS
        );
        
        // Use RecursiveIteratorIterator directly â€” it provides getDepth() correctly
        $iterator = new RecursiveIteratorIterator(
            $dirIterator,
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        
        $count = 0;
        foreach ($iterator as $file) {
            // DoS protection: limit total files processed
            if ($count++ >= $maxFiles) {
                error_log("[MarkdownViewer] scanMarkdownFiles: reached max files limit ({$maxFiles})");
                break;
            }
            
            // Only process regular files
            if (!$file->isFile()) {
                continue;
            }
            
            // Depth check â€” use RecursiveIteratorIterator::getDepth() (NOT RecursiveDirectoryIterator)
            // Depth 0 = files in base directory, 1 = files in first-level subfolder, etc.
            if ($iterator->getDepth() > $maxDepth) {
                continue;
            }
            
            // Skip hidden files and files inside hidden directories
            // getSubPathname() returns path relative to base, e.g. ".git/config" or "sub/.hidden.md"
            $subPathname = $iterator->getSubPathname();
            if (preg_match('#(^|[\\\\/])\.#', $subPathname)) {
                continue;
            }
            
            // Only .md files (case-insensitive)
            if (mb_strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            
            // Resolve real path (may differ if symlink)
            $realPath = $file->getRealPath();
            if ($realPath === false) {
                error_log("[MarkdownViewer] scanMarkdownFiles: getRealPath() failed for: " . $file->getPathname());
                continue;
            }
            
            // Build relative path with forward slashes for consistent URL handling
            $relativePath = substr($realPath, strlen($realBase));
            $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR . '/\\');
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            
            // Security: verify file is still inside base directory (symlink escape protection)
            $realBasePrefix = $realBase . DIRECTORY_SEPARATOR;
            if ($realPath !== $realBase && !str_starts_with($realPath, $realBasePrefix)) {
                error_log("[MarkdownViewer] scanMarkdownFiles: symlink escape detected: {$realPath}");
                continue;
            }
            
            $files[] = [
                'file'     => $file->getFilename(),
                'dir'      => dirname($relativePath) === '.' ? '' : dirname($relativePath),
                'path'     => $relativePath,
                'created'  => $file->getCTime(),
                'modified' => $file->getMTime(),
                'size'     => $file->getSize(),
            ];
        }
        
        // Sort by path for predictable output
        usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        
    } catch (Throwable $e) {
        // Log the actual error for diagnostics â€” do NOT silently swallow
        error_log("[MarkdownViewer] scanMarkdownFiles exception: " . $e->getMessage() 
            . " in " . $e->getFile() . ":" . $e->getLine());
        return [];
    }
    
    return $files;
}

/**
 * Format file size in human-readable form.
 */
function formatFileSize(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return number_format($bytes / 1048576, 1) . ' MB';
    return number_format($bytes / 1073741824, 2) . ' GB';
}

/**
 * Format timestamp as ISO-like datetime string.
 */
function formatDateTime(int $timestamp): string
{
    return date('Y-m-d H:i', $timestamp);
}

/**
 * Render the file browser table when no specific .md is loaded.
 */
function renderFilesTable(array $files, ?string $errorMessage = null): string
{
    $html = '<section class="files-browser my-8 rounded-3xl border border-slate-200/80 bg-white/80 shadow-[0_20px_60px_rgba(15,23,42,0.08)] dark:border-slate-700/70 dark:bg-slate-900/75 overflow-hidden">';
    
    $html .= '<div class="border-b border-slate-200/80 px-6 py-5 dark:border-slate-700/70">';
    $html .= '<h2 class="font-display text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">Markdown Files Browser</h2>';
    if ($errorMessage !== null) {
        $html .= '<p class="mt-2 text-sm text-red-600 dark:text-red-400">' . e($errorMessage) . '</p>';
    } else {
        $html .= '<p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Click any row to open the file in a new tab.</p>';
    }
    $html .= '</div>';
    
    // Search bar
    $html .= '<div class="px-6 py-4 border-b border-slate-200/80 dark:border-slate-700/70">';
    $html .= '<div class="relative">';
    $html .= '<svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>';
    $html .= '<input type="text" id="files-search" placeholder="Search files and directories..." class="w-full rounded-xl border border-slate-200 bg-white pl-10 pr-4 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500">';
    $html .= '</div>';
    $html .= '</div>';
    
    // Table
    if (empty($files)) {
        $html .= '<div class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">';
        $html .= '<p class="text-base">No .md files found in this directory tree.</p>';
        $html .= '</div>';
    } else {
        $html .= '<div class="overflow-x-auto">';
        $html .= '<table id="files-table" class="min-w-full border-collapse text-left text-sm">';
        $html .= '<thead class="bg-slate-50 dark:bg-slate-950/40">';
        $html .= '<tr class="border-b border-slate-200 dark:border-slate-800">';
        $html .= '<th data-sort="file" class="sortable px-5 py-3 text-xs font-semibold uppercase tracking-[0.15em] text-slate-600 dark:text-slate-300 cursor-pointer select-none hover:bg-slate-100 dark:hover:bg-slate-900 transition">File <span class="sort-ind ml-1 text-slate-400">â‡…</span></th>';
        $html .= '<th data-sort="dir" class="sortable px-5 py-3 text-xs font-semibold uppercase tracking-[0.15em] text-slate-600 dark:text-slate-300 cursor-pointer select-none hover:bg-slate-100 dark:hover:bg-slate-900 transition">Dir <span class="sort-ind ml-1 text-slate-400">â‡…</span></th>';
        $html .= '<th data-sort="created" class="sortable px-5 py-3 text-xs font-semibold uppercase tracking-[0.15em] text-slate-600 dark:text-slate-300 cursor-pointer select-none hover:bg-slate-100 dark:hover:bg-slate-900 transition">Created <span class="sort-ind ml-1 text-slate-400">â‡…</span></th>';
        $html .= '<th data-sort="modified" class="sortable px-5 py-3 text-xs font-semibold uppercase tracking-[0.15em] text-slate-600 dark:text-slate-300 cursor-pointer select-none hover:bg-slate-100 dark:hover:bg-slate-900 transition">Modified <span class="sort-ind ml-1 text-slate-400">â‡…</span></th>';
        $html .= '<th data-sort="size" class="sortable px-5 py-3 text-xs font-semibold uppercase tracking-[0.15em] text-slate-600 dark:text-slate-300 cursor-pointer select-none hover:bg-slate-100 dark:hover:bg-slate-900 transition text-right">Size <span class="sort-ind ml-1 text-slate-400">â‡…</span></th>';
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody class="divide-y divide-slate-200/80 dark:divide-slate-800">';
        
        foreach ($files as $f) {
            $html .= '<tr class="file-row hover:bg-blue-50/50 dark:hover:bg-blue-950/20 transition cursor-pointer" data-path="' . e($f['path']) . '" data-file="' . e(mb_strtolower($f['file'])) . '" data-dir="' . e(mb_strtolower($f['dir'])) . '" data-created="' . $f['created'] . '" data-modified="' . $f['modified'] . '" data-size="' . $f['size'] . '">';
            $html .= '<td class="px-5 py-3 font-medium text-slate-900 dark:text-slate-100">' . e($f['file']) . '</td>';
            $html .= '<td class="px-5 py-3 text-slate-600 dark:text-slate-400 font-mono text-xs">' . ($f['dir'] !== '' ? e($f['dir']) : '<span class="text-slate-400 dark:text-slate-500">â€”</span>') . '</td>';
            $html .= '<td class="px-5 py-3 text-slate-600 dark:text-slate-400 tabular-nums">' . formatDateTime($f['created']) . '</td>';
            $html .= '<td class="px-5 py-3 text-slate-600 dark:text-slate-400 tabular-nums">' . formatDateTime($f['modified']) . '</td>';
            $html .= '<td class="px-5 py-3 text-slate-600 dark:text-slate-400 tabular-nums text-right">' . formatFileSize($f['size']) . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table></div>';
        $html .= '<div class="px-6 py-3 text-xs text-slate-500 dark:text-slate-400 border-t border-slate-200/80 dark:border-slate-700/70">' . count($files) . ' file(s) found</div>';
    }
    
    $html .= '</section>';
    return $html;
}

// ============================================================
// Helper functions
// ============================================================

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function toStr(mixed $value): string
{
    if (is_string($value)) return $value;
    if (is_scalar($value) || $value === null) return (string) $value;
    if (is_array($value)) {
        $first = reset($value);
        return is_scalar($first) || $first === null ? (string) $first : '';
    }
    return '';
}

function normalizeMarkdown(string $markdown): string
{
    return trim(str_replace(["\r\n", "\r"], "\n", $markdown));
}

function extractMeta(string $markdown): array
{
    $title = 'Markdown Viewer';
    $description = 'Formatted markdown content';
    $titleMatch = [];
    if (preg_match('/^#\s+(.+)$/mu', $markdown, $titleMatch) === 1) {
        $title = trim(toStr($titleMatch[1] ?? ''));
    }
    $descriptionMatch = [];
    if (preg_match('/^##\s+(.+)$/mu', $markdown, $descriptionMatch) === 1) {
        $description = trim(toStr($descriptionMatch[1] ?? ''));
    }
    return [$title, $description];
}

/**
 * Parse numbered source definitions from Markdown.
 *
 * v2.2.7: Added explicit check to skip lines containing placeholders.
 *
 * @since 2.2.6  Code-block contents stripped before matching.
 */
function parseSources(string $markdown): array
{
    [$stripped] = stripCodeBlocks($markdown);

    $sources = [];
    $matches = [];

    preg_match_all(
        '/^(\d+)\.\s+\*\*(.+?)\*\*\s*\R\s*-\s*URL:\s*(https?:\/\/[^\s\r\n]+)/mu',
        $stripped,
        $matches,
        PREG_SET_ORDER
    );

    foreach ($matches as $match) {
        // Skip if this line contains a code block placeholder
        if (str_contains($match[0] ?? '', "\x02CB")) {
            continue;
        }
        
        $id = (int) toStr($match[1] ?? '0');
        if ($id <= 0) continue;
        $sources[$id] = [
            'title' => trim(toStr($match[2] ?? '')),
            'url'   => trim(toStr($match[3] ?? '')),
        ];
    }

    return $sources;
}

/**
 * Detect manual numbering at the start of heading text.
 *
 * Supported formats:
 * - "1. Title", "2) Title", "3: Title"
 * - "2 Title", "5 A"
 * - "1.1 Subsection", "10.2.3 Deep", "10.2.3. Deep"
 * - "I. Intro", "XIV. Chapter", "II Section", "XIV Chapter"
 *
 * Does NOT match:
 * - "2024 year", "101 Title" (number token out of heading range)
 * - "3D", "5G", "4K" (no whitespace after the number token)
 * - "â„–1 Title", "Chapter 1" (number not a leading standalone token)
 * - "M Title", "I Title" (single ambiguous Roman letter without separator)
 * - "Civic duty", "Mix tape" (plain words made of Roman letters)
 *
 * @param string $text Heading text without leading #.
 * @return bool True when the heading starts with manual numbering.
 *
 */
function hasManualNumbering(string $text): bool
{
    $text = trim($text);

    if ($text === '') {
        return false;
    }

    $matches = [];
    $matched = preg_match(
        '/^(?:(?<arabic>\d{1,3}(?:\.\d{1,3})*)|(?<roman>[IVXLCDM]{1,15}))(?<separator>[.):])?\h+(?<title>\S.*)$/u',
        $text,
        $matches
    );

    if ($matched !== 1) {
        return false;
    }

    if (($matches['title'] ?? '') === '') {
        return false;
    }

    $arabic = $matches['arabic'] ?? '';

    if ($arabic !== '') {
        foreach (explode('.', $arabic) as $segment) {
            if ($segment === '' || !ctype_digit($segment) || (int) $segment < 1) {
                return false;
            }
        }

        return (int) explode('.', $arabic)[0] <= 100;
    }

    $roman     = $matches['roman'] ?? '';
    $separator = $matches['separator'] ?? '';

    if ($roman === '') {
        return false;
    }

    // A single Roman letter without an explicit separator is too ambiguous
    // (e.g. "I Title", "M Title") and is intentionally rejected.
    if ($separator === '' && mb_strlen($roman, 'UTF-8') < 2) {
        return false;
    }

    // Canonical Roman numeral guard prevents plain words ("Civic", "Mix",
    // "Did") from being treated as numbering.
    return preg_match(
        '/^M{0,4}(CM|CD|D?C{0,3})(XC|XL|L?X{0,3})(IX|IV|V?I{0,3})$/u',
        $roman
    ) === 1;
}

/**
 * Convert heading text into a GitHub-compatible anchor slug.
 *
 * Mirrors GitHub's slugging algorithm so that hand-written links such as
 * [Vision & Principles](#-vision--principles) resolve correctly to the
 * rendered heading's `id` attribute.
 *
 * Algorithm (matches GitHub behaviour):
 *  1. Strip HTML tags.
 *  2. Lowercase the result (Unicode-aware).
 *  3. Remove everything except Unicode letters, digits, whitespace, hyphens.
 *  4. Collapse all Unicode whitespace variants to a single ASCII space.
 *  5. Replace spaces with hyphens (existing hyphens are preserved as-is).
 *
 * Accepts `string|int` to accommodate numeric footnote/heading identifiers
 * that arrive as bare array keys from PHP's PCRE capture groups, preventing
 * the downstream `TypeError: slugify(): Argument #1 must be of type string,
 * int given` that manifests in {@see renderFootnotes()}.
 *
 * @param  string|int $text Raw heading text (may contain inline markup or emoji).
 * @return string           Non-empty, GitHub-compatible slug; falls back to "section".
 *
 * @since 2.2.3 GitHub-style slugging for anchor-link compatibility.
 * @since 2.2.4 Accepts string|int; fixes TypeError on numeric footnote ids.
 */
function slugify(string|int $text): string
{
    // Normalise to string early â€” all further operations are string-only.
    $text = strip_tags((string) $text);
    $text = mb_strtolower($text, 'UTF-8');

    // Remove every character that is not a Unicode letter, digit,
    // whitespace, or hyphen â€” mirrors GitHub's stripping of emoji, "&", etc.
    $text = (string) preg_replace('/[^\p{L}\p{N}\s\-]+/u', '', $text);

    // Normalise any Unicode whitespace variant (NBSP, thin space, â€¦) to a
    // plain ASCII space, then convert spaces to hyphens in one pass.
    $text = (string) preg_replace('/\s/u', '-', $text);

    // Guard against blank input (all-emoji heading, empty string, â€¦).
    return $text !== '' ? $text : 'section';
}

/**
 * Parse Markdown reference-style link definitions.
 *
 * v2.2.7: Added explicit check to skip lines containing placeholders.
 *
 * @since 2.2.6  Code-block contents stripped before matching.
 *
 * @param string $markdown Full Markdown source.
 * @return array<string, array{url: string, title: string|null}>
 */
function parseReferenceLinks(string $markdown): array
{
    [$stripped] = stripCodeBlocks($markdown);

    $refs    = [];
    $matches = [];
    $pattern = '/^[ \t]*\[([^\]]+)\]:[ \t]*<?([^\s>]+)>?(?:[ \t]+(?:"([^"]+)"|\'([^\']+)\'|))?[ \t]*$/mu';

    if (preg_match_all($pattern, $stripped, $matches, PREG_SET_ORDER) === false) {
        return $refs;
    }

    foreach ($matches as $match) {
        // Skip if this line contains a code block placeholder
        if (str_contains($match[0] ?? '', "\x02CB")) {
            continue;
        }
        
        $key = mb_strtolower(trim($match[1]), 'UTF-8');

        if ($key === '' || isset($refs[$key])) {
            continue;
        }

        $title = '';
        if (isset($match[3]) && $match[3] !== '') {
            $title = $match[3];
        } elseif (isset($match[4]) && $match[4] !== '') {
            $title = $match[4];
        }

        $refs[$key] = [
            'url'   => trim($match[2]),
            'title' => $title !== '' ? trim($title) : null,
        ];
    }

    return $refs;
}

/**
 * Parse Markdown footnote definitions from a document source.
 *
 * v2.2.7: Removed DOTALL flag from regex to prevent capturing placeholders
 *         and subsequent content. Now processes line-by-line instead.
 *
 * @since  2.2.6  Code-block contents stripped before matching.
 * @since  2.2.7  Removed DOTALL, added line-by-line processing
 *
 * @param  string               $markdown Raw Markdown source.
 * @return array<string,string>           Map of footnote-id => body text.
 */
function parseFootnotes(string $markdown): array
{
    if ($markdown === '') {
        return [];
    }

    [$stripped] = stripCodeBlocks($markdown);

    $footnotes = [];
    $lines = explode("\n", $stripped);
    $currentId = null;
    $currentBody = [];
    
    foreach ($lines as $line) {
        // Check if this line starts a footnote definition
        $footnoteMatch = [];
        if (preg_match('/^\[\^([^\]]+)\]:\h*(.*)$/u', $line, $footnoteMatch) === 1) {
            // Save previous footnote if exists
            if ($currentId !== null && $currentBody !== []) {
                $body = trim(implode(' ', $currentBody));
                if ($body !== '' && !array_key_exists($currentId, $footnotes)) {
                    $footnotes[$currentId] = $body;
                }
            }
            
            // Start new footnote
            $currentId = trim($footnoteMatch[1]);
            $currentBody = [trim($footnoteMatch[2])];
        } elseif ($currentId !== null) {
            // Continuation line (indented or empty)
            if (preg_match('/^(?:\h{4}|\t)/u', $line) || trim($line) === '') {
                $currentBody[] = trim($line);
            } else {
                // Footnote ended, save it
                $body = trim(implode(' ', $currentBody));
                if ($body !== '' && !array_key_exists($currentId, $footnotes)) {
                    $footnotes[$currentId] = $body;
                }
                $currentId = null;
                $currentBody = [];
            }
        }
    }
    
    // Save last footnote if exists
    if ($currentId !== null && $currentBody !== []) {
        $body = trim(implode(' ', $currentBody));
        if ($body !== '' && !array_key_exists($currentId, $footnotes)) {
            $footnotes[$currentId] = $body;
        }
    }

    return $footnotes;
}

function parseUniversalPattern(string $pattern): ?array
{
    $pattern = trim($pattern);
    if ($pattern === '') return null;
    if (!preg_match('/[()\[\]{}]|->|<-|--/u', $pattern)) return null;

    $elements = []; $i = 0; $len = strlen($pattern);
    $hasNode = false; $hasRel = false;

    while ($i < $len) {
        $char = $pattern[$i];
        if ($char === '(') {
            $closePos = strpos($pattern, ')', $i);
            if ($closePos === false) break;
            $content = substr($pattern, $i + 1, $closePos - $i - 1);
            $label = $content !== '' ? (str_contains($content, ':') ? trim(explode(':', $content, 2)[1]) : trim($content)) : '';
            $elements[] = ['type' => 'node', 'label' => $label, 'wrapper' => 'paren'];
            $hasNode = true; $i = $closePos + 1; continue;
        }
        if ($char === '[') {
            $closePos = strpos($pattern, ']', $i);
            if ($closePos === false) break;
            $content = substr($pattern, $i + 1, $closePos - $i - 1);
            $label = trim($content);
            $isRel = (bool) preg_match('/(-+>|<-+|--)/u', $pattern);
            $elements[] = ['type' => $isRel ? 'rel' : 'bracket', 'label' => $label, 'wrapper' => 'bracket', 'direction' => 'undirected'];
            if ($isRel) $hasRel = true;
            $i = $closePos + 1; continue;
        }
        if ($char === '{') {
            $closePos = strpos($pattern, '}', $i);
            if ($closePos === false) break;
            $elements[] = ['type' => 'curly', 'label' => trim(substr($pattern, $i + 1, $closePos - $i - 1)), 'wrapper' => 'curly'];
            $i = $closePos + 1; continue;
        }
        if ($char === '-' || $char === '<' || $char === '>') {
            $hasLeftArrow = false; $hasRightArrow = false; $relLabel = ''; $startIdx = $i;
            if ($char === '<' && ($i + 1 < $len) && $pattern[$i + 1] === '-') { $hasLeftArrow = true; $i += 2; }
            elseif ($char === '-') { $i++; }
            if ($i < $len && $pattern[$i] === '[') {
                $closePos = strpos($pattern, ']', $i);
                if ($closePos !== false) {
                    $inner = substr($pattern, $i + 1, $closePos - $i - 1);
                    $relLabel = str_contains($inner, ':') ? trim(explode(':', $inner, 2)[1]) : trim($inner);
                    $i = $closePos + 1;
                }
            }
            if ($i < $len && $pattern[$i] === '-') { $i++; if ($i < $len && $pattern[$i] === '>') { $hasRightArrow = true; $i++; } }
            elseif ($i < $len && $pattern[$i] === '>') { $hasRightArrow = true; $i++; }
            if ($i > $startIdx && ($hasLeftArrow || $hasRightArrow || $relLabel !== '')) {
                $direction = match(true) {
                    $hasLeftArrow && $hasRightArrow => 'bidirectional',
                    $hasLeftArrow => 'left',
                    $hasRightArrow => 'right',
                    default => 'undirected'
                };
                $elements[] = ['type' => 'rel', 'label' => $relLabel, 'direction' => $direction, 'wrapper' => 'arrow'];
                $hasRel = true;
            }
            continue;
        }
        $i++;
    }
    return (UNIVERSAL_PATTERNS && $elements !== []) ? $elements : (($hasNode && $hasRel) ? $elements : null);
}

function renderUniversalElements(array $elements): string
{
    $html = ['<span class="pattern-chain" role="group" aria-label="Pattern visualization">'];
    foreach ($elements as $el) {
        $label = $el['label'] ?? ''; $type = $el['type'] ?? 'unknown'; $wrapper = $el['wrapper'] ?? '';
        if ($type === 'node' || $wrapper === 'paren') {
            $html[] = '<span class="pattern-node" role="term">' . ($label !== '' ? e($label) : '&#8226;') . '</span>';
        } elseif ($type === 'bracket' || $wrapper === 'bracket') {
            $html[] = '<span class="pattern-bracket" role="mark">' . ($label !== '' ? e($label) : '[]') . '</span>';
        } elseif ($type === 'curly' || $wrapper === 'curly') {
            $html[] = '<span class="pattern-curly" role="note">' . ($label !== '' ? e($label) : '{}') . '</span>';
        } elseif ($type === 'rel' || $wrapper === 'arrow') {
            $dir = $el['direction'] ?? 'undirected'; $relLabel = $label ?? '';
            $relHtml = '<span class="pattern-rel" role="link"><span class="pattern-line"></span>';
            if ($dir === 'left' || $dir === 'bidirectional') $relHtml .= '<span class="pattern-arrow">&lsaquo;</span>';
            if ($relLabel !== '') $relHtml .= '<span class="pattern-rel-label">' . e($relLabel) . '</span>';
            if ($dir === 'right' || $dir === 'bidirectional') $relHtml .= '<span class="pattern-arrow">&rsaquo;</span>';
            $relHtml .= '<span class="pattern-line"></span></span>';
            $html[] = $relHtml;
        } else {
            $html[] = '<code class="inline-code">' . e($label) . '</code>';
        }
    }
    $html[] = '</span>';
    return implode('', $html);
}

function emojiMap(): array
{
    return [
        'smile' => '😊', 'laughing' => '😆', 'joy' => '😂', 'heart' => '❤️',
        'thumbsup' => '👍', 'thumbsdown' => '👎', 'warning' => '⚠️', 'error' => '❌',
        'check' => '✅', 'x' => '❌', 'star' => '⭐', 'fire' => '🔥',
        'bulb' => '💡', 'rocket' => '🚀', 'link' => '🔗', 'info' => 'ℹ️',
    ];
}

/**
 * Convert a single line of inline Markdown into safe HTML.
 *
 * Processing pipeline (order is load-bearing):
 *
 *  1. Code spans          â†’ U+FFFC{n}U+FFFC  placeholders
 *  2. Image spans         â†’ U+FFFD{n}U+FFFD  placeholders  â† before e()
 *  3. HTML-escape remaining plain text
 *  4. Emphasis / strong / del / mark / sub / sup
 *  5. Emoji shortcodes
 *  6. Internal / anchor links   (negative lookbehind (?<!!) guards images)
 *  7. Absolute links            (negative lookbehind (?<!!) guards images)
 *  8. Reference-style links
 *  9. Source citations  [[1,2]]
 * 10. Footnote references [^id]
 * 11. Restore image placeholders â†’ raw HTML (never escaped)
 * 12. Restore code placeholders  â†’ <code> or pattern chain
 *
 * Images MUST be extracted before e() so that the generated <img> tag is
 * never HTML-escaped and link handlers cannot mistake [<imgâ€¦>](url) for a
 * hyperlink â€” which is the root cause of the [![badge](img)](url) breakage.
 *
 * @param  string                                         $text      Raw inline text.
 * @param  array<int, array{url:string,title:string}>     $sources   Numbered sources.
 * @param  array<string, array{url:string,title:?string}> $refs      Reference-style links.
 * @param  array<string, string>                          $footnotes Footnote map.
 * @return string Sanitized HTML fragment.
 *
 * @since 2.2.4 Images extracted before e(); fixes [![badge](img)](url) corruption
 *              and eliminates spurious <br> between consecutive badge images.
 */
function inlineMarkdown(
    string $text,
    array  $sources   = [],
    array  $refs      = [],
    array  $footnotes = [],
): string {
	// ── 1. Protect inline code spans ────────────────────────────────────────────
	//
	// CommonMark §6.1: code span is delimited by equal-length backtick strings.
	// The pattern `/`([^`\n]+)`/u` correctly handles single-backtick spans.
	//
	// Edge case: ` ```mermaid ` in the source — the author intended to show the
	// fenced code syntax inline. The parser sees:
	//   span = " "          (backtick + space + backtick)
	//   lone "`"               (first char of ``` — leftover in $text)
	//   span[1] = "mermaid "   (backtick + word + space + backtick)
	//
	// After whitespace spans become plain spaces (step 12), the lone "`" is left
	// dangling between placeholders and renders as a literal backtick in HTML.
	//
	// FIX (v2.2.8): after extracting all spans, remove any lone "`" that sits
	// immediately between two adjacent placeholders — it is always an artifact
	// of the ` ``` ` pattern, never meaningful content.
    $codeSpans = [];
    $text = (string) preg_replace_callback(
        '/`([^`\n]+)`/u',
        static function (array $m) use (&$codeSpans): string {
            $codeSpans[] = $m[1];
            return "\u{FFFC}" . (count($codeSpans) - 1) . "\u{FFFC}";
        },
        $text,
    );

    // Remove lone backtick artifact between adjacent placeholders.
    // Occurs with ` ```lang ` patterns: span[" "] + "`" + span["lang "].
    $text = (string) preg_replace(
        '/(\x{FFFC}\d+\x{FFFC})`(\x{FFFC})/u',
        '$1$2',
        $text,
    );
    // 2. Protect image spans BEFORE e() 
    // This is the critical fix: images are extracted into raw-HTML placeholders
    // so (a) <img> tags are never HTML-escaped, and (b) link handlers cannot
    // see  [<img¦>](url)  and wrap it in a second <a> tag.
    $imageSpans = [];
    if (FEATURE_IMAGES) {
        $text = (string) preg_replace_callback(
            '/!\[([^\]]*)\]\(([^\s)]+)(?:\s+"([^"]*)")?\)/u',
            static function (array $m) use (&$imageSpans): string {
                $alt   = e($m[1] ?? '');
                $src   = e($m[2] ?? '');
                $title = ($m[3] ?? '') !== '' ? ' title="' . e($m[3]) . '"' : '';

                // inline-block + no vertical margins: badges flow in a line,
                // not as block-level elements that generate <br> between them.
                $imageSpans[] =
                    '<img src="' . $src . '" alt="' . $alt . '"' . $title
                    . ' loading="lazy" decoding="async"'
                    . ' class="inline-block align-middle max-w-full"'
                    . ' onerror="this.style.display=\'none\';'
                    .          'this.nextElementSibling?.classList.remove(\'hidden\')" />'
                    . '<span class="hidden text-sm text-slate-500'
                    .           ' dark:text-slate-400">Image: ' . $alt . '</span>';

                return "\u{FFFD}" . (count($imageSpans) - 1) . "\u{FFFD}";
            },
            $text,
        );
    }

// ── 2b. Protect inline HTML tags ─────────────────────────────────────────
$rawHtmlSpans = [];
$text = (string) preg_replace_callback(
    '/<\/?[a-zA-Z][^>]*>/u',
    static function (array $m) use (&$rawHtmlSpans): string {
        $rawHtmlSpans[] = $m[0];
        return "\u{FFFE}" . (count($rawHtmlSpans) - 1) . "\u{FFFE}";
    },
    $text,
);

    // 3. HTML-escape all remaining plain text 
    $escaped = e($text);

    // 4. Emphasis / strong / strikethrough / highlight
    $escaped = (string) preg_replace('/\*\*([^*]+)\*\*/u',          '<strong>$1</strong>', $escaped);
    $escaped = (string) preg_replace('/__([^_]+)__/u',              '<strong>$1</strong>', $escaped);
    $escaped = (string) preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/u', '<em>$1</em>',         $escaped);
    $escaped = (string) preg_replace('/(?<!_)_([^_]+)_(?!_)/u',     '<em>$1</em>',         $escaped);
    $escaped = (string) preg_replace('/~~([^~]+)~~/u',              '<del>$1</del>',        $escaped);
    $escaped = (string) preg_replace('/==([^=]+)==/u',              '<mark>$1</mark>',      $escaped);

    if (FEATURE_SUBSUP) {
        $escaped = (string) preg_replace('/(?<!~)~([^~]+)~(?!~)/u',     '<sub>$1</sub>',  $escaped);
        $escaped = (string) preg_replace('/(?<!\^)\^([^^]+)\^(?!\^)/u', '<sup>$1</sup>',  $escaped);
    }

    // 5. Emoji shortcodes
    if (FEATURE_EMOJI) {
        $map = emojiMap();
        $escaped = str_replace(
            array_map(static fn(string $k): string => ':' . $k . ':', array_keys($map)),
            array_values($map),
            $escaped,
        );
    }

    // Reusable link classes.
    $lc = 'text-blue-600 hover:text-blue-500 dark:text-blue-400'
        . ' dark:hover:text-blue-300 underline-offset-2 hover:underline';

    // 6. Internal / anchor links  [Text](#anchor) or [Text](relative/path) 
    $escaped = (string) preg_replace_callback(
        '/(?<!!)(?<!\\\\)\[([^\]]+)\]\((#[^\s)]+|[^):\s]+(?:\/[^\s)]*)?)\)/u',
        static function (array $m) use ($lc): string {
            $href = trim($m[2] ?? '');
            return $href !== ''
                ? '<a href="' . e($href) . '" class="' . $lc . '">' . $m[1] . '</a>'
                : $m[1];
        },
        $escaped,
    );

    // 7. Absolute links  [Text](https://
    $escaped = (string) preg_replace_callback(
        '/(?<!!)(?<!\\\\)\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/u',
        static function (array $m) use ($lc): string {
            return '<a href="' . e($m[2]) . '" target="_blank" rel="noopener noreferrer"'
                 . ' class="' . $lc . '">' . e($m[1]) . '</a>';
        },
        $escaped,
    );

    // 8. Reference-style links  [Text][key]
    if (FEATURE_REF_LINKS && $refs !== []) {
        $escaped = (string) preg_replace_callback(
            '/(?<!!)(?<!\\\\)\[([^\]]+)\]\[([^\]]*)\]/u',
            static function (array $m) use ($refs, $lc): string {
                $label  = $m[1] ?? '';
                $key    = mb_strtolower(trim($m[2] !== '' ? $m[2] : $m[1]), 'UTF-8');
                if (!isset($refs[$key])) {
                    return '<span class="text-slate-400 dark:text-slate-500"'
                         . ' title="Reference not found">[' . $label . ']</span>';
                }
                $title = $refs[$key]['title'] !== null
                    ? ' title="' . e($refs[$key]['title']) . '"'
                    : '';
                return '<a href="' . e($refs[$key]['url']) . '"'
                     . ' target="_blank" rel="noopener noreferrer"'
                     . $title . ' class="' . $lc . '">' . $label . '</a>';
            },
            $escaped,
        );
    }

    // 9. Source citations  [[1,2,3]]
    $escaped = (string) preg_replace_callback(
        '/\[\[([0-9,\s]+)\]\]/u',
        static function (array $m) use ($sources): string {
            $ids   = array_filter(
                array_map('intval', array_map('trim', explode(',', toStr($m[1] ?? '')))),
            );
            $links = [];
            foreach ($ids as $id) {
                $links[] = isset($sources[$id])
                    ? '<a href="' . e($sources[$id]['url']) . '" target="_blank"'
                      . ' rel="noopener noreferrer"'
                      . ' title="' . e($sources[$id]['title']) . '"'
                      . ' class="source-ref">' . $id . '</a>'
                    : '<span class="source-ref source-ref-missing">' . $id . '</span>';
            }
            return '<sup class="source-group">[' . implode(',', $links) . ']</sup>';
        },
        $escaped,
    );

    // 10. Footnote references  [^id]
    if (FEATURE_FOOTNOTES && $footnotes !== []) {
        $escaped = (string) preg_replace_callback(
            '/\[\^([^\]]+)\]/u',
            static function (array $m) use ($footnotes): string {
                $id    = trim($m[1]);
                $slug  = 'fn-' . slugify($id);
                $label = isset($footnotes[$id])
                    ? ' title="' . e(mb_substr(strip_tags($footnotes[$id]), 0, 100)) . '"'
                    : '';
                return '<a href="#fn-' . e($slug) . '" id="fnref-' . e($slug) . '"'
                     . ' class="footnote-ref" aria-label="Footnote ' . e($id) . '"'
                     . $label . '>'
                     . '<sup class="text-blue-600 dark:text-blue-400 font-semibold">'
                     . '[' . e($id) . ']</sup></a>';
            },
            $escaped,
        );
    }

    // 11. Restore image placeholders 
    if ($imageSpans !== []) {
        $escaped = (string) preg_replace_callback(
            '/\x{FFFD}(\d+)\x{FFFD}/u',
            static fn(array $m): string => $imageSpans[(int) $m[1]] ?? '',
            $escaped,
        );
    }

// ── 11b. Restore inline HTML placeholders ────────────────────────────────
if ($rawHtmlSpans !== []) {
    $escaped = (string) preg_replace_callback(
        '/\x{FFFE}(\d+)\x{FFFE}/u',
        static fn(array $m): string => $rawHtmlSpans[(int) $m[1]] ?? '',
        $escaped,
    );
}

    // ── 12. Restore code placeholders → <code> or pattern chain ──────────────
    $escaped = (string) preg_replace_callback(
        '/\x{FFFC}(\d+)\x{FFFC}/u',
        static function (array $m) use ($codeSpans): string {
            $code    = $codeSpans[(int) $m[1]] ?? '';
            $trimmed = trim($code);

            if ($trimmed === '') {
                return ' ';
            }

            if (UNIVERSAL_PATTERNS || CYPHER_PATTERNS) {
                $elements = parseUniversalPattern($trimmed);
                if ($elements !== null) {
                    return renderUniversalElements($elements);
                }
            }

            return '<code class="inline-code copy-on-click" title="Click to copy">'
                 . e($trimmed)
                 . '</code>';
        },
        $escaped,
    );

    return $escaped;  
}                     

function isTableLine(string $line): bool { $t = trim($line); return $t !== '' && str_starts_with($t, '|') && str_contains($t, '|'); }
function parseTableRow(string $line): array { $t = trim($line); if (str_starts_with($t, '|')) $t = substr($t, 1); if (str_ends_with($t, '|')) $t = substr($t, 0, -1); return array_map(fn(string $c): string => trim($c), explode('|', $t)); }
function isAlignmentSeparator(string $line): bool { $t = trim($line); if (!str_contains($t, '|') || !str_contains($t, '-')) return false; foreach (parseTableRow($t) as $c) { $c = trim($c); if ($c === '' || preg_match('/^:?-{1,}:?$/', $c) !== 1) return false; } return true; }
function parseTableAlignments(string $line): array { $align = []; foreach (parseTableRow($line) as $c) { $c = trim($c); $l = str_starts_with($c, ':'); $r = str_ends_with($c, ':'); $align[] = $l && $r ? 'center' : ($r ? 'right' : 'left'); } return $align; }

function renderTable(array $lines, array $sources = []): string
{
    if (count($lines) < 2) return '';
    $headers = parseTableRow(toStr($lines[0] ?? '')); if ($headers === []) return '';
    $hasAlign = isset($lines[1]) && isAlignmentSeparator(toStr($lines[1]));
    $aligns = $hasAlign ? parseTableAlignments(toStr($lines[1])) : array_fill(0, count($headers), 'left');
    $bodyStart = $hasAlign ? 2 : 1; $bodyLines = array_slice($lines, $bodyStart);
    $html = ['<div class="my-8 overflow-hidden rounded-3xl border border-slate-200/80 bg-white/80 shadow-[0_20px_60px_rgba(15,23,42,0.08)] dark:border-slate-700/70 dark:bg-slate-900/75"><div class="overflow-x-auto"><table class="min-w-full border-collapse text-left text-sm text-slate-700 dark:text-slate-200"><thead class="bg-slate-950 text-white dark:bg-slate-800"><tr>'];
    foreach ($headers as $i => $h) { $a = $aligns[$i] ?? 'left'; $cls = $a === 'center' ? 'text-center' : ($a === 'right' ? 'text-right' : 'text-left'); $html[] = '<th scope="col" class="px-5 py-4 text-xs font-semibold uppercase tracking-[0.18em] ' . $cls . '">' . inlineMarkdown(toStr($h), [], [], []) . '</th>'; }
    $html[] = '</tr></thead><tbody class="divide-y divide-slate-200/80 dark:divide-slate-800">';
    foreach ($bodyLines as $ri => $line) {
        $cells = parseTableRow(toStr($line)); $rc = $ri % 2 === 0 ? 'bg-white/70 dark:bg-slate-950/20' : 'bg-slate-50/80 dark:bg-slate-900/60';
        $html[] = '<tr class="' . $rc . ' hover:bg-slate-100/80 dark:hover:bg-slate-800/50 transition">';
        foreach ($headers as $i => $h) { $a = $aligns[$i] ?? 'left'; $cls = $a === 'center' ? 'text-center' : ($a === 'right' ? 'text-right' : 'text-left'); $html[] = '<td class="px-5 py-4 align-top leading-5 text-slate-600 dark:text-slate-300 ' . $cls . '">' . inlineMarkdown(toStr($cells[$i] ?? ''), [], [], []) . '</td>'; }
        $html[] = '</tr>';
    }
    $html[] = '</tbody></table></div></div>'; return implode("\n", $html);
}


/**
 * Collect markdown headings with stable unique slugs and manual numbering metadata.
 *
 * FIXED (v2.2.8): fence detection now tracks length per CommonMark §4.5,
 * consistent with stripCodeBlocks() and renderMarkdown().
 *
 * @since 2.2.3
 * @since 2.2.8  Fixed: fence length tracking (same fix as renderMarkdown).
 */
function collectHeadings(string $markdown): array
{
    // stripCodeBlocks already uses the corrected length-aware logic.
    [$stripped] = stripCodeBlocks($markdown);

    $headings = [];
    $used     = [];
    $lines    = explode("\n", $stripped);

    // The second fence pass below only needs to handle placeholders, which
    // stripCodeBlocks has already reduced to \x02CBn\x03 tokens. The inline
    // fence loop is therefore kept only as a safety net for any edge cases
    // stripCodeBlocks may have missed, and it now correctly tracks length.
    $inCode    = false;
    $fenceChar = '';
    $fenceLen  = 0;

    for ($i = 0, $n = count($lines); $i < $n; $i++) {
        $line = toStr($lines[$i] ?? '');

        // Placeholders represent already-extracted code blocks — skip entirely.
        if (preg_match('/^\x02CB\d+\x03$/u', $line) === 1) {
            continue;
        }

        // Fence detection — length-aware (CommonMark §4.5)
        $fenceMatch = [];
        if (preg_match('/^ {0,3}(`{3,}|~{3,})/u', $line, $fenceMatch) === 1) {
            $currentFenceChar = substr(toStr($fenceMatch[1] ?? ''), 0, 1);
            $currentFenceLen  = strlen(toStr($fenceMatch[1] ?? ''));

            if (!$inCode) {
                $inCode    = true;
                $fenceChar = $currentFenceChar;
                $fenceLen  = $currentFenceLen;
            } elseif ($currentFenceChar === $fenceChar && $currentFenceLen >= $fenceLen) {
                // Valid closing fence
                $inCode    = false;
                $fenceChar = '';
                $fenceLen  = 0;
            }
            // else: inner fence with fewer chars — falls through as content (ignored anyway)
            continue;
        }

        if ($inCode) {
            continue;
        }

        $lvl = 0;
        $txt = '';

        $atxMatch = [];
        if (preg_match('/^ {0,3}(#{1,6})\s+(.*?)\s*#*\s*$/u', $line, $atxMatch) === 1) {
            $lvl = max(1, min(6, strlen(toStr($atxMatch[1] ?? '#'))));
            $txt = trim(toStr($atxMatch[2] ?? ''));
        } else {
            $setextMatch = [];
            if ($i > 0 && preg_match('/^ {0,3}(=+|-+)\s*$/u', $line, $setextMatch) === 1) {
                $prev = trim(toStr($lines[$i - 1] ?? ''));
                if ($prev !== '' && preg_match('/^ {0,3}#{1,6}\s+/u', $prev) !== 1) {
                    $lvl = substr(toStr($setextMatch[1] ?? '='), 0, 1) === '=' ? 1 : 2;
                    $txt = $prev;
                }
            }
        }

        if ($lvl === 0 || $txt === '') {
            continue;
        }

        $base  = slugify($txt);
        $slug  = $base;
        $count = 1;
        while (isset($used[$slug])) {
            $slug = $base . '-' . ++$count;
        }
        $used[$slug] = true;

        $headings[] = [
            'level'         => $lvl,
            'text'          => $txt,
            'slug'          => $slug,
            'manual_number' => hasManualNumbering($txt),
        ];
    }

    return $headings;
}

function assignHeadingNumbers(array $headings): array
{
    $cnt = [0, 0, 0, 0, 0, 0]; 
    foreach ($headings as &$h) { 
        $lvl = $h['level']; 
        $isManual = $h['manual_number'] ?? false;
        
        // Increment only if NOT manually numbered
        if (!$isManual) {
            $cnt[$lvl - 1]++; 
        }
        // Always reset deeper levels
        for ($i = $lvl; $i < 6; $i++) {
            $cnt[$i] = 0; 
        }
        
        $parts = []; 
        for ($i = 0; $i < $lvl; $i++) {
            $parts[] = $cnt[$i]; 
        }
        while (count($parts) > 1 && $parts[0] === 0) {
            array_shift($parts); 
        }
        $h['number'] = implode('.', $parts); 
    } 
    unset($h); 
    return $headings;
}

function renderTOC(array $headings): string
{
    if (count($headings) < 2) return '';
    $html = ['<nav class="toc my-8 rounded-2xl border border-slate-200/80 bg-slate-50/80 p-6 dark:border-slate-700/70 dark:bg-slate-900/60" aria-label="Table of content"><p class="mb-4 text-xs font-semibold uppercase tracking-[0.24em] text-slate-500 dark:text-slate-400">Table of content</p><ul class="space-y-2 text-sm leading-7">'];
    foreach ($headings as $h) {
        if (!is_array($h)) continue; $lvl = (int)($h['level'] ?? 1); $txt = toStr($h['text'] ?? ''); $slug = toStr($h['slug'] ?? ''); $num = toStr($h['number'] ?? '');
        if ($txt === '' || $slug === '') continue;
        $ind = match($lvl) { 1=>'', 2=>'ml-0', 3=>'ml-4', 4=>'ml-8', 5=>'ml-12', default=>'ml-16' };
        $wgt = $lvl <= 2 ? 'font-semibold text-slate-800 dark:text-slate-100' : 'text-slate-600 dark:text-slate-300';
        $manualNum = $h['manual_number'] ?? false;
        $numStr = (AUTO_NUMBERING && $num !== '' && !$manualNum) ? e($num) . '. ' : '';
        $html[] = '<li class="' . $ind . '"><a href="#' . e($slug) . '" class="' . $wgt . ' hover:text-blue-600 dark:hover:text-blue-400 transition">' . $numStr . e($txt) . '</a></li>';
    }
    $html[] = '</ul></nav>'; return implode("\n", $html);
}

function isMermaidLanguage(string $lang): bool { return in_array(mb_strtolower(trim($lang), 'UTF-8'), ['mermaid','mmd'], true); }

function renderCodeBlock(string $lang, array $buf): string
{
    $code = implode("\n", $buf);
    $lang = trim($lang);
    $isMermaid = isMermaidLanguage($lang);
    $hasLang = $lang !== '';
    $langLabel = $hasLang ? mb_strtoupper($lang, 'UTF-8') : 'CODE';
    
    $html = '<div class="code-block-wrapper group my-8 overflow-hidden rounded-2xl border border-slate-200/80 bg-slate-950 shadow-[0_20px_60px_rgba(15,23,42,0.12)] dark:border-slate-800 dark:shadow-[0_20px_60px_rgba(0,0,0,0.4)]">';
    $html .= '<div class="code-block-header flex items-center justify-between border-b border-slate-800/80 bg-slate-900/90 px-5 py-3">';
    $html .= '<div class="flex items-center gap-3">';
    $html .= '<div class="flex gap-1.5" aria-hidden="true"><span class="h-3 w-3 rounded-full bg-red-500/80"></span><span class="h-3 w-3 rounded-full bg-yellow-500/80"></span><span class="h-3 w-3 rounded-full bg-green-500/80"></span></div>';
    $html .= '<span class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">' . e($langLabel) . '</span>';
    $html .= '</div>';
    $html .= '<button type="button" class="copy-btn inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-slate-400 transition-all duration-200 hover:bg-slate-800 hover:text-white focus:outline-none focus:ring-2 focus:ring-blue-500/50 active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed" aria-label="Copy code to clipboard">';
    $html .= '<svg class="copy-icon h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>';
    $html .= '<svg class="check-icon h-4 w-4 hidden text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
    $html .= '<span class="copy-text">Copy</span><span class="check-text hidden text-green-400">Copied!</span>';
    $html .= '</button></div>';
    $html .= '<div class="overflow-x-auto px-5 py-4 sm:px-6">';
    if ($isMermaid) {
        $html .= '<pre class="mermaid text-slate-200 font-mono">' . e($code) . '</pre>';
    } else {
        $codeClass = $hasLang ? 'language-' . e($lang) . ' text-slate-200 font-mono' : 'text-slate-200 font-mono';
        $html .= '<pre class="text-sm leading-6"><code class="' . $codeClass . '">' . e($code) . '</code></pre>';
    }
    $html .= '</div></div>';
    return $html;
}

function renderSourcesList(array $src): string
{
    if (empty($src)) return '';
    $html = ['<div class="mt-12 border-t border-slate-200/80 pt-8 dark:border-slate-700/70"><h2 class="font-display text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">Sources List</h2><ul class="mt-4 space-y-3 text-sm leading-6 text-slate-600 dark:text-slate-300">'];
    foreach ($src as $id => $s) {
        $html[] = '<li id="source-' . (int)$id . '" class="flex gap-3"><span class="font-mono text-slate-400 dark:text-slate-500 shrink-0">[' . (int)$id . ']</span><span class="break-words"><a href="' . e($s['url']) . '" target="_blank" rel="noopener noreferrer" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">' . e($s['title']) . '</a><br><span class="text-slate-400 dark:text-slate-500 text-xs">' . e($s['url']) . '</span></span></li>';
    }
    $html[] = '</ul></div>'; return implode("\n", $html);
}

/**
 * Render the footnotes section at the end of a document.
 *
 * Produces a semantic <section> containing an ordered list of footnote
 * definitions.  Each item carries a back-link to its inline reference anchor
 * and is fully accessible to screen readers via aria-label attributes.
 *
 * Returns an empty string when the feature flag is off or the input is empty,
 * so the caller requires no guard of its own.
 *
 * @param  array<string|int, string> $fn  Map of footnote-id => body text,
 *                                        as produced by parseFootnotes().
 * @return string                         Rendered HTML fragment, or ''.
 *
 * @since 2.2.4  Explicit (string) cast on $id prevents TypeError in slugify()
 *               when numeric footnote identifiers arrive as int array keys.
 */
function renderFootnotes(array $fn): string
{
    if (!FEATURE_FOOTNOTES || $fn === []) {
        return '';
    }

    $items = [];

    foreach ($fn as $id => $txt) {
        // Cast to string: numeric ids ([^1], [^2]) arrive as int array keys.
        $slug = slugify((string) $id);
        $eid  = e($slug);

        $items[] = sprintf(
            '<li id="fn-%s" class="flex gap-2">'
                . '<a href="#fnref-%s"'
                . ' class="footnote-backlink text-blue-600 dark:text-blue-400 font-bold"'
                . ' aria-label="Back to content">&#8617;</a>'
                . '<span>%s</span>'
            . '</li>',
            $eid,
            $eid,
            inlineMarkdown($txt, [], [], []),
        );
    }

    return implode("\n", [
        '<section'
            . ' class="mt-16 pt-8 border-t border-slate-200/80 dark:border-slate-700/70"'
            . ' aria-label="Footnotes">',
        '  <h3 class="text-lg font-semibold text-slate-800 dark:text-slate-200 mb-4">'
            . 'Footnotes'
            . '</h3>',
        '  <ol class="space-y-3 text-sm text-slate-600 dark:text-slate-300">',
        '    ' . implode("\n    ", $items),
        '  </ol>',
        '</section>',
    ]);
}

function getHeadingClasses(int $lvl): string
{
    return match($lvl) {
        1 => 'text-3xl sm:text-4xl font-bold tracking-tight text-slate-900 dark:text-white mt-10 mb-5',
        2 => 'text-2xl sm:text-3xl font-semibold tracking-tight text-slate-900 dark:text-white mt-8 mb-4 border-b border-slate-200 dark:border-slate-700 pb-2',
        3 => 'text-xl sm:text-2xl font-semibold tracking-tight text-slate-800 dark:text-slate-100 mt-6 mb-3',
        4 => 'text-lg sm:text-xl font-semibold text-slate-800 dark:text-slate-100 mt-5 mb-2',
        5 => 'text-base sm:text-lg font-semibold text-slate-700 dark:text-slate-200 mt-4 mb-2',
        default => 'text-sm sm:text-base font-semibold text-slate-600 dark:text-slate-300 mt-4 mb-2 uppercase tracking-wide',
    };
}

function resolveParagraphGlue(): array
{
    if (!DOUBLE_LINE_BREAKS) return ['glue' => ' ', 'mode' => 'space'];
    return match(PARAGRAPH_BREAK_STYLE) {
        'double-br' => ['glue' => "<br><br>\n", 'mode' => 'br'],
        'paragraph' => ['glue' => "</p>\n<p class=\"mt-2\">", 'mode' => 'paragraph'],
        'space' => ['glue' => ' ', 'mode' => 'space'],
        'nbsp' => ['glue' => "&nbsp;", 'mode' => 'space'],
        default => ['glue' => "<br>\n", 'mode' => 'br'],
    };
}

/**
 * Render normalized Markdown into themed HTML.
 *
 * Processing pipeline:
 *  1. Parse reference links and footnotes from original markdown
 *  2. Strip code blocks into placeholders to protect them from regex
 *  3. Remove reference link definitions (line-by-line, placeholder-safe)
 *  4. Remove footnote definitions (state machine, placeholder-safe)
 *  5. Restore code blocks to original content
 *  6. Parse block-level elements (headings, lists, tables, code blocks, etc.)
 *  7. Render footnotes section at the end
 *
 * v2.2.7: Complete rewrite of ref-link/footnote removal logic.
 *         - Replaced DOTALL regex with state machine to prevent content loss
 *         - Added explicit placeholder protection (\x02CB{n}\x03)
 *         - Extracted removal logic into dedicated closures for clarity
 *         - Improved error handling and edge case coverage
 *
 * @param string $md    Raw markdown content (already normalized)
 * @param array  $src   Parsed sources array from parseSources()
 * @param array  $head  Collected headings array from collectHeadings()
 * @return string       Rendered HTML fragment
 *
 * @since 2.2.5 Setext heading support, ATX regex alignment
 * @since 2.2.7 DOTALL removal, state machine, placeholder protection
 */
function renderMarkdown(string $md, array $src = [], array $head = []): string
{
    if ($md === '') {
        return '<p>Markdown file is empty.</p>';
    }

    // ── Phase 1: Parse reference links and footnotes from original markdown ──
    $refs = FEATURE_REF_LINKS ? parseReferenceLinks($md) : [];
    $fn   = FEATURE_FOOTNOTES ? parseFootnotes($md)      : [];

    // ── Phase 2: Strip code blocks into placeholders ──
    // This protects code block content from being modified by subsequent regex operations
    if (FEATURE_REF_LINKS || FEATURE_FOOTNOTES) {
        [$mdStripped, $cbMap] = stripCodeBlocks($md);

        // ── Phase 3: Remove reference link definitions ──
        if (FEATURE_REF_LINKS) {
            $mdStripped = removeReferenceLinkDefinitions($mdStripped);
        }

        // ── Phase 4: Remove footnote definitions using state machine ──
        if (FEATURE_FOOTNOTES) {
            $mdStripped = removeFootnoteDefinitions($mdStripped);
        }

        // ── Phase 5: Restore code blocks to original content ──
        $md = restoreCodeBlocks($mdStripped, $cbMap);
    }

    // ── Build heading metadata lookup table ──
    $headingMeta = [];
    foreach ($head as $h) {
        if (!is_array($h)) {
            continue;
        }
        $headingMeta[] = [
            'slug'         => toStr($h['slug']          ?? ''),
            'number'       => toStr($h['number']        ?? ''),
            'manualNumber' => (bool) ($h['manual_number'] ?? false),
        ];
    }

    // ── Initialize parser state ──
    $si     = 0;  // heading index counter
    $pc     = 0;  // paragraph counter
    $lines  = explode("\n", $md);
    $n      = count($lines);
    $html   = [];
    $para   = [];
    $inList = false;
    $lType  = '';
    $olC    = 0;
    $inCode = false;
    $cBuf   = [];
    $cLang  = '';
    $fenceChar = '';   // fence character: '`' or '~'
    $fenceLen  = 0;    // fence length: 3 for ```, 4 for ````, etc.
    $tBuf   = [];

    // ── Helper: Flush accumulated paragraph lines ──
    $flushPara = static function () use (&$para, &$html, &$pc, &$src, &$refs, &$fn): void {
        if ($para === []) {
            return;
        }

        $rend = array_values(array_filter(array_map(
            static fn($t): string => inlineMarkdown(trim(toStr($t)), $src, $refs, $fn),
            $para
        )));

        if ($rend === []) {
            $para = [];
            return;
        }

        $hasImage = static fn(string $s): bool => str_contains($s, '<img');

        ['glue' => $defaultGlue, 'mode' => $mode] = resolveParagraphGlue();

        if ($pc > 0) {
            $html[] = '<div class="paragraph-gap" aria-hidden="true"></div>';
        }

        if ($mode === 'paragraph') {
            foreach ($rend as $i => $l) {
                $html[] = '<p' . ($i === 0 ? '' : ' class="mt-3"') . '>' . $l . '</p>';
            }
        } else {
            $parts = '';
            $count = count($rend);
            foreach ($rend as $i => $line) {
                $parts .= $line;
                if ($i < $count - 1) {
                    $next   = $rend[$i + 1];
                    $parts .= ($hasImage($line) || $hasImage($next)) ? ' ' : $defaultGlue;
                }
            }
            $html[] = '<p>' . $parts . '</p>';
        }

        $pc++;
        $para = [];
    };

    // ── Helper: Close open list ──
    $closeList = static function () use (&$inList, &$lType, &$olC, &$html): void {
        if ($inList) {
            $html[] = $lType === 'ol' ? '</ol>' : '</ul>';
            $inList = false;
            $lType  = '';
            $olC    = 0;
        }
    };

    // ── Helper: Flush accumulated table rows ──
    $flushTable = static function () use (&$tBuf, &$html, &$src): void {
        if ($tBuf !== []) {
            $html[] = renderTable($tBuf, $src);
            $tBuf   = [];
        }
    };

    // ── Helper: Render heading with auto-numbering logic ──
    $renderHeading = static function (
        int    $lvl,
        string $txt,
    ) use (&$si, &$html, &$headingMeta, &$src, &$refs, &$fn): void {
        $meta        = $headingMeta[$si] ?? null;
        $slug        = ($meta['slug'] ?? '') !== '' ? $meta['slug'] : slugify($txt);
        $num         = AUTO_NUMBERING ? ($meta['number'] ?? '') : '';
        $manualNum   = $meta['manualNumber'] ?? false;
        $showAutoNum = AUTO_NUMBERING && $num !== '' && !$manualNum;
        $si++;

        $np = $showAutoNum
            ? '<span class="heading-number text-slate-400 dark:text-slate-500 mr-2 font-mono">' . e($num) . '.</span>'
            : '';

        $html[] = sprintf(
            '<h%d id="%s" class="%s">%s%s</h%d>',
            $lvl,
            e($slug),
            getHeadingClasses($lvl),
            $np,
            inlineMarkdown($txt, $src, $refs, $fn),
            $lvl
        );
    };

    // ── Main parsing loop ──
    foreach ($lines as $idx => $raw) {
        $line = toStr($raw);

// ── Fenced code block detection ────────────────────────────────────────────
//
// CommonMark §4.5 compliance (FIXED v2.2.8):
//   - Store fence LENGTH ($fenceLen), not just the character.
//   - Closing fence: same char + length >= opening + indent <= opening.
//   - Inner fences with fewer backticks are CODE CONTENT, not closers.
//
// BROKEN before v2.2.8:
//   $fence = $marker;  // stored only '`' or '~'
//   if ($marker === $fence)  // matched ``` inside ```` → wrong close
//
$fm = [];
if (preg_match('/^ {0,3}(`{3,}|~{3,})\s*([\w+.-]*)\s*$/u', $line, $fm) === 1) {
    $markerChar = substr(toStr($fm[1] ?? ''), 0, 1);
    $markerLen  = strlen(toStr($fm[1] ?? ''));

    if ($inCode) {
        // Closing fence: same char, length >= opening fence length
        if ($markerChar === $fenceChar && $markerLen >= $fenceLen) {
            $html[]  = renderCodeBlock($cLang, $cBuf);
            $inCode  = false;
            $cBuf    = [];
            $cLang   = '';
            $fenceChar = '';
            $fenceLen  = 0;
            continue;
        }
        // Inner fence with fewer chars (or wrong char) → treat as code content
        $cBuf[] = $line;
        continue;
    }

    // Opening fence
    $flushPara();
    $closeList();
    $flushTable();
    $inCode    = true;
    $fenceChar = $markerChar;
    $fenceLen  = $markerLen;   // ← key fix: store length
    $cLang     = toStr($fm[2] ?? '');
    continue;
}

        // ── Inside code block — accumulate lines ──
        if ($inCode) {
            $cBuf[] = $line;
            continue;
        }

        // ── Table row detection ──
        if (isTableLine($line)) {
            $flushPara();
            $closeList();
            $tBuf[] = $line;
            continue;
        }
        $flushTable();

        // ── Empty line — flush paragraph and close list ──
        if (trim($line) === '') {
            $flushPara();
            $closeList();
            continue;
        }

        // ── ATX heading: # Heading ──
        $hm = [];
        if (preg_match('/^ {0,3}(#{1,6})\s+(.*?)\s*#*\s*$/u', $line, $hm) === 1) {
            $flushPara();
            $closeList();
            $renderHeading(
                max(1, min(6, strlen(toStr($hm[1] ?? '#')))),
                trim(toStr($hm[2] ?? ''))
            );
            continue;
        }

        // ── Setext heading: Heading followed by === or --- ──
        $sx = [];
        if ($idx > 0 && preg_match('/^ {0,3}(=+|-+)\s*$/u', $line, $sx) === 1) {
            $prev = trim(toStr($lines[$idx - 1] ?? ''));
            if ($prev !== '' && preg_match('/^ {0,3}#{1,6}\s+/u', $prev) !== 1) {
                $flushPara();
                $closeList();
                $lvl = substr(toStr($sx[1] ?? '='), 0, 1) === '=' ? 1 : 2;
                array_pop($para);
                $renderHeading($lvl, $prev);
                continue;
            }
        }

        // ── Blockquote: > text ──
        $qm = [];
        if (preg_match('/^>\s?(.*)$/u', $line, $qm) === 1) {
            $flushPara();
            $closeList();
            $html[] = '<blockquote class="border-l-4 border-blue-500 pl-4 py-2 my-4 bg-slate-50/50 dark:bg-slate-800/30 rounded-r-lg"><p>'
                . inlineMarkdown(trim(toStr($qm[1] ?? '')), $src, $refs, $fn)
                . '</p></blockquote>';
            continue;
        }

        // ── Task list: - [x] text or - [ ] text ──
        if (FEATURE_TASK_LISTS) {
            $tm = [];
            if (preg_match('/^[-\*\+]\s+\[([ xX])\]\s+(.*)$/u', $line, $tm) === 1) {
                $flushPara();
                $chk  = trim($tm[1]) !== '';
                $itxt = trim(toStr($tm[2] ?? ''));
                if (!$inList || $lType !== 'ul') {
                    $closeList();
                    $html[] = '<ul class="task-list space-y-2">';
                    $inList = true;
                    $lType  = 'ul';
                }
                $html[] = '<li class="flex items-start gap-3' . ($chk ? ' task-done' : '') . '">'
                    . '<input type="checkbox"' . ($chk ? ' checked disabled' : '')
                    . ' class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 disabled:opacity-50">'
                    . '<span class="flex-1">' . inlineMarkdown($itxt, $src, $refs, $fn) . '</span></li>';
                continue;
            }
        }

        // ── Unordered list: - text, * text, + text ──
        $um = [];
        if (preg_match('/^[-*+]\s+(.*)$/u', $line, $um) === 1) {
            $flushPara();
            if (!$inList || $lType !== 'ul') {
                $closeList();
                $html[] = '<ul class="space-y-1">';
                $inList = true;
                $lType  = 'ul';
            }
            $html[] = '<li>' . inlineMarkdown(trim(toStr($um[1] ?? '')), $src, $refs, $fn) . '</li>';
            continue;
        }

        // ── Ordered list: 1. text ──
        $om = [];
        if (preg_match('/^(\d+)\.\s+(.*)$/u', $line, $om) === 1) {
            $flushPara();
            $in   = (int) toStr($om[1] ?? '1');
            $itxt = trim(toStr($om[2] ?? ''));
            if (!$inList || $lType !== 'ol') {
                $closeList();
                $html[] = '<ol start="' . $in . '" class="space-y-1 list-decimal list-inside">';
                $inList = true;
                $lType  = 'ol';
                $olC    = $in;
            }
            $html[] = '<li value="' . $in . '">' . inlineMarkdown($itxt, $src, $refs, $fn) . '</li>';
            $olC = $in;
            continue;
        }

        // ── Horizontal rule: ---, ***, ___ ──
        if (preg_match('/^[-*_]{3,}\s*$/u', trim($line)) === 1) {
            $flushPara();
            $closeList();
            $html[] = '<hr class="my-8 border-slate-200 dark:border-slate-700">';
            continue;
        }

// ── Raw HTML block ──────────────────────────────────────────────────────────
if (preg_match('/^\s*<\/?[a-zA-Z][^>]*>/u', $line)) {
    $flushPara();
    $closeList();
    $html[] = $line;   // без e() — сырой HTML
    continue;
}

        // ── Regular paragraph line ──
        $para[] = trim($line);
    }

    // ── Flush remaining content ──
    if ($inCode) {
        $html[] = renderCodeBlock($cLang, $cBuf);
    }
	$flushPara();
	$closeList();
	$flushTable();

    // ── Render footnotes section at the end ──
    if (FEATURE_FOOTNOTES && $fn !== []) {
        $html[] = renderFootnotes($fn);
    }

    return implode("\n", $html);
}



/**
 * Remove reference link definitions from markdown using line-by-line processing.
 *
 * v2.2.7: Extracted from renderMarkdown() for clarity and reusability.
 *         Skips lines containing code block placeholders (\x02CB{n}\x03).
 *
 * @param string $md Markdown with code blocks replaced by placeholders
 * @return string    Markdown with reference link definitions removed
 */
function removeReferenceLinkDefinitions(string $md): string
{
    $lines  = explode("\n", $md);
    $result = [];
    
    $refLinkPattern = '/^[ \t]*\[([^\]]+)\]:[ \t]*<?([^\s>]+)>?(?:[ \t]+(?:"([^"]+)"|\'([^\']+)\'|))?[ \t]*$/u';
    
    foreach ($lines as $line) {
        // Skip lines containing code block placeholders
        if (str_contains($line, "\x02CB")) {
            $result[] = $line;
            continue;
        }
        
        // Skip reference link definitions
        if (preg_match($refLinkPattern, $line) === 1) {
            continue;
        }
        
        $result[] = $line;
    }
    
    return implode("\n", $result);
}


/**
 * Remove footnote definitions from Markdown using an explicit state machine.
 *
 * Processes the document line-by-line to avoid the catastrophic backtracking
 * and content-loss issues that plague DOTALL-based regex approaches.
 *
 * State machine:
 *   NORMAL       — regular content; watch for [^id]: lines
 *   IN_FOOTNOTE  — consuming footnote body; stop on non-continuation line
 *
 * FIXES vs v2.2.7:
 *   - Placeholder lines (\x02CB…\x03) no longer reset state to NORMAL.
 *     A code-block placeholder may appear inside a footnote's continuation
 *     body; forcibly resetting the state caused subsequent lines of the same
 *     footnote (and sometimes unrelated content) to be mis-classified.
 *     Placeholders are now preserved as-is regardless of current state.
 *
 * @param  string $md  Markdown with fenced code blocks replaced by placeholders
 *                     (output of stripCodeBlocks()).
 * @return string      Markdown with all footnote definitions stripped.
 *
 * @since 2.2.7  Initial state-machine implementation.
 * @since 2.2.8  Fixed: placeholder lines no longer reset IN_FOOTNOTE state.
 */
function removeFootnoteDefinitions(string $md): string
{
    if ($md === '') {
        return $md;
    }

    $lines  = explode("\n", $md);
    $result = [];
    // NORMAL | IN_FOOTNOTE
    $state  = 'NORMAL';

    // [^id]: optional-body-text
    $fnStart      = '/^\[\^([^\]]+)\]:\h*(.*)$/u';
    // Continuation: 4 spaces, a tab, or an empty line
    $fnContinuation = '/^(?:\h{4}|\t)/u';

    foreach ($lines as $line) {
        // Code-block placeholders are ALWAYS preserved verbatim.
        // IMPORTANT: we do NOT touch $state here — a placeholder may legally
        // appear inside a footnote's multi-line body (stripped from a fenced
        // code block that was part of the footnote text).
        if (str_contains($line, "\x02CB")) {
            $result[] = $line;
            continue;
        }

        if ($state === 'NORMAL') {
            if (preg_match($fnStart, $line) === 1) {
                // Footnote definition starts — enter consuming state, drop line.
                $state = 'IN_FOOTNOTE';
                continue;
            }
            $result[] = $line;
        } else {
            // IN_FOOTNOTE: consume continuation lines (indented or blank).
            if (preg_match($fnContinuation, $line) === 1 || trim($line) === '') {
                // Drop continuation line.
                continue;
            }

            // Non-continuation line ends the footnote — emit it as normal content.
            $state    = 'NORMAL';
            $result[] = $line;
        }
    }

    return implode("\n", $result);
}

/**
 * Remove the Sources/Bibliography section from Markdown before rendering.
 *
 * The section is identified by a H1/H2 heading whose text matches one of the
 * canonical names (Sources, Sources List, Источники, Список источников).
 * Everything from that heading to the next same-or-higher-level heading (or
 * the end of the document) is removed.
 *
 * CRITICAL FIXES vs the broken one-liner that existed in v2.2.7:
 *
 *  1. stripCodeBlocks() is called FIRST — the regex never sees the contents
 *     of fenced code blocks, so `## Sources List` inside a ```markdown``` example
 *     is never mistaken for a real section heading.
 *
 *  2. Anchor ^ is present — the heading pattern is line-anchored (flag `m`),
 *     which prevents matching a bare `#` that appears inside an inline code
 *     span such as `## Sources` inside a blockquote.
 *
 *  3. No DOTALL flag — `[^\n]*` instead of `.*` stops at the line boundary,
 *     so the heading line itself is consumed but nothing beyond it unless
 *     the body-lines subpattern explicitly matches them.
 *
 *  4. The body subpattern `(?:\n(?![ \t]*#{1,2}[ \t])[^\n]*)*` eats only lines
 *     that are NOT a same-or-higher-level heading, leaving subsequent sections
 *     intact.
 *
 * @param  string $md  Raw, normalized Markdown source.
 * @return string      Markdown with the Sources section removed.
 *
 * @since 2.2.8
 */
function removeSourcesSection(string $md): string
{
    if ($md === '') {
        return $md;
    }

    // Protect fenced code blocks: their contents must never be inspected
    // by the Sources-heading regex (e.g. ```markdown\n## Sources\n``` examples).
    [$stripped, $cbMap] = stripCodeBlocks($md);

    // Pattern explained:
    //   ^[ \t]*         — optional leading spaces/tabs (up to the indent limit)
    //   #{1,2}[ \t]+    — H1 or H2 marker followed by mandatory whitespace
    //   (?:\d+\.[ \t]+)? — optional manual numbering prefix "10. "
    //   (?:Sources …)   — canonical section names (case-insensitive via flag i)
    //   \b              — word boundary prevents partial matches ("SourcesList")
    //   [^\n]*          — consume the rest of the heading line (NO dotall!)
    //   (?:\n…)*        — consume body lines that are NOT a H1/H2 heading
    $pattern = '/^[ \t]*#{1,2}[ \t]+(?:\d+\.[ \t]+)?'
             . '(?:Sources List|Sources|Источники|Список источников)\b[^\n]*'
             . '(?:\n(?![ \t]*#{1,2}[ \t])[^\n]*)*/mu';

    $stripped = (string) preg_replace($pattern, '', $stripped);

    return restoreCodeBlocks($stripped, $cbMap);
}


// ============================================================
// MAIN: File resolution with security validation
// ============================================================

$mode = 'browser'; // 'viewer' | 'browser'
$errorMessage = null;
$currentFilePath = null;
$markdown = null;

// Only process GET (read operation) â€” POST intentionally ignored for file access
$fileParam = $_GET['file'] ?? null;

if ($fileParam !== null) {
    // User requested a specific file via GET
    if (!is_string($fileParam)) {
        $errorMessage = 'Invalid file parameter: expected string.';
    } else {
        $validated = validateRequestedFile($fileParam, $baseDir);
        if ($validated === null) {
            $errorMessage = 'Access denied or file not found. Path validation failed.';
        } else {
            $currentFilePath = $validated;
            $markdown = @file_get_contents($validated);
            if ($markdown === false) {
                $errorMessage = 'Failed to read file: ' . basename($validated);
                $currentFilePath = null;
            }
        }
    }
} else {
    // No file parameter â€” try default file (scriptname.md)
    if (is_file($defaultMarkdownFile)) {
        $currentFilePath = realpath($defaultMarkdownFile);
        $markdown = @file_get_contents($defaultMarkdownFile);
        if ($markdown === false) {
            $errorMessage = 'Failed to read default file: ' . basename($defaultMarkdownFile);
            $currentFilePath = null;
        }
    }
}

// Decide mode
if ($markdown !== null && $currentFilePath !== null) {
    $mode = 'viewer';
} else {
    $mode = 'browser';
}

// Prepare content based on mode
$title = 'Markdown Viewer';
$desc = '';
$toc = '';
$rend = '';
$srcList = '';
$filesTable = '';

if ($mode === 'viewer') {
    $md = normalizeMarkdown($markdown);
    [$title, $desc] = extractMeta($md);
    $md = (string) preg_replace('/^#\s+.+$/mu', '', $md, 1);
    $src = parseSources($md);
    $md = removeSourcesSection($md);
    $head = collectHeadings($md);
    if (AUTO_NUMBERING) $head = assignHeadingNumbers($head);
    $toc  = AUTO_TOC ? renderTOC($head) : '';
    $rend = renderMarkdown($md, $src, $head);
    $srcList = AUTO_FOOTNOTES_LINKS ? renderSourcesList($src) : '';
} else {
    $title = 'Markdown Files';
    $desc = 'Browse available markdown documents';
    $files = scanMarkdownFiles($baseDir);
    $filesTable = renderFilesTable($files, $errorMessage);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?></title>
    <meta name="description" content="<?= e($desc) ?>">
    <meta name="color-scheme" content="light dark">
    <!-- Critical CSS for FOUC prevention and code block enhancements -->
    <style>
        :root { color-scheme: light; }
        html[data-theme="dark"] { color-scheme: dark; }
        body { margin: 0; font-family: Inter, system-ui, sans-serif; }
        .code-block-wrapper pre {
            scrollbar-width: thin;
            scrollbar-color: #475569 transparent;
        }
        .code-block-wrapper pre::-webkit-scrollbar { height: 8px; }
        .code-block-wrapper pre::-webkit-scrollbar-track { background: transparent; }
        .code-block-wrapper pre::-webkit-scrollbar-thumb { background-color: #475569; border-radius: 4px; }
        .code-block-wrapper pre::-webkit-scrollbar-thumb:hover { background-color: #64748b; }
        .copy-btn .copy-icon, .copy-btn .check-icon { transition: opacity 0.2s ease, transform 0.2s ease; }
        .copy-btn:active:not(:disabled) { transform: scale(0.95); }
        /* File browser row hover effect */
        .file-row:hover td { background-color: rgba(59, 130, 246, 0.05); }
        html[data-theme="dark"] .file-row:hover td { background-color: rgba(59, 130, 246, 0.1); }
        /* Sort indicator states */
        th.sortable.asc .sort-ind::after { content: ' â†‘'; color: #3b82f6; }
        th.sortable.desc .sort-ind::after { content: ' â†“'; color: #3b82f6; }
        th.sortable .sort-ind::after { content: ' â‡…'; }
        /* Hidden rows (search filter) */
        .file-row.filtered-out { display: none; }
        /* No results message */
        #no-results-msg { display: none; }
	#viewer { width: 100%; }
	#viewer > .markdown-body,
	#viewer > .toc,
	#viewer .markdown-body { max-width: none; width: 100%; }
	.markdown-body { line-height: 1.1; max-width: none; width: 100%; }
	.toc ul { line-height: 1; max-width: none;  }
    </style>
    <link rel="stylesheet" href="/assets/css/md.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class', theme: { extend: { fontFamily: { sans: ['Inter','system-ui','sans-serif'], display: ['Sora','Inter','sans-serif'] }, maxWidth: { reading:'72ch', article:'100ch', wide:'160ch' }, boxShadow: { soft: '0 20px 60px rgba(15,23,42,0.10)' } } } };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
</head>
<body class="flex flex-col min-h-screen antialiased">
    <header class="sticky top-0 z-50 border-b border-slate-200/70 bg-white/70 backdrop-blur-xl dark:border-slate-800 dark:bg-slate-950/65">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6 lg:px-8">
            <a href="?" class="font-display text-lg font-semibold text-slate-900 dark:text-white hover:text-blue-600 dark:hover:text-blue-400 transition">MD Viewer</a>
            <div class="flex flex-wrap items-center gap-3">
                <?php if ($mode === 'viewer'): ?>
                <div class="inline-flex items-center rounded-full border border-slate-200 bg-white/80 p-1 shadow-soft dark:border-slate-700 dark:bg-slate-900/80">
                    <button type="button" data-width="reading" class="width-switch rounded-full px-4 py-2 text-sm font-medium text-slate-600 transition hover:text-slate-950 dark:text-slate-300 dark:hover:text-white">Narrow</button>
                    <button type="button" data-width="article" class="width-switch rounded-full px-4 py-2 text-sm font-medium text-slate-600 transition hover:text-slate-950 dark:text-slate-300 dark:hover:text-white">Medium</button>
                    <button type="button" data-width="wide" class="width-switch rounded-full px-4 py-2 text-sm font-medium text-slate-600 transition hover:text-slate-950 dark:text-slate-300 dark:hover:text-white">Wide</button>
                </div>
                <?php endif; ?>
                <button type="button" data-theme-toggle class="inline-flex min-h-11 items-center gap-2 rounded-full border border-slate-200 bg-white/80 px-4 py-2 text-sm font-medium text-slate-700 shadow-soft transition hover:-translate-y-0.5 hover:text-slate-950 dark:border-slate-700 dark:bg-slate-900/80 dark:text-slate-200 dark:hover:text-white" aria-label="ÐŸÐµÑ€ÐµÐºÐ»ÑŽÑ‡Ð¸Ñ‚ÑŒ Ñ‚ÐµÐ¼Ñƒ">
                    <span data-theme-icon aria-hidden="true"></span><span>Theme</span>
                </button>
            </div>
        </div>
    </header>
    <main class="px-4 py-8 sm:px-6 lg:px-8">
        <?php if ($mode === 'viewer'): ?>
        <section id="viewer" data-width-target class="content-shell mx-auto rounded-[2rem] px-6 py-8 shadow-soft transition-[max-width] duration-300 ease-out sm:px-8 lg:px-10 lg:py-10">
            <div class="mb-10 border-b border-slate-200/70 pb-6 dark:border-slate-800">
                <h1 class="font-display text-4xl font-semibold tracking-[-0.05em] text-slate-950 sm:text-5xl dark:text-white"><?= e($title) ?></h1>
                <p class="mt-4 max-w-3xl text-base leading-8 text-slate-600 dark:text-slate-300"><?= e($desc) ?></p>
            </div>
            <?= $toc ?>
            <article class="markdown-body"><?= $rend ?><?= $srcList ?></article>
        </section>
        <?php else: ?>
        <section class="content-shell mx-auto max-w-wide px-6 py-8 sm:px-8 lg:px-10 lg:py-10">
            <?= $filesTable ?>
        </section>
        <?php endif; ?>
    </main>
<footer class="mt-auto border-t border-slate-200/60 bg-white/50 backdrop-blur-sm dark:border-slate-800 dark:bg-slate-950/50">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6 lg:px-8">

        <p class="text-xs text-slate-400 dark:text-slate-500">
            &copy; <?= date('Y') ?> <a href="https://Deynekin.com" target="_blank" rel="noopener noreferrer"
               class="font-medium text-slate-500 transition hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
                Mikhail Deynekin
            </a>
        </p>

        <p class="text-xs text-slate-400 dark:text-slate-500">
            <a href="https://github.com/paulmann/MD.Viewer" target="_blank" rel="noopener noreferrer"
               class="inline-flex items-center gap-1.5 font-medium text-slate-500 transition hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
                <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd"/>
                </svg>
                MD.Viewer
            </a>
            <span class="mx-2 text-slate-300 dark:text-slate-700" aria-hidden="true">&middot;</span>
            <a href="https://github.com/paulmann/MD.Viewer/blob/main/LICENSE" target="_blank" rel="noopener noreferrer"
               class="transition hover:text-slate-800 dark:hover:text-slate-200">
                MIT License
            </a>
        </p>

    </div>
</footer>    
    <!-- Accessible live region for copy status announcements -->
    <div aria-live="polite" aria-atomic="true" class="sr-only" id="copy-status"></div>
    
    <script type="module" src="/assets/js/md.js"></script>
</body>
</html>
