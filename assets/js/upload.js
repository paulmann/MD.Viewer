/**
 * MD.Viewer — File Browser Upload & Clipboard Preview
 * Auto-extracted from md.php inline <script> block.
 * Requires window.MDV_CONFIG (disableUpload, disableClipboard) and ((window.MDV_CONFIG || {}).updaterUrl || '/updater.php').
 */
'use strict';

// ── Browser: Upload .md + Clipboard Preview ──────────────────────────────
        (function () {
            const btnUpload   = document.getElementById('btn-upload-md');
            const inputUpload = document.getElementById('upload-md-input');
            const btnClip     = document.getElementById('btn-clipboard-preview');
            if (!btnUpload && !btnClip) return; // not in browser mode

            // Server-side DISABLE_* flags from MDV_CONFIG
            const cfg = window.MDV_CONFIG || {};
            if (cfg.disableUpload && btnUpload) {
                btnUpload.disabled = true;
                btnUpload.title = 'File upload is disabled by server configuration.';
                btnUpload.style.opacity = '0.4';
                btnUpload.style.cursor = 'not-allowed';
            }
            if (cfg.disableClipboard && btnClip) {
                btnClip.disabled = true;
                btnClip.title = 'Clipboard preview is disabled by server configuration.';
                btnClip.style.opacity = '0.4';
                btnClip.style.cursor = 'not-allowed';
            }

            // ── Toast helper ───────────────────────────────────────────────────
            const toast = document.getElementById('browser-toast');
            let toastTimer;
            function showToast(msg, type, ms) {
                if (!toast) return;
                clearTimeout(toastTimer);
                toast.textContent = msg;
                toast.className = 'show toast-' + (type || 'inf');
                toastTimer = setTimeout(function () {
                    toast.className = '';
                }, ms || 3000);
            }

            // ── Filename sanitizer (client-side pre-check) ─────────────────────
            function isSafeFilename(name) {
                if (!name) return false;
                if (!/\.md$/i.test(name)) return false;          // must end in .md
                if (/[/\\]/.test(name)) return false;             // no slashes
                if (/\.\./.test(name)) return false;              // no traversal
                if (/[<>:"|?*\x00-\x1f]/.test(name)) return false; // no control/shell chars
                if (name.length > 200) return false;
                return true;
            }

            // ── Upload ─────────────────────────────────────────────────────────
            if (btnUpload && inputUpload) {
                btnUpload.addEventListener('click', function () {
                    if ((window.MDV_CONFIG || {}).disableUpload) { return; } // server lock
                    inputUpload.value = '';
                    inputUpload.click();
                });

                inputUpload.addEventListener('change', function () {
                    const file = this.files && this.files[0];
                    if (!file) return;

                    // Client-side validation
                    if (!isSafeFilename(file.name)) {
                        showToast('Invalid filename. Only .md files without path separators or special characters are allowed.', 'err', 5000);
                        this.value = '';
                        return;
                    }
                    if (file.size > 2 * 1024 * 1024) {
                        showToast('File too large (max 2 MB).', 'err', 4000);
                        this.value = '';
                        return;
                    }

                    btnUpload.disabled = true;
                    showToast('Uploading…', 'inf', 30000);

                    const fd = new FormData();
                    fd.append('md_file', file, file.name);

                    fetch(((window.MDV_CONFIG || {}).updaterUrl || '/updater.php') + '?action=upload_md', { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (d.error) {
                                showToast('⚠ ' + d.error, 'err', 6000);
                            } else {
                                showToast('✓ Uploaded: ' + d.filename, 'ok', 4000);
                                setTimeout(function () { location.reload(); }, 1200);
                            }
                        })
                        .catch(function (e) { showToast('⚠ ' + e.message, 'err', 5000); })
                        .finally(function () { btnUpload.disabled = false; });
                });
            }

            // ── Clipboard Preview ──────────────────────────────────────────────
            if (btnClip) {
                btnClip.addEventListener('click', function () {
                    if ((window.MDV_CONFIG || {}).disableClipboard) { return; } // server lock
                    if (!navigator.clipboard || !navigator.clipboard.readText) {
                        showToast('Clipboard API not available in this browser or context.', 'err', 5000);
                        return;
                    }
                    navigator.clipboard.readText()
                        .then(function (text) {
                            if (!text || !text.trim()) {
                                showToast('Clipboard is empty.', 'err', 3000);
                                return;
                            }
                            // POST to md.php itself — the preview handler runs and returns a full page
                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.action = window.location.pathname;
                            form.target = '_blank';
                            form.style.display = 'none';

                            const f1 = document.createElement('input');
                            f1.type  = 'hidden';
                            f1.name  = 'md_preview';
                            f1.value = '1';

                            const f2 = document.createElement('input');
                            f2.type  = 'hidden';
                            f2.name  = 'content';
                            f2.value = text;

                            form.appendChild(f1);
                            form.appendChild(f2);
                            document.body.appendChild(form);
                            form.submit();
                            document.body.removeChild(form);
                        })
                        .catch(function (e) {
                            showToast('Cannot read clipboard: ' + e.message, 'err', 5000);
                        });
                });
            }
        }());
