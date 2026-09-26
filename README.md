<!--
 * MD.Viewer — Documentation
 * Version: 2.9.6
-->

# MD.Viewer

> A secure, self-hosted PHP Markdown viewer and browser that turns plain `.md` files into polished documentation pages with a searchable file browser, auto-generated table of contents, heading numbering, Mermaid diagrams, glossary tooltips, responsive reading controls, dark mode, a built-in self-updater, file upload, clipboard preview, and server-side configuration. No build step. No database. No framework.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://github.com/paulmann/MD.Viewer/blob/main/LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![md.php](https://img.shields.io/badge/md.php-v2.9.4-success)](https://github.com/paulmann/MD.Viewer)
[![updater.php](https://img.shields.io/badge/updater.php-v3.9.0-4f46e5)](https://github.com/paulmann/MD.Viewer)

---

## Overview

MD.Viewer is built around **`md.php`**, a single PHP entry point that renders a matching Markdown file, or switches to a recursive Markdown file browser when no matching file exists. The project is designed for simple deployment: copy files to a PHP host, place your `.md` documents nearby, and open `md.php` in a browser.

The viewer focuses on self-hosted documentation without a toolchain. It supports readable typography, heading numbering, automatic table of contents generation, Mermaid rendering with auto-repair and English console diagnostics, shared code and quote copy controls, glossary tooltips, dark mode, mobile-friendly controls, and a Settings panel that stores viewer preferences locally.

**`updater.php`** is a companion self-update and maintenance engine. It checks GitHub raw files using ETag and SHA-256, creates versioned backups before replacement, restores previous versions from the Settings panel or directly via URL, manages an optional `index.php` hard link, handles `.md` file uploads, and supports direct browser-based updates and rollbacks.

Server-side behavior is controlled through a **`.md.ini`** configuration file placed next to `md.php`. This file hard-locks feature flags that cannot be overridden from the browser or Settings panel.

---

## Quick start

```bash
# Clone into your web root or any subdirectory
git clone https://github.com/paulmann/MD.Viewer.git /var/www/html/docs

# Create a Markdown file that matches the script name
echo "# Hello, World\n\nThis is my documentation." > /var/www/html/docs/md.md

# Open in a browser
# https://your-domain.com/docs/md.php
```

Minimal setup:

- Place **`md.php`** (with the `js/md/` and `css/md/` folders) next to **`md.md`**.
- Open **`md.php`** in a browser.
- The script automatically looks for a Markdown file with the same base name.

Optional:

- Add **`updater.php`** to enable in-app update checks, apply updates, backups, restore, file uploads, and `index.php` link management.
- Set **`ALLOW_UPDATE = true`** in `.md.ini` to enable the update system (disabled by default).

### One-file bootstrap install

The fastest way to install is to upload **only `updater.php`** to your server, then open it in a browser with `?update=true`. It will:

1. Create `.md.ini` with safe defaults automatically.
2. Download all tracked files (`md.php`, `js/md/*`, `css/md/*`, `README.md`, `LICENSE`) from GitHub.
3. Create all required subdirectories (`js/md/`, `css/md/`) with `0755` permissions if they do not exist.
4. Place itself in the final list and show a full result page.

**Requirement:** for the browser mode, `ALLOW_UPDATE = true` must be set in `.md.ini` before running (or add it manually to the auto-created `.md.ini` and reload). For the machine API, `API_KEY` from `.md.ini` is the credential instead — see [Machine API](#4-machine-api-json-for-external-clients).

```bash
# Upload only updater.php, then:
curl -o updater.php https://raw.githubusercontent.com/paulmann/MD.Viewer/main/updater.php
# Edit .md.ini (auto-created on first hit) and set ALLOW_UPDATE = true
# Then open: https://your-domain.com/updater.php?update=true
#
# Or drive it over the machine API (no ALLOW_UPDATE needed, key from .md.ini):
curl "https://your-domain.com/updater.php?api_key=<key>&action=conflicts&format=json"
curl -X POST "https://your-domain.com/updater.php?api_key=<key>&action=install&format=json&dry_run=1"
```

---

## Requirements

- PHP **8.3+**.
- PHP extensions: `mbstring` and `curl` (required for updater and clipboard).
- A web server such as Apache, Nginx, Caddy, or the PHP built-in server.
- Browser-side internet access for CDN assets (Mermaid, syntax highlighting, Tailwind) unless replaced with local copies.

---

## Installation

### Git clone

```bash
git clone https://github.com/paulmann/MD.Viewer.git
cd MD.Viewer
```

### Manual install

1. Download the repository or release archive.
2. Copy `md.php`, `updater.php`, `js/`, `css/`, and optionally `README.md` into your target directory.
3. Add your Markdown files in the same directory tree.
4. Open `md.php` in a browser. `.md.ini` is created automatically on first load.

### One-file install via updater

1. Upload only `updater.php` to your server directory.
2. Open `updater.php` once in a browser — it auto-creates `.md.ini` with `ALLOW_UPDATE = false`.
3. Edit `.md.ini` on the server: set `ALLOW_UPDATE = true`.
4. Open `https://your-domain.com/updater.php?update=true`.
5. The updater downloads all files, creates `js/md/` and `css/md/` directories as needed, and shows a result page.
6. Open `md.php` — your MD.Viewer is ready.
7. Optional: install without touching `ALLOW_UPDATE` by calling the machine API with 
   `API_KEY` from `.md.ini` (see [Machine API](#4-machine-api-json-for-external-clients)).

### PHP built-in server

```bash
cd /path/to/MD.Viewer
php -S localhost:8080
# then open http://localhost:8080/md.php
```

### Nginx example

```nginx
server {
    listen 80;
    server_name docs.example.com;
    root /var/www/html/docs;
    index index.php md.php;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

---

## File layout

```text
MD.Viewer/
├── md.php                     # Main viewer / browser script       v2.9.4
├── updater.php                # Self-updater, backup, upload        v3.9.0
├── .md.ini                    # Server-side config (auto-created)
├── md.md                      # Default Markdown file for md.php
├── uploads.md/                # Created automatically for uploaded/saved files
├── md.backup/                 # Created automatically when updates/restores run
│   ├── 2.8.2/
│   ├── 2.8.2-pre-restore/     # Auto-created before each rollback
│   └── .state/                # ETag + SHA-256 per-file cache
├── css/
│   └── md/
│       ├── md.css             # v2.2.2
│       ├── settings.css       # v2.8.1
│       └── tooltips.css       # v2.4.5
├── js/
│   └── md/
│       ├── md.js              # v2.5.2
│       ├── settings.js        # v2.8.7
│       ├── tooltips.js        # v2.4.5
│       └── upload.js          # v2.8.0
├── LICENSE                    # v1.0.0
└── README.md                  # v2.9.6
```

Key naming rule: `md.php` looks for `md.md`, `docs.php` looks for `docs.md`. Multiple independent viewer instances can share one directory tree without extra routing.

---

## Server-side configuration — `.md.ini`

On first request, `md.php` (or `updater.php`) creates `.md.ini` in the same directory. This file hard-locks feature flags so they **cannot** be changed from the browser or Settings panel. Missing keys are automatically appended with safe defaults on every load.

```ini
; MD.Viewer server-side configuration

; Disable the "Upload .md" button in the file browser (recommended: true)
DISABLE_UPLOAD    = true

; Disable the "Preview Clipboard" button in the file browser
DISABLE_CLIPBOARD = false

; Disable the "Save to File" button in clipboard preview
DISABLE_SAVE_CLIPBOARD_TO_FILE = true

; Allow updating files via updater.php?update=true or the Settings panel
ALLOW_UPDATE = false

; Allow restoring a backup via updater.php?restore=latest or ?restore=[version]
ALLOW_RESTORE = false

; Allow creating/removing the index.php hard link from the Settings panel
ALLOW_CREATE_INDEX_PHP_LINK = true

; Machine API key used by external clients (a control panel, an installer)
; Generated automatically on first run. Send it as ?api_key=<value>.
API_KEY = mdv_0123456789abcdef0123456789abcdef0123456789abcdef
```

| Key | Default | Effect |
|---|---|---|
| `DISABLE_UPLOAD` | `true` | Hides and disables the Upload button |
| `DISABLE_CLIPBOARD` | `false` | Hides and disables the Clipboard Preview button |
| `API_KEY` | auto-generated | Secret key for the machine API (`?api_key=`); required for JSON installs |
| `DISABLE_SAVE_CLIPBOARD_TO_FILE` | `true` | Hides Save to File in clipboard preview |
| `ALLOW_UPDATE` | `false` | Enables update system (Settings panel + `?update=true`) |
| `ALLOW_RESTORE` | `false` | Enables Backup & Restore section and `?restore=` URL mode |
| `ALLOW_CREATE_INDEX_PHP_LINK` | `true` | Enables Index File section in Settings panel |

**All keys are auto-appended** (with their default values) to existing `.md.ini` files on next load — upgrading never requires manual `.md.ini` edits.

### Per-directory `.md.ini` (v2.12.0 / v3.12.0)

The `.md.ini` next to `md.php` keeps its role as the installation-level file
(`BROWSE_DIR`, `API_KEY`, `ALLOW_*`, and the defaults for the `DISABLE_*` flags).
On top of it, **any directory inside the browse root may carry its own
`.md.ini`**. The settings that apply to a document are the base file with every
`.md.ini` found on the way from the browse root down to the document's directory
applied over it - a deeper file always wins over a shallower one:

```text
ext/mdviewer/.md.ini     base file: installation-level keys, API key
share/.md.ini            applies to everything below share/
share/sub/.md.ini        applies to share/sub/ and below, and wins
```

Only these three keys are honoured in a directory file:

| Key | Default | Effect in that directory |
|---|---|---|
| `DISABLE_UPLOAD` | `true` | Whether **Upload .md** is allowed and where the upload lands |
| `DISABLE_CLIPBOARD` | `false` | Whether the Clipboard Preview button is active |
| `DISABLE_SAVE_CLIPBOARD_TO_FILE` | `true` | Whether **Save to File** is allowed and where the file lands |

Rules:

- The keys `BROWSE_DIR`, `API_KEY`, `ALLOW_UPDATE`, `ALLOW_RESTORE` and
  `ALLOW_CREATE_INDEX_PHP_LINK` are installation-level. They are ignored in a
  directory file, and a note is written to the PHP error log.
- Values are parsed as strictly as in the base file (trimmed, optional paired
  quotes stripped, a value containing a null byte, a newline or more than 255
  bytes discarded). An unreadable file or an unusable value is skipped with an
  error-log entry - it never breaks the page and never falls back to a
  privileged default.
- A directory `.md.ini` is only read when its real path is inside the browse
  root, so a path that tries to climb out with `..` is refused.
- In viewer mode (`?file=sub/doc.md`) the document's directory governs; in file
  browser mode the browse root governs.
- **Upload .md** and **Save to File** write into the directory being viewed. The
  request carries it in the `dir` parameter (relative to the browse root); a
  `dir` value with traversal segments, an absolute path or a null byte is
  answered with `400`, and a directory cannot be left in any other way either.
- Directory `.md.ini` files are optional. Without them behaviour is exactly as
  before.

---

## Usage modes

### Viewer mode

When a matching Markdown file exists, MD.Viewer renders it as a styled document page. It derives the page title from the first top-level heading, builds a description from the first paragraph, renders a table of contents, and applies local viewer preferences.

### File browser mode

When no matching Markdown file exists, MD.Viewer switches to a recursive Markdown browser. The browser scans the current directory tree, lists Markdown files in a searchable table, and opens the selected file with `?file=relative/path.md`.

### Clipboard preview mode

The file browser includes a **Clipboard Preview** button. Paste any Markdown text into the textarea and click Preview — the content is rendered in viewer mode. If `DISABLE_SAVE_CLIPBOARD_TO_FILE = false`, a **Save to File** bar appears with a filename input and Save button that writes into `BROWSE_DIR` (falling back to `uploads.md/` next to `md.php` when that key is unset).

### URL parameters

| Parameter | Meaning | Example |
|---|---|---|
| `?file=path/to/doc.md` | Render a specific Markdown file | `md.php?file=docs/api.md` |

---

## Features

### Markdown rendering

- Headings, paragraphs, emphasis, strong text, inline code, blockquotes, horizontal rules, fenced code blocks.
- Mermaid auto-repair converts PlantUML-style class notes, literal `\n`, unclosed `<br>`, PlantUML `return`, and unquoted parentheses; diagnostics are English-only.
- Ordered and unordered lists, including nested lists.
- Tables with styled output.
- Task lists, footnotes, reference-style links.
- Images and standard links.
- Emoji shortcode support.
- Universal inline patterns for polished document formatting.

### Navigation and structure

- Auto-generated table of contents.
- Optional automatic heading numbering.
- Title splitting by colon for cleaner hero titles.
- Searchable file browser mode.

### Code, diagrams, and rich content

- Syntax-highlighted fenced code blocks with copy button.
- Mermaid diagrams.
- Superscript/subscript support.

### Glossary tooltips

Inline glossary tooltips via `js/md/tooltips.js` and `css/md/tooltips.css`. Terms show a tooltip on hover; the engine resolves variant spellings to the same definition. Includes touch support, 2-second grace period, and smart viewport-aware positioning.

### Settings panel — feature tooltips

Every toggle in the **Features** section of the Settings panel shows a detailed hover tooltip explaining what the feature does and how it affects rendering. Tooltips use the same `.g-tooltip` bubble and positioning engine as Glossary Tooltips — same visual style, same dark-mode support, same touch/keyboard behavior. No additional CSS is required.

### Appearance and reading controls

- Dark mode.
- Adjustable page width, font size, and line height.
- Header toolbar visibility settings (desktop/mobile independently).
- Persistent preferences stored in browser cookies.

### File upload

If `DISABLE_UPLOAD = false` in `.md.ini`, the file browser shows an **Upload .md** button. Uploaded files are saved into `BROWSE_DIR`, or into `uploads.md/` next to `md.php` when that key is unset. With per-directory settings the file lands in the directory being viewed instead, and that directory's own `.md.ini` decides whether the action is allowed. Upload protection is three-layered:

1. UI button hidden/disabled when `DISABLE_UPLOAD = true`.
2. JS click handler early-returns if `MDV_CONFIG.disableUpload` is true.
3. PHP `updater.php`: reads `.md.ini` independently, returns `403` if disabled.

### Clipboard preview and Save to File

Paste Markdown text and render it instantly without saving a file. If `DISABLE_SAVE_CLIPBOARD_TO_FILE = false`, a Save to File bar appears with filename input, Save button, and inline status feedback. Source text is stored in `sessionStorage` when preview opens. The file is written into the directory being viewed, and a per-directory `.md.ini` can disable or re-enable saving there.

### Settings panel

The Settings panel (opened from the header gear icon) includes:

- Viewer preference toggles (theme, width, font, line height, TOC, numbering).
- Header toolbar visibility controls per device type.
- PHP-side feature toggles with descriptive hover tooltips (applied on next page load via cookies).
- Update check and apply controls (visible only when `ALLOW_UPDATE = true`).
- Backup list and restore controls (visible only when `ALLOW_RESTORE = true`).
- `index.php` hard-link management (visible only when `ALLOW_CREATE_INDEX_PHP_LINK = true`).

---

## Built-in updater

`updater.php` provides four ways to update and manage files.

### Updater landing page

Opening `updater.php` without any parameters shows an **information page** with:

- Present / missing status for every tracked file with version numbers.
- Each filename links to the raw file on GitHub (opens in new tab).
- `.md.ini` status: shows a warning if `ALLOW_UPDATE = false` with edit instructions.
- Action buttons (**Check & Apply Updates**, **↺ Force Reinstall All**, **⟲ Restore Latest Backup**) — appear only when the corresponding flag is enabled in `.md.ini`.

### 1. Settings panel (AJAX)

- Check for updates across all tracked files, with per-file version display.
- Apply updates — backs up current files, downloads new versions.
- Force reinstall all files regardless of version (bypasses ETag/SHA-256 cache).
- View and restore backup versions (visible only when `ALLOW_RESTORE = true`).

### 2. Direct update (`?update=true`)

Requires `ALLOW_UPDATE = true` in `.md.ini`.

```
https://your-domain.com/updater.php?update=true
https://your-domain.com/updater.php?update=true&force=true   ← force reinstall
```

**Two-phase bootstrap:**

- **Phase 1** — updates `updater.php` itself first. If the file changed, redirects to `?_phase=2` (with `&force=true` preserved if set) so the **new** updater handles the rest.
- **Phase 2** — updates all remaining tracked files with version delta display.

**`&force=true` flag:**

- Skips ETag and SHA-256 cache entirely — always downloads each file from GitHub.
- Useful after a failed partial update or when local files are corrupted.
- Propagated automatically from phase 1 to phase 2 via redirect URL.
- An amber **↺ Force reinstall all** button appears in the result page footer.

**Directory auto-creation:**

The updater creates any missing subdirectories (`js/md/`, `css/md/`) with `0755` permissions before writing files. This makes `?update=true` and the machine API work from a one-file install.

**Result page:**

- Per-file status badges: `updated` / `force-updated` / `created` / `current` / `error`
- Version delta: `2.7.0 → 2.8.2`
- Each filename is a clickable link to the raw file on GitHub (opens in new tab)
- Footer: **↺ Force reinstall all** button + **← Back** button

### 3. Direct rollback (`?restore=`)

Requires `ALLOW_RESTORE = true` in `.md.ini`.

```
https://your-domain.com/updater.php?restore=latest
https://your-domain.com/updater.php?restore=2.7.0
```

| URL | Behavior |
|---|---|
| `?restore=latest` | Scans `md.backup/`, picks the highest semver version automatically |
| `?restore=X.Y.Z` | Restores the exact named backup version |

**Rollback process:**

1. Validates `ALLOW_RESTORE = true` — shows error page if false.
2. `?restore=latest` scans `md.backup/`, sorts by semver, picks the newest.
3. Validates the version string (alphanumeric, dots, dashes only).
4. Backs up current files to `md.backup/[version]-pre-restore/` before overwriting — rollback of a rollback is always possible.
5. Invalidates per-file ETag cache (`.state/`) so next update check does a full fetch.
6. Outputs HTML result page with `restored` / `skipped` / `error` badges, version delta, and clickable file links.

### 4. Machine API (JSON, for external clients)

A key-protected JSON API lets another program (a control panel, an installer) drive the install and 
render its progress step by step. It never returns HTML, and PHP notices or warnings never 
leak into the response body.

`API_KEY` is generated on the first request and stored in `.md.ini`. Set your own value 
there before deploying if you want to automate the bootstrap without reading the file later.

```
GET  updater.php?api_key=<key>&action=status&format=json
GET  updater.php?api_key=<key>&action=conflicts&format=json
POST updater.php?api_key=<key>&action=install&format=json[&dry_run=1][&force=1]
```

| Action | Method | Purpose |
|---|---|---|
| `status` | GET | Local install state per file (present / missing / version). No network I/O. |
| `conflicts` | GET | Dry-run conflict report. Never writes anything. |
| `install` | POST | Fetch and write files through the pre-write conflict guard. |

Response envelope:

```json
{
  "ok": true,
  "action": "install",
  "version": "2.9.4",
  "steps":     [{"step": "install:css/md/md.css", "status": "ok", "ms": 143, "detail": "2.2.2 → 2.2.2"}],
  "files":     [{"path": "css/md/md.css", "status": "current", "version": "2.2.2"}],
  "conflicts": [{"path": "css/md/settings.css", "status": "same_name_elsewhere",
                 "existing_path": "legacy/components/settings.css", "size_bytes": 1932}],
  "errors":    []
}
```

- `conflicts[].status`: `ok` | `exists` (the exact path already exists) | `same_name_elsewhere`
- `files[].status`: `present` | `missing` | `updated` | `created` | `force-updated` | `current` | `skipped` | `would_write` | `error`
- `steps[].status`: `ok` | `dry-run` | `skip` | `warn` | `error`
- HTTP codes: `200` completed (per-file failures keep `"ok": false` in the body), `400` unknown action, `403` `unauthorized` or `api_key_unavailable`, `405` `install` without POST

`dry_run=1` stops after the conflict report: nothing is fetched and nothing is written. 
`force=1` also replaces a file at a tracked path that does not look like an MD.Viewer file 
(otherwise it is skipped and reported in `conflicts`) and bypasses the ETag/SHA-256 cache. 
The browser modes above keep honouring `ALLOW_UPDATE` / `ALLOW_RESTORE`; the API key is its 
own credential for the JSON actions.
---

## Tracked files

All files managed by the updater (checked, downloaded, backed up):

```
md.php                    v2.9.4
updater.php               v3.9.0
js/md/md.js               v2.5.2
js/md/settings.js         v2.8.7
js/md/tooltips.js         v2.4.5
js/md/upload.js           v2.8.0
css/md/md.css             v2.2.2
css/md/settings.css       v2.8.1
css/md/tooltips.css       v2.4.5
README.md                 v2.9.6
LICENSE                   v1.0.0
```

---

## Backups and restore

Before each file replacement (update or force reinstall), the current local file is copied to `md.backup/[version]/`. The Settings panel lists backup versions, dates, and file counts (visible only when `ALLOW_RESTORE = true`). Restoring first backs up the current version (as `[version]-pre-restore`), then writes the selected files back. `README.md` and `LICENSE` are downloaded and updated but **not** backed up (they are docs, not code).

---

## Security

All security-sensitive actions are protected at **three independent levels**: server `.md.ini` flag, PHP API guard, and client-side UI suppression.

| Feature | `.md.ini` flag | PHP guard | UI suppression |
|---|---|---|---|
| Update (apply/check) | `ALLOW_UPDATE` | `requireAllowUpdate()` | Hidden when false |
| Restore backup | `ALLOW_RESTORE` | `requireAllowRestore()` | Hidden when false |
| List backups | `ALLOW_RESTORE` | `requireAllowRestore()` | Not called when false |
| Create index link | `ALLOW_CREATE_INDEX_PHP_LINK` | `requireAllowIndexLink()` | Hidden when false |
| Remove index link | `ALLOW_CREATE_INDEX_PHP_LINK` | `requireAllowIndexLink()` | Hidden when false |
| Index status | `ALLOW_CREATE_INDEX_PHP_LINK` | Returns `{disabled:true}` | Not called when false |
| File upload | `DISABLE_UPLOAD` | 403 if true | Button hidden |
| Save clipboard | `DISABLE_SAVE_CLIPBOARD_TO_FILE` | 403 if true | Button hidden |
| Machine API (`status`, `conflicts`, `install`) | `API_KEY` | 403 `unauthorized` | Not callable without the key |

Additional protections:

- **`.md.ini`** is never served to the browser — it is read server-side only.
- **Per-directory `.md.ini`** files are read only from directories inside the browse root, and only the `DISABLE_*` keys are taken from them; installation-level keys are ignored and logged.
- **Path traversal** is prevented in all file-serving, upload, and save operations.
- **Upload / Save to File** validate filenames server-side (`.md` extension only, no slashes, no null bytes).
- **CORS guard** in `updater.php` rejects cross-origin requests.
- **All mutating actions** require `POST` (except direct URL modes which require their respective `ALLOW_*` flags).
- **Version strings** in `?restore=` are validated against `[a-zA-Z0-9.\-]` only.
- **Machine API** answers JSON only (`format=json`), requires `API_KEY`, and reports every 
  skipped foreign file instead of overwriting it.

---

## License

Released under the MIT License. See [LICENSE](https://github.com/paulmann/MD.Viewer/blob/main/LICENSE).
