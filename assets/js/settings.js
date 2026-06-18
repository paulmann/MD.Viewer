/**
 * MD.Viewer — Settings Panel Engine
 * Auto-extracted from md.php inline <script> block.
 * Requires window.MDV_CONFIG to be set before this script loads.
 */
'use strict';

/**
     * MD Viewer — Settings Panel Engine (v2.5.1)
     * Author: Mikhail Deynekin | https://Deynekin.com
     *
     * Controls: font-size, line-height, page width, feature toggles (PHP cookie),
     *           paragraph break style, cookie consent.
     * Storage: sessionStorage always; cookies when consent given.
     * PHP features: written to cookie mdv_feat_{NAME}=1/0, page reloads to apply.
     */
    (function () {
        'use strict';

        const CFG    = window.MDV_CONFIG || {};
        const PREFIX = 'mdv_';
        const FEAT   = 'mdv_feat_';

        // ── Storage ───────────────────────────────────────────────────────────
        function cookiesAllowed() {
            return sessionStorage.getItem(PREFIX + 'cookieAccept') === '1';
        }

        function store(key, val) {
            sessionStorage.setItem(key, String(val));
            if (cookiesAllowed()) {
                const exp = new Date(Date.now() + 365 * 864e5).toUTCString();
                document.cookie = key + '=' + encodeURIComponent(val)
                    + '; path=/; expires=' + exp + '; SameSite=Lax';
            }
        }

        function load(key, fallback) {
            const sv = sessionStorage.getItem(key);
            if (sv !== null) return sv;
            const rx = new RegExp('(?:^|;)\\s*' + key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)');
            const m  = document.cookie.match(rx);
            return m ? decodeURIComponent(m[1]) : fallback;
        }

        function clearCookie(key) {
            document.cookie = key + '=; path=/; expires=Thu,01 Jan 1970 00:00:00 GMT; SameSite=Lax';
        }

        // Init cookie consent
        (function () {
            const stored = load(PREFIX + 'cookieAccept', null);
            if (stored !== null) {
                sessionStorage.setItem(PREFIX + 'cookieAccept', stored);
            } else if (CFG.cookieAccept) {
                sessionStorage.setItem(PREFIX + 'cookieAccept', '1');
            }
        })();

        // ── Mobile ────────────────────────────────────────────────────────────
        const isMobile = () => window.innerWidth < 768;

        // ── Font size ─────────────────────────────────────────────────────────
        const FS_MIN = 12, FS_MAX = 24, FS_DEF = 16, FS_STEP = 1;
        let currentFS = Math.min(FS_MAX, Math.max(FS_MIN,
            parseInt(load(PREFIX + 'fontSize', String(FS_DEF)), 10) || FS_DEF));

        function applyFS(size) {
            currentFS = Math.min(FS_MAX, Math.max(FS_MIN, size));
            document.documentElement.style.fontSize = currentFS + 'px';
            store(PREFIX + 'fontSize', currentFS);
            updateFSLabel();
        }
        function updateFSLabel() {
            [document.getElementById('fs-label'),
             document.getElementById('sp-fs-label')]
                .forEach(el => { if (el) el.textContent = currentFS + 'px'; });
        }
        applyFS(currentFS);

        // ── Line height ───────────────────────────────────────────────────────
        const LH_MIN = 1.2, LH_MAX = 2.4, LH_DEF = 1.65, LH_STEP = 0.05;
        let currentLH = Math.min(LH_MAX, Math.max(LH_MIN,
            parseFloat(load(PREFIX + 'lineHeight', String(LH_DEF))) || LH_DEF));

        function applyLH(val) {
            currentLH = Math.min(LH_MAX, Math.max(LH_MIN,
                Math.round(val * 100) / 100));  // 2-decimal precision
            document.documentElement.style.lineHeight = currentLH;
            store(PREFIX + 'lineHeight', currentLH);
            updateLHLabel();
        }
        function updateLHLabel() {
            const el = document.getElementById('sp-lh-label');
            if (el) el.textContent = currentLH.toFixed(2);
        }
        applyLH(currentLH);

        // ── Width ─────────────────────────────────────────────────────────────
        const WIDTHS    = ['reading', 'article', 'wide'];
        const DEF_WIDTH = 'article';
        let   currentWidth = DEF_WIDTH;

        function applyWidth(w) {
            if (!WIDTHS.includes(w)) w = DEF_WIDTH;
            currentWidth = w;
            store(PREFIX + 'width', w);
            const maxW = { reading: '72ch', article: '100ch', wide: '160ch' }[w];
            document.querySelectorAll('[data-width-target], .width-target').forEach(el => {
                el.style.maxWidth = maxW;
            });
            // Sync header width-switch buttons
            document.querySelectorAll('.width-switch').forEach(btn => {
                const on = btn.dataset.width === w;
                btn.classList.toggle('bg-slate-950', on);
                btn.classList.toggle('text-white', on);
                btn.classList.toggle('dark:bg-slate-200', on);
                btn.classList.toggle('dark:text-slate-900', on);
            });
            // Sync panel buttons
            document.querySelectorAll('[data-sp-width]').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.spWidth === w);
            });
        }

        function initWidth() {
            if (isMobile()) {
                applyWidth('wide');
                const sec = document.getElementById('sp-width-section');
                if (sec) sec.style.display = 'none';
            } else {
                applyWidth(load(PREFIX + 'width', DEF_WIDTH));
            }
        }

        // ── Feature toggles (PHP-side, require reload) ────────────────────────
        const FEATURES = [
            { key: 'AUTO_NUMBERING',       label: 'Auto Numbering'     },
            { key: 'AUTO_TOC',             label: 'Auto TOC'           },
            { key: 'AUTO_FOOTNOTES_LINKS', label: 'Footnote Links'     },
            { key: 'DOUBLE_LINE_BREAKS',   label: 'Double Line Breaks' },
            { key: 'CYPHER_PATTERNS',      label: 'Cypher Patterns'    },
            { key: 'UNIVERSAL_PATTERNS',   label: 'Universal Patterns' },
            { key: 'FEATURE_IMAGES',       label: 'Images'             },
            { key: 'FEATURE_REF_LINKS',    label: 'Ref Links'          },
            { key: 'FEATURE_TASK_LISTS',   label: 'Task Lists'         },
            { key: 'FEATURE_FOOTNOTES',    label: 'Footnotes'          },
            { key: 'FEATURE_SUBSUP',       label: 'Sub / Sup'          },
            { key: 'FEATURE_EMOJI',        label: 'Emoji'              },
            { key: 'SPLIT_TITLE_BY_COLON', label: 'Split Title'        },
            { key: 'GLOSSARY_TOOLTIPS',    label: 'Glossary Tooltips'  },
        ];

        // Map MDV_CONFIG keys to FEATURES keys
        const CFG_MAP = {
            AUTO_NUMBERING:       CFG.autoNumbering,
            AUTO_TOC:             CFG.autoToc,
            AUTO_FOOTNOTES_LINKS: CFG.autoFootnotes,
            DOUBLE_LINE_BREAKS:   CFG.doubleLineBreaks,
            CYPHER_PATTERNS:      CFG.cypherPatterns,
            UNIVERSAL_PATTERNS:   CFG.universalPatterns,
            FEATURE_IMAGES:       CFG.featureImages,
            FEATURE_REF_LINKS:    CFG.featureRefLinks,
            FEATURE_TASK_LISTS:   CFG.featureTaskLists,
            FEATURE_FOOTNOTES:    CFG.featureFootnotes,
            FEATURE_SUBSUP:       CFG.featureSubSup,
            FEATURE_EMOJI:        CFG.featureEmoji,
            SPLIT_TITLE_BY_COLON: CFG.splitTitleByColon,
            GLOSSARY_TOOLTIPS:    CFG.glossaryTooltips,
        };

        let pendingReload = false;

        function markReload() {
            pendingReload = true;
            const badge = document.getElementById('sp-reload-badge');
            if (badge) badge.style.display = '';
            const applySection = document.getElementById('sp-apply-section');
            if (applySection) applySection.style.display = '';
        }

        function doReload() {
            location.reload();
        }

        const featContainer = document.getElementById('sp-features');
        if (featContainer) {
            featContainer.innerHTML = FEATURES.map(function (f) {
                // Cookie override wins over PHP default
                const storedVal = load(FEAT + f.key, null);
                const checked   = storedVal !== null
                    ? storedVal === '1'
                    : (CFG_MAP[f.key] !== false && CFG_MAP[f.key] !== undefined);
                return '<label class="settings-toggle-row" title="Requires page reload">'
                     +   '<span class="settings-toggle-name">' + f.label + '</span>'
                     +   '<span class="settings-toggle-switch">'
                     +     '<input type="checkbox" data-feat="' + f.key + '"'
                     +       (checked ? ' checked' : '') + '>'
                     +     '<span class="settings-toggle-track"></span>'
                     +   '</span>'
                     + '</label>';
            }).join('');

            featContainer.addEventListener('change', function (e) {
                const cb = e.target.closest('[data-feat]');
                if (!cb) return;
                store(FEAT + cb.dataset.feat, cb.checked ? '1' : '0');
                markReload();
            });
        }

        // ── Paragraph break style ─────────────────────────────────────────────
        let currentPara = load(FEAT + 'PARAGRAPH_BREAK_STYLE', CFG.paragraphBreak || 'double-br');

        function applyParaButtons(val) {
            document.querySelectorAll('[data-sp-para]').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.spPara === val);
            });
        }
        applyParaButtons(currentPara);

        document.querySelectorAll('[data-sp-para]').forEach(btn => {
            btn.addEventListener('click', function () {
                currentPara = this.dataset.spPara;
                store(FEAT + 'PARAGRAPH_BREAK_STYLE', currentPara);
                applyParaButtons(currentPara);
                markReload();
            });
        });

        // Apply and Reload button
        document.getElementById('sp-apply-reload')?.addEventListener('click', function () {
            doReload();
        });

        // ── Panel open / close ────────────────────────────────────────────────
        const panel    = document.getElementById('settings-panel');
        const overlay  = document.getElementById('settings-overlay');
        const btn      = document.getElementById('settings-btn');
        const closeBtn = document.getElementById('settings-close');

        function openPanel()  {
            panel.classList.add('open');
            overlay.classList.add('open');
            if (btn) btn.setAttribute('aria-expanded', 'true');
        }
        function closePanel() {
            if (pendingReload) {
                // Ask user whether to apply or discard — no auto-reload on X/overlay close
                const apply = confirm(
                    'Feature settings were changed.\n\n' +
                    'OK — Apply & Reload\n' +
                    'Cancel — Discard changes'
                );
                if (apply) { location.reload(); return; }
                // Discard: clear pending feat cookies from sessionStorage
                FEATURES.forEach(function (f) {
                    sessionStorage.removeItem(FEAT + f.key);
                    clearCookie(FEAT + f.key);
                });
                sessionStorage.removeItem(FEAT + 'PARAGRAPH_BREAK_STYLE');
                clearCookie(FEAT + 'PARAGRAPH_BREAK_STYLE');
                pendingReload = false;
                const badge = document.getElementById('sp-reload-badge');
                if (badge) badge.style.display = 'none';
                const applySection = document.getElementById('sp-apply-section');
                if (applySection) applySection.style.display = 'none';
            }
            panel.classList.remove('open');
            overlay.classList.remove('open');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        }

        if (btn)      btn.addEventListener('click', function () {
            panel.classList.contains('open') ? closePanel() : openPanel();
        });
        if (closeBtn) closeBtn.addEventListener('click', closePanel);
        if (overlay)  overlay.addEventListener('click', closePanel);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && panel.classList.contains('open')) closePanel();
        });

        // ── Cookie consent ────────────────────────────────────────────────────
        const cookieCb = document.getElementById('sp-cookie-accept');
        if (cookieCb) {
            cookieCb.checked = cookiesAllowed();
            cookieCb.addEventListener('change', function () {
                if (this.checked) {
                    sessionStorage.setItem(PREFIX + 'cookieAccept', '1');
                    // Persist all current settings to cookies immediately
                    store(PREFIX + 'fontSize',   currentFS);
                    store(PREFIX + 'lineHeight',  currentLH);
                    store(PREFIX + 'width',       currentWidth);
                    store(PREFIX + 'cookieAccept', '1');
                    FEATURES.forEach(function (f) {
                        const v = sessionStorage.getItem(FEAT + f.key);
                        if (v !== null) store(FEAT + f.key, v);
                    });
                    store(FEAT + 'PARAGRAPH_BREAK_STYLE', currentPara);
                } else {
                    sessionStorage.setItem(PREFIX + 'cookieAccept', '0');
                    // Wipe all mdv_ cookies
                    [PREFIX + 'fontSize', PREFIX + 'lineHeight', PREFIX + 'width',
                     PREFIX + 'cookieAccept', FEAT + 'PARAGRAPH_BREAK_STYLE',
                     ...FEATURES.map(f => FEAT + f.key)
                    ].forEach(clearCookie);
                }
            });
        }

        // ── Wire up buttons ───────────────────────────────────────────────────
        // Header font-size (mobile)
        const hDec = document.getElementById('fs-decrease');
        const hInc = document.getElementById('fs-increase');
        if (hDec) hDec.addEventListener('click', () => applyFS(currentFS - FS_STEP));
        if (hInc) hInc.addEventListener('click', () => applyFS(currentFS + FS_STEP));

        // Panel font-size
        document.getElementById('sp-fs-decrease')?.addEventListener('click', () => applyFS(currentFS - FS_STEP));
        document.getElementById('sp-fs-increase')?.addEventListener('click', () => applyFS(currentFS + FS_STEP));
        document.getElementById('sp-fs-reset')?.addEventListener('click',    () => applyFS(FS_DEF));

        // Panel line-height
        document.getElementById('sp-lh-decrease')?.addEventListener('click', () => applyLH(currentLH - LH_STEP));
        document.getElementById('sp-lh-increase')?.addEventListener('click', () => applyLH(currentLH + LH_STEP));
        document.getElementById('sp-lh-reset')?.addEventListener('click',    () => applyLH(LH_DEF));

        // Width buttons
        document.querySelectorAll('[data-sp-width]').forEach(b =>
            b.addEventListener('click', function () { applyWidth(this.dataset.spWidth); }));
        document.querySelectorAll('.width-switch').forEach(b =>
            b.addEventListener('click', function () { applyWidth(this.dataset.width); }));

        // ── Init ──────────────────────────────────────────────────────────────
        initWidth();
        updateFSLabel();
        updateLHLabel();



        // ── index.php hard link management ───────────────────────────────────
        const elIndexStatus = document.getElementById('sp-index-status');
        const elIndexCreate = document.getElementById('sp-index-create');
        const elIndexRemove = document.getElementById('sp-index-remove');

        function indexSetStatus(msg, cls) {
            if (!elIndexStatus) return;
            elIndexStatus.className = 'sp-index-status' + (cls ? ' ' + cls : '');
            elIndexStatus.innerHTML = msg;
        }

        function loadIndexStatus() {
            indexSetStatus('Checking…');
            fetch(UPDATER_URL + '?action=index_status')
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.error) { indexSetStatus('⚠ ' + d.error, 'err'); return; }
                    if (d.linked) {
                        // index.php is a hard link to md.php
                        indexSetStatus('✓ <strong>index.php</strong> → <strong>md.php</strong>'
                            + (d.inode ? '&ensp;<small>inode ' + d.inode + '</small>' : ''), 'ok');
                        if (elIndexCreate) { elIndexCreate.style.display = 'none'; elIndexCreate.disabled = false; }
                        if (elIndexRemove) { elIndexRemove.style.display = ''; elIndexRemove.disabled = false; }
                    } else if (d.exists) {
                        // index.php exists as a regular file — block all actions
                        indexSetStatus(
                            '⚠ <strong>index.php</strong> already exists as a regular file.<br>'
                          + '<small>Remove or rename it manually before using this feature.</small>',
                            'warn'
                        );
                        if (elIndexCreate) { elIndexCreate.style.display = ''; elIndexCreate.disabled = true; }
                        if (elIndexRemove) { elIndexRemove.style.display = 'none'; elIndexRemove.disabled = true; }
                    } else {
                        // index.php does not exist — ready to create
                        indexSetStatus('No <strong>index.php</strong> in this directory.', '');
                        if (elIndexCreate) { elIndexCreate.style.display = ''; elIndexCreate.disabled = false; }
                        if (elIndexRemove) { elIndexRemove.style.display = 'none'; elIndexRemove.disabled = true; }
                    }
                })
                .catch(function (e) { indexSetStatus('⚠ ' + e.message, 'err'); });
        }

        function indexAction(action, btn) {
            if (btn) btn.disabled = true;
            fetch(UPDATER_URL + '?action=' + action, { method: 'POST' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.error) { indexSetStatus('⚠ ' + d.error, 'err'); }
                    else { loadIndexStatus(); }
                })
                .catch(function (e) { indexSetStatus('⚠ ' + e.message, 'err'); })
                .finally(function () { if (btn) btn.disabled = false; });
        }

        if (elIndexCreate) {
            elIndexCreate.addEventListener('click', function () {
                indexAction('index_create', this);
            });
        }
        if (elIndexRemove) {
            elIndexRemove.addEventListener('click', function () {
                if (!confirm('Remove index.php?\n\nIf it is a hard link to md.php, only the link will be removed. '
                           + 'The md.php file itself is not affected.\n\nContinue?')) return;
                indexAction('index_remove', this);
            });
        }

        // ── Backup & Restore ──────────────────────────────────────────────────
        const elBackupSection    = document.getElementById('sp-backup-section');
        const elBackupSelect     = document.getElementById('sp-backup-select');
        const elRestoreBtn       = document.getElementById('sp-restore-btn');
        const elRestoreStatus    = document.getElementById('sp-restore-status');
        const elReloadAfterRestore = document.getElementById('sp-reload-after-restore');
        const elBackupMeta       = document.getElementById('sp-backup-meta');

        function loadBackups() {
            fetch(UPDATER_URL + '?action=backups')
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    const backups = d.backups || [];
                    if (!backups.length) {
                        if (elBackupSection) elBackupSection.style.display = 'none';
                        return;
                    }
                    // Populate select
                    while (elBackupSelect.options.length > 1) elBackupSelect.remove(1);
                    backups.forEach(function (b) {
                        const opt = document.createElement('option');
                        opt.value       = b.version;
                        opt.textContent = 'v' + b.version + '  (' + b.date + ', '
                                        + b.files.length + ' file' + (b.files.length !== 1 ? 's' : '') + ')';
                        elBackupSelect.appendChild(opt);
                    });
                    if (elBackupMeta) {
                        elBackupMeta.textContent = backups.length + ' backup'
                            + (backups.length !== 1 ? 's' : '') + ' available';
                    }
                    if (elBackupSection) elBackupSection.style.display = '';
                })
                .catch(function () { /* silent — updater.php may not exist yet */ });
        }

        if (elBackupSelect) {
            elBackupSelect.addEventListener('change', function () {
                if (elRestoreBtn) elRestoreBtn.disabled = this.value === '';
            });
        }

        if (elRestoreBtn) {
            elRestoreBtn.addEventListener('click', function () {
                const ver = elBackupSelect ? elBackupSelect.value : '';
                if (!ver) return;
                if (!confirm(
                    'Restore from backup v' + ver + '?\n\n'
                  + 'Current files will be backed up first, then replaced with the v'
                  + ver + ' backup.\n\nContinue?'
                )) return;

                elRestoreBtn.disabled    = true;
                elRestoreBtn.textContent = '⟳ Restoring…';
                elRestoreStatus.style.display = 'none';
                elReloadAfterRestore.style.display = 'none';

                const body = new URLSearchParams({ version: ver });
                fetch(UPDATER_URL + '?action=restore', { method: 'POST', body: body })
                    .then(function (r) {
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.json();
                    })
                    .then(function (d) {
                        elRestoreStatus.style.display = '';
                        if (d.error) {
                            elRestoreStatus.className = 'sp-update-status error';
                            elRestoreStatus.textContent = '⚠ ' + d.error;
                            return;
                        }
                        if (d.success) {
                            const lines = [];
                            if (d.backedUpCurrent)
                                lines.push('Current v' + d.backedUpCurrent + ' backed up.');
                            if (d.restored && d.restored.length) {
                                d.restored.forEach(function (f) {
                                    lines.push('✓ ' + f.path
                                        + (f.toVersion ? '  → v' + f.toVersion : ''));
                                });
                            }
                            if (d.skipped && d.skipped.length)
                                lines.push('· Not in backup: ' + d.skipped.join(', '));
                            if (d.newVersion)
                                lines.push('<strong>Restored to v' + d.newVersion + '</strong>');
                            elRestoreStatus.className = 'sp-update-status ok';
                            elRestoreStatus.innerHTML = lines.join('<br>');
                            elReloadAfterRestore.style.display = '';
                            // Refresh backup list
                            loadBackups();
                        } else {
                            const msg = d.failed && d.failed.length
                                ? d.failed.map(function (f) { return f.path + ': ' + f.reason; }).join('<br>')
                                : 'Restore failed';
                            elRestoreStatus.className = 'sp-update-status error';
                            elRestoreStatus.innerHTML = '⚠ ' + msg;
                        }
                    })
                    .catch(function (err) {
                        elRestoreStatus.style.display = '';
                        elRestoreStatus.className = 'sp-update-status error';
                        elRestoreStatus.textContent = '⚠ ' + err.message;
                    })
                    .finally(function () {
                        elRestoreBtn.disabled    = false;
                        elRestoreBtn.textContent = '↩ Restore';
                    });
            });
        }

        if (elReloadAfterRestore) {
            elReloadAfterRestore.addEventListener('click', function () { location.reload(); });
        }



        // ── Header Toolbar visibility prefs ───────────────────────────────────
        const TB_KEYS = {
            showWidth:       PREFIX + 'show-width-ctrl',
            showWidthMobile: PREFIX + 'show-width-mobile',
            showFont:        PREFIX + 'show-font-ctrl',
            showFontMobile:  PREFIX + 'show-font-mobile',
        };
        // Defaults: width shown on desktop, hidden on mobile;
        //           font hidden on desktop, shown on mobile
        const TB_DEF = {
            showWidth:       '1',
            showWidthMobile: '0',
            showFont:        '0',
            showFontMobile:  '1',
        };

        const elWidthCtrl  = document.getElementById('width-switcher');
        const elFontCtrl   = document.getElementById('fontsize-controls');

        function applyToolbarPrefs() {
            const mobile        = isMobile();
            const showWidth     = load(TB_KEYS.showWidth,       TB_DEF.showWidth)       === '1';
            const showWidthMob  = load(TB_KEYS.showWidthMobile, TB_DEF.showWidthMobile) === '1';
            const showFont      = load(TB_KEYS.showFont,        TB_DEF.showFont)        === '1';
            const showFontMob   = load(TB_KEYS.showFontMobile,  TB_DEF.showFontMobile)  === '1';

            if (elWidthCtrl) {
                const show = mobile ? showWidthMob : showWidth;
                elWidthCtrl.style.display = show ? 'inline-flex' : 'none';
            }
            if (elFontCtrl) {
                const show = mobile ? showFontMob : showFont;
                elFontCtrl.style.display = show ? 'inline-flex' : 'none';
            }
        }

        function syncToolbarCheckboxes() {
            const ck = function (id, key, def) {
                const el = document.getElementById(id);
                if (el) el.checked = load(key, def) === '1';
            };
            ck('sp-show-width-ctrl',   TB_KEYS.showWidth,       TB_DEF.showWidth);
            ck('sp-show-width-mobile', TB_KEYS.showWidthMobile, TB_DEF.showWidthMobile);
            ck('sp-show-font-ctrl',    TB_KEYS.showFont,        TB_DEF.showFont);
            ck('sp-show-font-mobile',  TB_KEYS.showFontMobile,  TB_DEF.showFontMobile);
        }

        // Wire checkboxes
        [
            ['sp-show-width-ctrl',   TB_KEYS.showWidth],
            ['sp-show-width-mobile', TB_KEYS.showWidthMobile],
            ['sp-show-font-ctrl',    TB_KEYS.showFont],
            ['sp-show-font-mobile',  TB_KEYS.showFontMobile],
        ].forEach(function ([id, key]) {
            const el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('change', function () {
                store(key, this.checked ? '1' : '0');
                applyToolbarPrefs();
            });
        });

        // Apply on init
        applyToolbarPrefs();
        syncToolbarCheckboxes();

        // Resize debounce for mobile/desktop switch
        let resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                const sec = document.getElementById('sp-width-section');
                if (isMobile()) {
                    applyWidth('wide');
                    if (sec) sec.style.display = 'none';
                } else {
                    if (sec) sec.style.display = '';
                    applyWidth(load(PREFIX + 'width', DEF_WIDTH));
                }
                applyToolbarPrefs();
            }, 200);
        }, { passive: true });

        // ── Updates ───────────────────────────────────────────────────────────
        const UPDATER_URL = (window.MDV_CONFIG || {}).updaterUrl || '/updater.php';

        const elCheckBtn    = document.getElementById('sp-check-updates');
        const elApplyBtn    = document.getElementById('sp-apply-updates');
        const elReinstall   = document.getElementById('sp-reinstall-btn');
        const elReloadAfter = document.getElementById('sp-reload-after-update');
        const elStatus      = document.getElementById('sp-update-status');
        const elFileList    = document.getElementById('sp-file-list');
        const elVerBadge    = document.getElementById('sp-version-badge');

        function setStatus(msg, type /* ok|warn|error|info */) {
            elStatus.className = 'sp-update-status ' + type;
            elStatus.innerHTML = msg;
            elStatus.style.display = '';
        }

        function renderFileList(files) {
            if (!files || !files.length) { elFileList.style.display = 'none'; return; }
            const labels = {
                'up-to-date':  'Up to date',
                'outdated':    'Update available',
                'missing':     'Missing locally',
                'local-only':  'Local only',
                'newer-local': 'Ahead of remote',
                'no-version':  'No version tag',
                'error':       'Error',
                'current':     'Up to date',    // alias from RawFileUpdater
            };
            elFileList.innerHTML = files.map(function (f) {
                const pill  = f.status || (f.hasUpdate ? 'outdated' : 'up-to-date');
                const label = labels[pill] || pill;
                // Show version info: "v1.2 → v1.3" or just "v1.2"
                let verStr = '';
                if (f.localVersion)  verStr = 'v' + f.localVersion;
                if (f.remoteVersion && f.remoteVersion !== f.localVersion)
                    verStr += ' → v' + f.remoteVersion;
                const errTitle = (f.error && pill === 'error')
                    ? ' title="' + f.error.replace(/"/g, '&quot;') + '"' : '';
                return '<div class="sp-file-row">'
                     +   '<span class="sp-file-name">' + f.path + '</span>'
                     +   '<span style="display:flex;align-items:center;gap:5px;flex-shrink:0">'
                     +     (verStr ? '<span class="sp-file-ver">' + verStr + '</span>' : '')
                     +     '<span class="sp-file-pill ' + pill + '"' + errTitle + '>' + label + '</span>'
                     +   '</span>'
                     + '</div>';
            }).join('');
            elFileList.style.display = '';
        }

        // Load local version badge on panel open
        function loadVersionBadge() {
            if (!elVerBadge || elVerBadge.dataset.loaded) return;
            fetch(UPDATER_URL + '?action=version')
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.version) {
                        elVerBadge.textContent = 'v' + d.version;
                        elVerBadge.dataset.loaded = '1';
                    }
                })
                .catch(function () { /* silent */ });
        }

        // Patch openPanel to load version badge, backups, and sync toolbar checkboxes
        (function () {
            const _orig = openPanel;
            openPanel = function () {
                _orig();
                loadVersionBadge();
                loadBackups();
                loadIndexStatus();
                syncToolbarCheckboxes();
            };
        }());

        if (elCheckBtn) elCheckBtn.addEventListener('click', function () {
            elCheckBtn.disabled = true;
            elCheckBtn.textContent = '⟳ Checking…';
            elApplyBtn.style.display  = 'none';
            elReinstall.style.display = 'none';
            elReloadAfter.style.display = 'none';
            setStatus('Contacting GitHub…', 'info');
            elFileList.style.display = 'none';

            fetch(UPDATER_URL + '?action=check')
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function (d) {
                    if (d.error) { setStatus('⚠ ' + d.error, 'error'); return; }

                    // Version line
                    const sameVer = d.localVersion === d.remoteVersion;
                    if (elVerBadge) elVerBadge.textContent = 'v' + d.localVersion;

                    renderFileList(d.files);

                    // Count how many files used 304 (no re-download)
                    const cached304 = (d.files || []).filter(function (f) { return f.httpStatus === 304; }).length;
                    const cacheNote = cached304 > 0 ? ' <small>(' + cached304 + ' via ETag cache)</small>' : '';

                    if (d.hasUpdates) {
                        const verInfo = sameVer
                            ? ''
                            : ' <strong>' + d.localVersion + '</strong> → <strong>' + d.remoteVersion + '</strong>';
                        setStatus('Updates available' + verInfo + cacheNote, 'warn');
                        elApplyBtn.style.display  = '';
                        elReinstall.style.display = 'none';
                    } else {
                        setStatus('✓ All files are up to date (v' + d.localVersion + ')' + cacheNote, 'ok');
                        elApplyBtn.style.display  = 'none';
                        elReinstall.style.display = '';
                    }
                })
                .catch(function (err) {
                    setStatus('⚠ Check failed: ' + err.message + '<br><small>Is updater.php accessible?</small>', 'error');
                })
                .finally(function () {
                    elCheckBtn.disabled = false;
                    elCheckBtn.textContent = '↻ Check for Updates';
                });
        });

        function runApply(force) {
            const btn = force ? elReinstall : elApplyBtn;
            btn.disabled = true;
            btn.textContent = force ? '⟳ Reinstalling…' : '⟳ Applying…';
            elFileList.style.display = 'none';
            setStatus(force ? 'Reinstalling all files…' : 'Downloading updates…', 'info');

            const url = UPDATER_URL + '?action=apply' + (force ? '&force=1' : '');
            fetch(url, { method: 'POST' })
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function (d) {
                    if (d.error) { setStatus('⚠ ' + d.error, 'error'); return; }

                    if (d.success) {
                        const lines = [];
                        if (d.updated && d.updated.length) {
                            d.updated.forEach(function (u) {
                                const arrow = (u.fromVersion && u.toVersion && u.fromVersion !== u.toVersion)
                                    ? ' <small>v' + u.fromVersion + ' → v' + u.toVersion + '</small>'
                                    : (u.toVersion ? ' <small>v' + u.toVersion + '</small>' : '');
                                lines.push('✓ ' + u.path + arrow);
                            });
                        }
                        if (d.skipped && d.skipped.length)
                            lines.push('· Already current: ' + d.skipped.join(', '));
                        if (d.newVersion)
                            lines.push('<strong>Now at v' + d.newVersion + '</strong>');
                        setStatus(lines.join('<br>'), 'ok');
                        elApplyBtn.style.display    = 'none';
                        elReinstall.style.display   = 'none';
                        elReloadAfter.style.display = '';
                    } else {
                        const failMsg = d.failed && d.failed.length
                            ? '⚠ Failed: ' + d.failed.join(', ')
                            : '⚠ Update failed';
                        setStatus(failMsg, 'error');
                    }
                })
                .catch(function (err) {
                    setStatus('⚠ Apply failed: ' + err.message, 'error');
                })
                .finally(function () {
                    btn.disabled = false;
                    btn.textContent = force ? '↺ Reinstall Files' : '↓ Apply Updates';
                });
        }

        if (elApplyBtn)    elApplyBtn.addEventListener('click',    function () { runApply(false); });
        if (elReinstall)   elReinstall.addEventListener('click',   function () { runApply(true);  });
        if (elReloadAfter) elReloadAfter.addEventListener('click', function () { location.reload(); });

        // Load version badge immediately if panel is already open on init
        loadVersionBadge();


    }());
    }());
