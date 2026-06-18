<!--
 * MD.Viewer — Documentation
 * Version: 2.8.1
-->

# MD.Viewer

> A secure, self-hosted PHP Markdown viewer and browser that turns plain `.md` files into polished documentation pages with a searchable file browser, auto-generated table of contents, heading numbering, Mermaid diagrams, glossary tooltips, responsive reading controls, dark mode, a built-in self-updater, file upload, clipboard preview, and server-side configuration. No build step. No database. No framework.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://github.com/paulmann/MD.Viewer/blob/main/LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![md.php](https://img.shields.io/badge/md.php-v2.8.1-success)](https://github.com/paulmann/MD.Viewer)
[![updater.php](https://img.shields.io/badge/updater.php-v3.7.0-4f46e5)](https://github.com/paulmann/MD.Viewer)

---

## Overview

MD.Viewer is built around **`md.php`**, a single PHP entry point that renders a matching Markdown file, or switches to a recursive Markdown file browser when no matching file exists. The project is designed for simple deployment: copy files to a PHP host, place your `.md` documents nearby, and open `md.php` in a browser.

The viewer focuses on self-hosted documentation without a toolchain. It supports readable typography, heading numbering, automatic table of contents generation, Mermaid rendering, code-copy buttons, glossary tooltips, dark mode, mobile-friendly controls, and a Settings panel that stores viewer preferences locally.

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

- Place **`md.php`** (and the `assets/` folder) next to **`md.md`**.
- Open **`md.php`** in a browser.
- The script automatically looks for a Markdown file with the same base name.

Optional:

- Add **`updater.php`** to enable in-app update checks, apply updates, backups, restore, file uploads, and `index.php` link management.
- Set **`ALLOW_UPDATE = true`** in `.md.ini` to enable the update system (disabled by default).

### One-file bootstrap install

The fastest way to install is to upload **only `updater.php`** to your server, then open it in a browser with `?update=true`. It will:

1. Create `.md.ini` with safe defaults automatically.
2. Download all tracked files (`md.php`, `assets/js/*`, `assets/css/*`, `README.md`, `LICENSE`) from GitHub.
3. Create all required subdirectories (`assets/js/`, `assets/css/`) with `0755` permissions if they do not exist.
4. Place itself in the final list and show a full result page.

**Requirement:** `ALLOW_UPDATE = true` must be set in `.md.ini` before running (or add `ALLOW_UPDATE = true` manually to the auto-created `.md.ini` and reload).

```bash
# Upload only updater.php, then:
curl -o updater.php https://raw.githubusercontent.com/paulmann/MD.Viewer/main/updater.php
# Edit .md.ini (auto-created on first hit) and set ALLOW_UPDATE = true
# Then open: https://your-domain.com/updater.php?update=true
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
2. Copy `md.php`, `updater.php`, `assets/`, and optionally `README.md` into your target directory.
3. Add your Markdown files in the same directory tree.
4. Open `md.php` in a browser. `.md.ini` is created automatically on first load.

### One-file install via updater

1. Upload only `updater.php` to your server directory.
2. Open `updater.php` once in a browser — it auto-creates `.md.ini` with `ALLOW_UPDATE = false`.
3. Edit `.md.ini` on the server: set `ALLOW_UPDATE = true`.
4. Open `https://your-domain.com/updater.php?update=true`.
5. The updater downloads all files, creates `assets/js/` and `assets/css/` directories as needed, and shows a result page.
6. Open `md.php` — your MD.Viewer is ready.

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
├── md.php                     # Main viewer / browser script
├── updater.php                # Self-updater, backup manager, upload handler
├── .md.ini                    # Server-side config (auto-created on first run)
├── md.md                      # Default Markdown file for md.php
├── uploads.md/                # Created automatically for uploaded/saved files
├── md.backup/                 # Created automatically when updates/restores run
│   ├── 2.8.1/
│   ├── 2.8.1-pre-restore/     # Auto-created before each rollback
│   └── .state/                # ETag + SHA-256 per-file cache
├── assets/
│   ├── css/
│   │   ├── md.css             # v2.0.2
│   │   ├── settings.css       # v2.8.0
│   │   └── tooltips.css       # v2.4.3
│   └── js/
│       ├── md.js              # v2.2.2
│       ├── settings.js        # v2.8.0
│       ├── tooltips.js        # v2.4.3
│       └── upload.js          # v2.8.0
├── LICENSE                    # v1.0.0
└── README.md                  # v2.8.0
```

Key naming rule: `md.php` looks for `md.md`, `docs.php` looks for `docs.md`. Multiple independent viewer instances can share one directory tree without extra routing.

---

## Server-side configuration — `.md.ini`

On first request, `md.php` (or `updater.php`) creates `.md.ini` in the same directory. This file hard-locks feature flags so they **cannot** be changed from the browser or Settings panel.

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
```

| Key | Default | Effect |
|---|---|---|
| `DISABLE_UPLOAD` | `true` | Hides and disables the Upload button |
| `DISABLE_CLIPBOARD` | `false` | Hides and disables the Clipboard Preview button |
| `DISABLE_SAVE_CLIPBOARD_TO_FILE` | `true` | Hides Save to File in clipboard preview |
| `ALLOW_UPDATE` | `false` | Enables update system (Settings panel + `?update=true`) |
| `ALLOW_RESTORE` | `false` | Enables direct rollback via `?restore=latest` or `?restore=X.Y.Z` |

Missing keys are automatically appended to the file with default values on next load.

---

## Usage modes

### Viewer mode

When a matching Markdown file exists, MD.Viewer renders it as a styled document page. It derives the page title from the first top-level heading, builds a description from the first paragraph, renders a table of contents, and applies local viewer preferences.

### File browser mode

When no matching Markdown file exists, MD.Viewer switches to a recursive Markdown browser. The browser scans the current directory tree, lists Markdown files in a searchable table, and opens the selected file with `?file=relative/path.md`.

### Clipboard preview mode

The file browser includes a **Clipboard Preview** button. Paste any Markdown text into the textarea and click Preview — the content is rendered in viewer mode. If `DISABLE_SAVE_CLIPBOARD_TO_FILE = false`, a **Save to File** bar appears with a filename input and Save button that writes to `uploads.md/`.

### URL parameters

| Parameter | Meaning | Example |
|---|---|---|
| `?file=path/to/doc.md` | Render a specific Markdown file | `md.php?file=docs/api.md` |

---

## Features

### Markdown rendering

- Headings, paragraphs, emphasis, strong text, inline code, blockquotes, horizontal rules, fenced code blocks.
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

Inline glossary tooltips via `assets/js/tooltips.js` and `assets/css/tooltips.css`. Terms show a tooltip on hover; the engine resolves variant spellings to the same definition.

### Appearance and reading controls

- Dark mode.
- Adjustable page width, font size, and line height.
- Header toolbar visibility settings (desktop/mobile independently).
- Persistent preferences stored in browser cookies.

### File upload

If `DISABLE_UPLOAD = false` in `.md.ini`, the file browser shows an **Upload .md** button. Uploaded files are saved to `uploads.md/`. Upload protection is three-layered:

1. UI button hidden/disabled when `DISABLE_UPLOAD = true`.
2. JS click handler early-returns if `MDV_CONFIG.disableUpload` is true.
3. PHP `updater.php`: reads `.md.ini` independently, returns `403` if disabled.

### Clipboard preview and Save to File

Paste Markdown text and render it instantly without saving a file. If `DISABLE_SAVE_CLIPBOARD_TO_FILE = false`, a Save to File bar appears with filename input, Save button, and inline status feedback. Source text is stored in `sessionStorage` when preview opens.

### Settings panel

The Settings panel (opened from the header gear icon) includes:

- Viewer preference toggles (theme, width, font, line height, TOC, numbering).
- Header toolbar visibility controls per device type.
- PHP-side feature toggles (applied on next page load via cookies).
- Update check and apply controls (visible only when `ALLOW_UPDATE = true`).
- Backup list and restore controls (visible only when `ALLOW_RESTORE = true`).
- `index.php` hard-link management.

---

## Built-in updater

`updater.php` provides three ways to update and manage files.

### 1. Settings panel (AJAX)

- Check for updates across all tracked files, with per-file version display.
- Apply updates — backs up current files, downloads new versions.
- Force reinstall all files regardless of version (bypasses ETag/SHA-256 cache).
- View and restore backup versions.

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
- A amber **↺ Force reinstall all** button appears in the result page footer on update pages.

**Directory auto-creation:**

The updater creates any missing subdirectories (`assets/js/`, `assets/css/`) with `0755` permissions before writing files. This makes `?update=true` work even from a one-file install where only `updater.php` exists.

**Result page:**

- Per-file status badges: `updated` / `force-updated` / `created` / `current` / `error`
- Version delta: `2.7.0 → 2.8.1`
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
6. Outputs HTML result page with `restored` / `skipped (not in backup)` / `error` badges, version delta, and clickable file links.

---

## Tracked files

All files managed by the updater (checked, downloaded, backed up):

```
md.php                    v2.8.1
updater.php               v3.7.0
assets/js/md.js           v2.2.2
assets/js/settings.js     v2.8.0
assets/js/tooltips.js     v2.4.3
assets/js/upload.js       v2.8.0
assets/css/md.css         v2.0.2
assets/css/settings.css   v2.8.0
assets/css/tooltips.css   v2.4.3
README.md                 v2.8.0
LICENSE                   v1.0.0
```

---

## Backups and restore

Before each file replacement (update or force reinstall), the current local file is copied to `md.backup/[version]/`. The Settings panel lists backup versions, dates, and file counts. Restoring first backs up the current version (as `[version]-pre-restore`), then writes the selected files back. `README.md` and `LICENSE` are downloaded and updated but **not** backed up (they are docs, not code).

---

## Security notes

- **`.md.ini`** hard-locks all feature flags — the browser cannot override them regardless of cookie values.
- **`ALLOW_UPDATE` and `ALLOW_RESTORE` default to `false`** — both systems are completely disabled until explicitly enabled.
- **Path traversal** is prevented in all file-serving, upload, and save operations.
- **Upload** and **Save to File** validate filenames server-side (`.md` extension only, no slashes, no null bytes).
- **CORS guard** in `updater.php` rejects cross-origin requests.
- **All mutating actions** in `updater.php` require `POST` (except direct URL modes which require their respective `ALLOW_*` flags).
- **Version strings** in `?restore=` are validated against `[a-zA-Z0-9.\-]` only — no path injection possible.

---

## License

Released under the MIT License. See [LICENSE](https://github.com/paulmann/MD.Viewer/blob/main/LICENSE).
