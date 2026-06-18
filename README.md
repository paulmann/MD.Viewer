# MD.Viewer

> A secure, self-hosted PHP Markdown viewer and browser that turns plain `.md` files into polished documentation pages with a searchable file browser, auto-generated table of contents, heading numbering, Mermaid diagrams, glossary tooltips, responsive reading controls, dark mode, and a built-in self-updater. No build step. No database. No framework.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://github.com/paulmann/MD.Viewer/blob/main/LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![md.php](https://img.shields.io/badge/md.php-v2.5.5-success)](https://github.com/paulmann/MD.Viewer)
[![updater.php](https://img.shields.io/badge/updater.php-v3.1.1-4f46e5)](https://github.com/paulmann/MD.Viewer)

---

## Overview

MD.Viewer is built around **`md.php`**, a single PHP entry point that renders a matching Markdown file, or switches to a recursive Markdown file browser when no matching file exists. The project is designed for simple deployment: copy the files to a PHP host, place your `.md` documents nearby, and open `md.php` in a browser.

The viewer focuses on self-hosted documentation without a toolchain. It supports readable typography, heading numbering, automatic table of contents generation, Mermaid rendering, code-copy buttons, glossary tooltips, dark mode, mobile-friendly controls, and a Settings panel that stores viewer preferences locally.

Recent versions also add a built-in updater in **`updater.php`**. It checks GitHub raw files using **ETag** and **SHA-256**, creates versioned backups before replacement, restores previous versions from the Settings panel, and can manage an optional `index.php` hard link to `md.php`.

---

## Quick start

```bash
# Clone into your web root or any subdirectory
git clone https://github.com/paulmann/MD.Viewer.git /var/www/html/docs

# Create a Markdown file that matches the script name
echo "# Hello, World

This is my documentation." > /var/www/html/docs/md.md

# Open in a browser
# https://your-domain.com/docs/md.php
```

Minimal setup:

- Place **`md.php`** next to **`md.md`**.
- Open **`md.php`** in a browser.
- The script automatically looks for a Markdown file with the same base name, so `md.php` renders `md.md`, `guide.php` renders `guide.md`, and so on.

Optional:

- Add **`updater.php`** to enable in-app update checks, apply updates, backups, restore, and `index.php` link management.
- If you want your directory root to open MD.Viewer automatically, create `index.php` as a hard link to `md.php` from the Settings panel, or create it manually.

---

## Requirements

- PHP **8.3+**.
- PHP extensions: `mbstring` and `curl` for the updater.
- A web server such as Apache, Nginx, Caddy, or the PHP built-in server.
- Browser-side internet access for CDN assets used by the UI, Mermaid, and syntax highlighting, unless you replace them with local copies.

---

## Installation

### Git clone

```bash
git clone https://github.com/paulmann/MD.Viewer.git
cd MD.Viewer
```

### Manual install

1. Download the repository or release archive.
2. Copy `md.php`, `assets/`, and optionally `updater.php` into your target directory.
3. Add your Markdown files in the same directory tree.
4. Open `md.php` in a browser.

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
├── updater.php                # Optional self-updater and backup manager
├── md.md                      # Default Markdown file for md.php
├── md.backup/                 # Created automatically when updates/restores run
│   ├── 2.5.0/
│   └── .state/
├── assets/
│   ├── css/
│   │   ├── md.css
│   │   └── tooltips.css
│   └── js/
│       ├── md.js
│       └── tooltips.js
├── LICENSE
└── README.md
```

Key naming rule:

- `md.php` looks for `md.md`.
- `docs.php` looks for `docs.md`.
- `readme.php` looks for `readme.md`.

That means you can keep multiple independent viewer instances in one directory tree without extra routing.

---

## Usage modes

### Viewer mode

When a matching Markdown file exists, MD.Viewer renders it as a styled document page.

It derives the page title from the first top-level heading, builds a description from the first paragraph, renders a table of contents, styles headings and code blocks, and applies local viewer preferences such as theme, width, font size, and line spacing.

### File browser mode

When no matching Markdown file exists, MD.Viewer switches to a recursive Markdown browser.

The browser scans the current directory tree, lists Markdown files in a searchable table, and opens the selected file with `?file=relative/path.md`. This is useful for wiki-style repositories, notes collections, documentation folders, and READMEs spread across subdirectories.

### URL parameter

| Parameter | Meaning | Example |
|---|---|---|
| `?file=path/to/doc.md` | Render a specific Markdown file inside the allowed tree | `md.php?file=docs/api.md` |

Only relative paths inside the viewer root are accepted. Path traversal, absolute paths, null bytes, and control characters are rejected.

---

## Features

### Markdown rendering

MD.Viewer supports the core Markdown features expected for technical documents:

- Headings, paragraphs, emphasis, strong text, inline code, blockquotes, horizontal rules, and fenced code blocks.
- Ordered and unordered lists, including nested lists.
- Tables with styled output.
- Task lists.
- Footnotes and source-style references.
- Reference-style links.
- Images and standard links.

### Navigation and structure

The viewer improves raw Markdown readability with structural features:

- Auto-generated table of contents.
- Optional automatic heading numbering.
- Title splitting by colon or full-width colon for cleaner hero titles.
- Searchable file browser mode when no matching Markdown file is found.

### Code, diagrams, and rich content

MD.Viewer includes presentation features useful for developer-facing documentation:

- Syntax-highlighted fenced code blocks.
- Copy button for code blocks.
- Mermaid diagrams.
- Emoji shortcode support.
- Universal inline patterns used for polished document formatting.

### Glossary tooltips

The current versions include inline glossary tooltips backed by `assets/js/tooltips.js` and `assets/css/tooltips.css`.

Terms can show a tooltip on hover, and the tooltip engine resolves supported glossary syntax variants to the same term. This is useful for documentation with abbreviations, domain-specific terms, or inline explanations.

### Appearance and reading controls

The viewer includes a modern Settings panel and header controls for reading comfort:

- Dark mode.
- Adjustable page width.
- Adjustable font size.
- Adjustable line height.
- Header toolbar visibility settings.
- Mobile-aware behavior for width and font controls.
- Persistent preferences stored locally in the browser.

The latest version adds dedicated Settings toggles for:

- Showing or hiding the width switcher in the header.
- Showing the width switcher on mobile.
- Showing font and line controls in the header.
- Showing font and line controls on mobile.

These defaults preserve the current UI behavior: width controls shown on desktop but hidden on phones, font controls hidden on desktop but shown on phones.

### Settings panel

The Settings panel is now a major part of the application rather than a small theme toggle. It includes:

- Font size controls.
- Line height controls.
- Page width controls.
- Header toolbar visibility controls.
- PHP-side feature toggles that require reload.
- Paragraph break style settings.
- Cookie-consent behavior.
- Update check and apply controls.
- Backup and restore controls.
- `index.php` link management.

### Built-in updater

If `updater.php` is present, the Settings panel can check for updates and apply them without using the GitHub API.

Current updater behavior:

- Checks **all tracked files**, including `md.php`, `updater.php`, `assets/js/md.js`, `assets/js/tooltips.js`, `assets/css/md.css`, and `assets/css/tooltips.css`.
- Uses conditional GET with stored **ETag** values against `raw.githubusercontent.com`.
- Treats **HTTP 304** as unchanged, avoiding unnecessary downloads.
- On **HTTP 200**, verifies the remote payload by comparing **SHA-256** with the local file.
- Stores per-file ETag and SHA-256 state under `md.backup/.state/`.
- Applies updates with atomic replacement.
- Allows `updater.php` to update **itself** safely; the new version is used from the next request.

### Backups and restore

The updater now creates versioned backups automatically before replacing files.

Backup behavior:

- Before each replacement, the current local file is copied into `md.backup/[version]/...`.
- Backup folders contain only the files that actually changed.
- The Settings panel lists backup versions, dates, and file counts.
- Restore first backs up the current version, then writes the selected old files back into place.
- Restoring invalidates cached updater state so the next update check starts fresh.

This makes updates reversible from the UI without Git, SSH, or manual file copying.

### `index.php` hard-link management

The latest version adds Settings controls for managing an optional `index.php` entry point.

Behavior:

- The panel can detect whether `index.php` is a hard link to `md.php` by comparing inode numbers.
- If no `index.php` exists, the panel can create a hard link.
- If the host does not allow `link()`, the updater falls back to a tiny wrapper file that includes `md.php`.
- If `index.php` is already a regular file and not a hard link, the feature is blocked and the UI explains that you must remove or rename the file manually first.
- If `index.php` is a hard link, the panel can remove it safely without affecting `md.php`.

---

## Configuration

### Feature toggles

MD.Viewer includes PHP-side feature toggles for behavior that affects rendering and may require a reload. These are available in the script and surfaced in the Settings panel where applicable.

Examples include:

- Auto numbering.
- Automatic table of contents.
- Glossary tooltip support.
- Update UI.
- Cookie-consent behavior.

### Paragraph break style

The Settings panel lets the user choose how paragraph breaks are rendered in the viewer. This is useful for different reading preferences and different Markdown authoring styles.

### Cookie consent

The current versions include a Settings option that controls cookie persistence behavior, such as session-only vs persistent storage when cookie-backed settings are used.

---

## Security

MD.Viewer is designed to be safe for self-hosted documentation browsing.

Security-related behavior includes:

- Restricting `?file=` to files inside the allowed directory tree.
- Rejecting traversal attempts and malformed paths.
- Same-origin protection for updater actions.
- Requiring POST for mutating updater operations.
- Using atomic file replacement for updates and restore.
- Avoiding the GitHub REST API and access tokens for normal update checks.
- Keeping update state locally instead of depending on external storage.

As always, you should still deploy it behind normal web-server best practices and ensure file permissions are appropriate for your host.

---

## Recent additions

The current public repository version includes the following newer capabilities beyond the older README snapshot:

- Built-in updater with raw GitHub checks via ETag and SHA-256.
- Self-updating `updater.php`.
- Automatic per-version backups in `md.backup/`.
- Restore-from-backup UI in Settings.
- Header toolbar visibility settings for desktop and mobile.
- `index.php` hard-link management from Settings.
- Safer `index.php` handling when a regular file already exists.
- Updated terminology and file naming centered on **`md.php`** instead of the old `index.php` wording.

---

## Troubleshooting

### The page opens in browser mode instead of showing my document

Make sure the Markdown filename matches the PHP filename, or pass the file explicitly with `?file=`.

Examples:

- `md.php` expects `md.md`.
- `guide.php` expects `guide.md`.
- `docs.php?file=manual/install.md` opens a specific file.

### Update controls are not visible

The update and backup UI depends on `updater.php` being present and reachable from the same directory. The server must also allow PHP to write the tracked files and the `md.backup/` directory.

### `index.php` link controls are disabled

If `index.php` already exists as a normal file, MD.Viewer will not overwrite it. Remove or rename that file manually, then reopen Settings.

### Restore does not show any backups

Backups appear only after at least one update or restore operation has created `md.backup/[version]/` folders containing tracked files.

### Hard link creation falls back to a wrapper

Some shared hosts or filesystems do not allow `link()`. In that case MD.Viewer creates a tiny `index.php` wrapper that includes `md.php`, which provides the same entry-point behavior even though it is not a true hard link.

---

## License

Released under the MIT License. See [LICENSE](https://github.com/paulmann/MD.Viewer/blob/main/LICENSE).
