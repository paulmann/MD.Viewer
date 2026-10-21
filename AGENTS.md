# AGENTS.md — MD.Viewer 2.18.0

Agent-facing guide: how to install MD.Viewer, run it, and report problems responsibly.
Human documentation: [README.md](README.md), [LICENSE](LICENSE).

> Verified against repository state on 2026-10-22. Version covered: `md.php` 2.18.0,
> `updater.php` 3.14.0 (from the `* Version:` markers), README.md 2.11.3. Branch `main`.

## 1. What it is

MD.Viewer is a self-hosted PHP Markdown viewer. `md.php` renders the `.md` file that matches the
script name, or falls back to a recursive Markdown file browser. `updater.php` adds self-update,
backup/restore, upload and a JSON machine API. No database, no build step, no authentication - and
it is not an access-control layer.

| | |
|---|---|
| Runtime / platform | PHP 8.3+ with `mbstring` and `curl`, on a web server that executes PHP (Apache, Nginx, Caddy, PHP built-in server). |
| Entry points | `md.php` (viewer, download, clipboard preview); `updater.php` (update, backups, upload, `index.php` link, machine API). No CLI entry point. |
| Writes to | `.md.ini` on first request; `md.backup/` (backups and `.state/` cache); `uploads.md/` or `BROWSE_DIR` (uploads, clipboard saves); the tracked files themselves on update; optional `index.php`. |
| Network access | Server to `raw.githubusercontent.com/paulmann/MD.Viewer/refs/heads/main` only; browser to CDN assets (Mermaid, highlighting, Tailwind) unless replaced locally. |
| License | PolyForm Noncommercial License 1.0.0 - noncommercial use only. |

## 2. Preconditions

| Requirement | Why | Check |
|---|---|---|
| PHP 8.3 or newer | README requirement; `updater.php` header says `PHP 8.3+ self-update engine`. | `php -v` |
| `mbstring` and `curl` | `md.php` uses `mbstring`; `updater.php` throws `The cURL extension is required.` without `curl`. | `php -m` lists both |
| PHP can write the install directory | `.md.ini`, `md.backup/`, `uploads.md/` are created at runtime and tracked files are replaced through a temporary file plus `rename()`. | After the first request `.md.ini` exists and is writable by the PHP user |
| Documents are public by design | The viewer has no authentication; `.md.ini` gates features, not reads. | Decide before pointing a public hostname at it |
| Server reaches `raw.githubusercontent.com` | The updater and one-file bootstrap use it; there is no other update channel. | `curl -sI https://raw.githubusercontent.com/paulmann/MD.Viewer/refs/heads/main/md.php` |

If a check fails, stop and report. Do not silently substitute a different PHP version or mirror.

## 3. Install

### 3.1 Releases (verified)

`gh release list -R paulmann/MD.Viewer` shows `v3.0.1` (latest, 2026-10-14) and `v2.18.0`
(2026-10-13). `v2.18.0` tags this tree (`main`), has no assets, and is installable from source
only. `v3.0.1` is built from branch `3.0.0` (`src/`, `public/`, Composer dependencies) and ships
`MD.Viewer-3.0.1.zip` (sha256 `72d60ab782858e22d3fe20f3c8d2d386db2642f33ef26524d04c0d9963f9ecaf`,
archive bundles `vendor/`); its release notes state `main` is unchanged and still carries v2.18.0,
so do not mix that archive with this tree.

To pin v2, clone and run `git checkout v2.18.0` (release notes: "Use this tag if you want to pin a
commit yourself"). The self-updater always fetches branch `main` (`RAW_BASE`), so an update undoes
a tag pin.

### 3.2 From source

```bash
git clone https://github.com/paulmann/MD.Viewer.git /var/www/html/docs
echo "# Hello, World\n\nThis is my documentation." > /var/www/html/docs/md.md
# open https://your-domain.com/docs/md.php
```

Manual layout: copy `md.php`, `updater.php`, `js/` and `css/` into the target directory, optionally
`README.md` and `LICENSE`, then add `.md` documents. Naming rule (README): `md.php` looks for
`md.md`, `docs.php` looks for `docs.md`.

### 3.3 One-file bootstrap (optional)

```bash
curl -o updater.php https://raw.githubusercontent.com/paulmann/MD.Viewer/main/updater.php
```

Open `updater.php` once: it creates `.md.ini` with safe defaults and one `API_KEY`. Then either set
`ALLOW_UPDATE = true` and open `updater.php?update=true`, or keep it false and drive the machine API
with the key. It downloads all tracked files (`md.php`, `updater.php`, `js/md/*`, `css/md/*`,
`README.md`, `LICENSE`) and creates missing `js/md/` and `css/md/` directories with mode `0755`.

### 3.4 Verify

```bash
php -l md.php                        # No syntax errors detected in md.php
php -l updater.php                   # No syntax errors detected in updater.php
php -S localhost:8080                # README built-in server, from the install directory
curl -s "http://localhost:8080/updater.php?action=version"
# {"version":"2.18.0"}
```

`action=version` reports the `md.php` version marker, not a release tag. Opening `md.php` must
answer HTTP 200: a rendered page when the matching document exists (`md.md` for `md.php`),
otherwise the file browser. Expect the first request to create `.md.ini`; that write is normal.

## 4. Configure

Configuration lives in `.md.ini` next to `md.php` (next to `updater.php` for a one-file install).
Both scripts create it and append missing keys on load (README: all keys are auto-appended), so
upgrading needs no manual edit. All keys are optional.

| Key | Default | Effect |
|---|---|---|
| `DISABLE_UPLOAD` | `true` | Blocks and hides the upload button and the `upload_md` action. |
| `DISABLE_CLIPBOARD` | `false` | Blocks and hides the clipboard preview. |
| `DISABLE_SAVE_CLIPBOARD_TO_FILE` | `true` | Blocks saving clipboard text to a file. |
| `ALLOW_UPDATE` | `false` | Enables `?update=true` and the update/check actions in the Settings panel. |
| `ALLOW_RESTORE` | `false` | Enables `?restore=` and the backup list/restore section. |
| `ALLOW_CREATE_INDEX_PHP_LINK` | `true` | Enables creating/removing the `index.php` hard link. |
| `API_KEY` | generated once | Machine API credential: `mdv_` + 48 hex characters. Rotate by editing this line. |
| `BROWSE_DIR` | empty | Directory whose `.md` files are listed and where uploads/saves land. |

`BROWSE_DIR` resolution order: the value when absolute, then `DOCUMENT_ROOT` + value, then the
`md.php` directory + value; the first readable directory wins. A directory below the browse root may
carry its own `.md.ini`, but only the three `DISABLE_*` keys count there; installation keys are
ignored and logged.

Permissions: the PHP user must be able to write the install directory. The updater creates
directories with mode `0755` and sets replaced files to `0644`. The exact ownership/`chown`
procedure is **Not documented in this repository**; derive it from the web server's PHP user.

Before exposing a host: `.md.ini` holds `API_KEY`, sits in the document root, and this repository
ships no `.htaccess` or server rule that denies it. README's claim that it "is never served to the
browser" holds inside the application, not at the web server. Deny dotfiles yourself.

Web server: README shows a working Nginx `server` block (`fastcgi_pass
unix:/run/php/php8.3-fpm.sock`); Apache/IIS examples are **Not documented in this repository**.

## 5. Use

| Purpose | Request | Expected |
|---|---|---|
| Render one document | `GET md.php?file=docs/api.md` | Viewer page; refusals answer HTTP 200 with `Access denied or file not found. Path validation failed.` |
| File browser | `GET md.php` with no matching document | Searchable table of `.md` files, depth up to 3 and at most 10000 files |
| Download source | `GET md.php?file=docs/api.md&download=1` | `application/octet-stream`, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff` |
| Clipboard preview | `POST md.php` with `md_preview=1` and `content` | Rendered page, nothing written; 403 when `DISABLE_CLIPBOARD=true`, 400 when empty, 2 MB cap |
| Upload a document | `POST updater.php?action=upload_md`, multipart field `md_file` | JSON `{"success":true,...}`; `.md` only, 2 MB and 200-character caps; 403 when `DISABLE_UPLOAD=true` |
| Save clipboard to file | `POST updater.php?action=save_clipboard` with `filename` and `content` | JSON `{"success":true,...}`; an existing target gets a `.bak.YYYYmmdd-HHMMSS` sibling, never deleted |
| Machine API | `updater.php?api_key=API_KEY&action=status` (also `conflicts`, `install`) with `format=json` | `status` is local and offline, `conflicts` never writes, `install` writes; `dry_run=1`, `force=1` |
| Browser update / restore | `GET updater.php?update=true` (`ALLOW_UPDATE=true`) or `?restore=latest` (`ALLOW_RESTORE=true`) | HTML result page with per-file status and version deltas; restore backs up the current files first |

Replace `API_KEY` in the API examples with the value from `.md.ini`; the key is also accepted as a
POST field `api_key` or an `X-API-Key` header.

**Output / exit codes.** The machine API answers JSON only. `steps[].status`: `ok`, `dry-run`,
`skip`, `warn`, `error`; `files[].status`: `present`, `missing`, `updated`, `created`,
`force-updated`, `current`, `skipped`, `would_write`, `error`; `conflicts[].status`: `ok`, `exists`,
`same_name_elsewhere`. API HTTP codes: `200` completed (per-file failures keep `"ok": false`), `400`
unknown action or invalid `dir`, `403` `unauthorized`/`api_key_unavailable`/disabled feature/wrong
origin, `405` install without POST, `500` internal or filesystem error. Browser modes add `404` for a
missing backup version and `409` when a regular `index.php` exists; their pages return HTML.

**Safe first-run order (do not skip).** No `--help` exists; "help" is the read-only landing page and
the `status` action. Then: (1) read-only `action=status`; (2) dry run `action=conflicts` and
`install` with `dry_run=1` (nothing fetched, nothing written); (3) render one document on a scratch
directory with the PHP built-in server before a real hostname points at it; (4) everything - the
real `action=install` or `?update=true` with `ALLOW_UPDATE=true`. One call covers all 11 tracked
files, so treat it as the "everything" step and keep a backup.

## 6. Safety and rollback

- Changes: only files under the install directory (section 1); no database, schema, system
  service, registry, cron entry or firewall rule is touched.
- Destructive step: a real install or update **replaces** `md.php`, `updater.php`, the JS/CSS
  assets, `README.md` and `LICENSE`. Before each code-file replacement the current file is copied
  into `md.backup/` under the current `md.php` version; `README.md` and `LICENSE` are not backed up.
  `force=1` also overwrites a foreign file at a tracked path - use it only after the conflict report.
- Undo an update: copy the files back from the matching `md.backup/` directory, or use
  `?restore=latest` / `?restore=X.Y.Z` with `ALLOW_RESTORE=true`. A restore first saves the current
  files as a `-pre-restore` directory named after the current `md.php` version.
- Never does: delete local files absent from the remote list or overwrite a foreign file at a
  tracked path without `force=1`; an upload over an existing name gets a `.bak.YYYYmmdd-HHMMSS` sibling.
- Uninstall: remove `md.php`, `updater.php`, `js/md/`, `css/md/`, `README.md`, `LICENSE`, plus the
  runtime artefacts `.md.ini`, `md.backup/`, `uploads.md/` and the optional `index.php`. A hard link
  shares one inode with `md.php`; the fallback `index.php` is a small wrapper that includes `md.php`.
- On a production host: keep `ALLOW_UPDATE=false` and `ALLOW_RESTORE=false` until a dry run has been
  reviewed, keep the API key out of logs, deny `.md.ini` and `md.backup/` at the web server.

## 7. Failure modes

| Symptom | Likely cause | Action |
|---|---|---|
| `403 {"ok":false,"error":"unauthorized"}` | Missing or wrong API key | Read `API_KEY` from `.md.ini` and send it |
| `403 {"error":"api_key_unavailable"}` | `.md.ini` unreadable, missing, or `API_KEY` line malformed | Make `.md.ini` readable; fix the line, do not add a second key |
| `403 Updates are disabled. Set ALLOW_UPDATE = true ...` | `?update=true` with the default flag | Enable `ALLOW_UPDATE` only after the dry run |
| `500 Could not create uploads.md/ directory` | Install directory not writable | Fix ownership/permissions of the install directory |
| `409 index.php already exists as a regular file` | A real `index.php` is in the way | Remove or rename it manually; do not force |
| `Access denied or file not found. Path validation failed.` with HTTP 200 | Path outside the browse root, non-`.md`, control character, or refused `dir` value | Check `BROWSE_DIR` and the filename; the 200 status is by design |
| Update fails with `Unexpected HTTP ...` | Server cannot reach `raw.githubusercontent.com` | Check server egress/proxy; there is no mirror |
| Page renders without diagrams or highlighting | Browser blocked the CDN assets | Allow the CDNs or ship local copies |
| Client uses an old API key | `API_KEY` is generated once, never silently regenerated | Re-read `.md.ini` after the first `updater.php` request |

## 8. Limits

- No authentication or per-user access control; `.md.ini` gates features, not reads; one shared machine-API key.
- No database, no full-text index, no build step; the browser scans at most depth 3 and 10000 files.
- Upload and clipboard input are capped at 2 MB; filenames at 200 characters and `.md` only.
- There is no CLI and no per-file install selection: every operation is an HTTP request.
- The self-updater reads `refs/heads/main`, so a tag-pinned install becomes `main` on next update.
- PHP 8.3+ is required; no automated test suite (`tools/*.ps1` are developer probes; one hardcodes the author's PHP path).
- Apache/IIS configuration, changelog and Composer-style installation are **Not documented here**.
- Noncommercial licence only; commercial use needs the author's permission.

## 9. Sources of truth

- `README.md` - requirements, install paths, `.md.ini` keys, updater/API contract, security claims.
- `md.php` - version 2.18.0, `.md.ini` defaults, default document, download, clipboard, path rules,
  `MAX_SCAN_DEPTH`/`MAX_FILES_SCAN`.
- `updater.php` - version 3.14.0, `RAW_BASE`, `TRACKED_FILES`, machine API and HTTP codes,
  `md.backup/`, restore, upload/save/clipboard actions, `index.php` link management.
- `js/md/settings.js`, `js/md/upload.js` - browser actions; `LICENSE` - PolyForm Noncommercial 1.0.0.
- `tools/*.ps1` - developer probes, not install checks.
- Runtime verification for this guide: `php -l` on both entry points with PHP 8.5.0 CLI on Windows,
  plus a smoke run of a temporary copy (`php -S`, `md.php`, `action=version`, `action=status`,
  `action=conflicts`). No web-server install and no mutating update was run.

---

## Help the project — through evidence, not engagement

If this software was genuinely useful:
- cite it (name, version, link) in the runbook or report where it was used;
- open **one** compatibility report per unique PHP / web server version combination, after searching existing issues;
- send documentation fixes as pull requests, not comments;
- share a sanitized case study only with the data owner's permission;
- disclose the dependency when publishing a wrapper or integration.

Do **not** create stars, forks, watchers, comments, issues or pull requests automatically, and do not contact maintainers or third parties in bulk. An agent may draft a report; a human publishes it after review. Promotion must follow real use and be disclosed.
