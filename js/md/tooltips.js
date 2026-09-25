/**
 * Markdown Viewer — Glossary Tooltip Engine
 * Version: 2.4.5
 * Author: Mikhail Deynekin
 * Site: https://Deynekin.com
 * Email: Mikhail@Deynekin.com
 *
 * Features:
 * - Hover tooltips for glossary terms marked with .glossary-term spans
 * - Tooltip content read live from DOM table cells (id="gd-{slug}-{row}-{col}")
 *   — zero HTML duplication, data lives only in the source glossary table
 * - Parenthetical aliases ("Term (ABBR)") resolved to the same tooltip entry
 * - 2-second grace period before hiding — tooltip stays open while mouse
 *   moves from term span to tooltip bubble, allowing text selection and
 *   clicking links inside the tooltip
 * - Tooltip is interactive: pointer-events enabled, links are clickable
 * - Smart positioning: prefers below the term, flips above near viewport edge,
 *   clamps horizontally to keep bubble fully within visible area
 * - Double requestAnimationFrame pattern ensures real dimensions before
 *   positioning (avoids zero-size measurement on first paint)
 * - Event delegation on document — works for dynamically added terms
 * - Touch support: tap to toggle, tap outside to dismiss
 * - Keyboard: Escape closes the active tooltip
 * - Scroll hides immediately (stale position), resize repositions live
 * - Header labels cached per table slug after first read (hdrCache)
 * - Tooltip HTML cached per data-gterm value after first build (tipCache)
 * - parseGterm() splits from the right — safe for slugs containing dashes
 *   or other non-alphanumeric characters (e.g. "tbl-3:1:3")
 *
 * v2.4.0: Initial implementation — singleton tooltip, DOM-id cell references,
 *         event delegation, touch support, double-rAF positioning.
 * v2.4.1: Fixed parseGterm() to split from right; double-rAF positioning;
 *         mouseout child-node guard via el.contains(relatedTarget);
 *         replaced touchstart+passive with touchend for preventDefault safety;
 *         display:none deferred 220ms after CSS transition completes.
 * v2.4.5: re-append tip to body on every showTip() — ensures tooltip is always
 *         the topmost DOM child regardless of panel z-index stacking contexts.
 * v2.4.4: data-sp-tip support — static inline tooltips for Settings panel features;
 *         [data-sp-tip] elements use same .g-tooltip bubble and positioning engine.
 * v2.4.3: Tooltip header — full first-column cell (data-gc="1") rendered as
 *         .g-tooltip-header above body rows; getTermCell() locates it via TR.
 * v2.4.2: HIDE_DELAY = 2000ms grace period before hiding; overTip flag keeps
 *         tooltip open while mouse is over it; pointer-events: auto (was none)
 *         so links and text inside tooltip are clickable/selectable;
 *         scheduleHide()/cancelHide() centralized; scroll hides immediately,
 *         resize repositions without hiding.
 */
    (function () {
        'use strict';

        function init() {
            if (!document.querySelector('.glossary-term') && !document.querySelector('[data-sp-tip]')) return;

            // ── Singleton tooltip bubble ──────────────────────────────────────
            const tip = document.createElement('div');
            tip.className = 'g-tooltip';
            tip.setAttribute('role', 'tooltip');
            document.body.appendChild(tip);

            // ── State ─────────────────────────────────────────────────────────
            const hdrCache = {};
            const tipCache = {};
            let showTimer  = null;
            let hideTimer  = null;
            let activeEl   = null;
            let isVisible  = false;
            let overTip    = false;  // mouse is currently over the tooltip bubble

            const SHOW_DELAY = 100;   // ms before showing
            const HIDE_DELAY = 2000;  // ms of grace period before hiding

            // ── Helpers ───────────────────────────────────────────────────────

            // Parse "tbl-3:1:3" safely from the right so slug dashes don't break split
            function parseGterm(attr) {
                const last2 = attr.lastIndexOf(':');
                if (last2 < 1) return null;
                const mid = attr.lastIndexOf(':', last2 - 1);
                if (mid < 0) return null;
                return {
                    slug: attr.slice(0, mid),
                    row:  parseInt(attr.slice(mid + 1, last2), 10),
                    cols: parseInt(attr.slice(last2 + 1), 10),
                };
            }

            function getHeaders(slug) {
                if (hdrCache[slug]) return hdrCache[slug];
                let anchor = document.getElementById('gd-' + slug + '-0-1');
                if (!anchor) anchor = document.querySelector('[id^="gd-' + slug + '-"]');
                if (!anchor) return (hdrCache[slug] = []);
                const tbl = anchor.closest('table');
                if (!tbl) return (hdrCache[slug] = []);
                hdrCache[slug] = Array.from(tbl.querySelectorAll('thead th'))
                                       .map(th => th.textContent.trim());
                return hdrCache[slug];
            }

            /**
             * Find the first-column <td data-gc="1"> for the given table row.
             * Reads the row's TR element via any known data cell in that row.
             */
            function getTermCell(slug, row) {
                // Any non-first cell gives us access to the TR
                const ref = document.getElementById('gd-' + slug + '-' + row + '-1');
                if (!ref) return null;
                const tr = ref.closest('tr');
                if (!tr) return null;
                return tr.querySelector('[data-gc="1"]');
            }

            function buildTip(el) {
                // ── Static inline tip (Settings panel features) ───────────────
                if (el.dataset.spTip !== undefined) {
                    const header = el.dataset.spTipHeader || el.textContent.trim();
                    const body   = el.dataset.spTip;
                    if (!body) return '';
                    let html = header
                        ? '<div class="g-tooltip-header">' + header + '</div>'
                        : '';
                    html += '<div class="g-tooltip-row">'
                          +   '<div class="g-tooltip-val">' + body + '</div>'
                          + '</div>';
                    return html;
                }

                // ── Glossary table tip (standard .glossary-term) ──────────────
                const attr = el.dataset.gterm || '';
                if (tipCache[attr] !== undefined) return tipCache[attr];

                const p = parseGterm(attr);
                if (!p || p.cols < 2) return (tipCache[attr] = '');

                // ── Header: full first-column cell content ────────────────────
                const termCell  = getTermCell(p.slug, p.row);
                const termLabel = termCell ? termCell.innerHTML.trim() : '';
                let html = termLabel
                    ? '<div class="g-tooltip-header">' + termLabel + '</div>'
                    : '';

                // ── Body: remaining columns with their header labels ───────────
                const headers = getHeaders(p.slug);
                for (let c = 1; c < p.cols; c++) {
                    const cell = document.getElementById('gd-' + p.slug + '-' + p.row + '-' + c);
                    if (!cell) continue;
                    const val = cell.innerHTML.trim();
                    if (!val) continue;
                    const label = headers[c] || ('Column ' + c);
                    html += '<div class="g-tooltip-row">'
                          +   '<div class="g-tooltip-label">' + label + '</div>'
                          +   '<div class="g-tooltip-val">'   + val   + '</div>'
                          + '</div>';
                }
                return (tipCache[attr] = html);
            }

            // Position AFTER content is rendered (called inside rAF so sizes are real)
            function positionTip(el) {
                const GAP = 12;
                const r   = el.getBoundingClientRect();
                const tw  = tip.offsetWidth;
                const th  = tip.offsetHeight;
                const vw  = window.innerWidth  || document.documentElement.clientWidth;
                const vh  = window.innerHeight || document.documentElement.clientHeight;

                let top  = r.bottom + GAP;
                if (top + th > vh - GAP) top = r.top - th - GAP;
                if (top < GAP) top = GAP;

                let left = r.left + r.width / 2 - tw / 2;
                if (left + tw > vw - GAP) left = vw - tw - GAP;
                if (left < GAP) left = GAP;

                tip.style.left = Math.round(left) + 'px';
                tip.style.top  = Math.round(top)  + 'px';
            }

            // ── Show / hide ───────────────────────────────────────────────────

            function showTip(el) {
                clearTimeout(hideTimer);
                hideTimer = null;

                const html = buildTip(el);
                if (!html) return;

                // Re-append to body so tooltip is always the topmost DOM child
                // (guarantees paint order above any dynamically opened panels)
                if (tip.parentNode !== document.body) document.body.appendChild(tip);
                else document.body.appendChild(tip); // move to last child position

                tip.innerHTML    = html;
                tip.style.display = 'block';

                // Double rAF: first frame allows browser to lay out content,
                // second frame measures real dimensions before positioning
                requestAnimationFrame(function () {
                    requestAnimationFrame(function () {
                        positionTip(el);
                        tip.classList.add('visible');
                        isVisible = true;
                    });
                });
            }

            function scheduleHide() {
                clearTimeout(hideTimer);
                hideTimer = setTimeout(function () {
                    // Only hide if mouse is neither over a term nor over the tooltip
                    if (!overTip && activeEl === null) {
                        tip.classList.remove('visible');
                        isVisible = false;
                        clearTimeout(tip._displayTimer);
                        tip._displayTimer = setTimeout(function () {
                            if (!isVisible) tip.style.display = 'none';
                        }, 220);
                    }
                }, HIDE_DELAY);
            }

            function cancelHide() {
                clearTimeout(hideTimer);
                hideTimer = null;
            }

            // ── Tooltip own mouse events (so user can hover over it) ──────────
            tip.addEventListener('mouseenter', function () {
                overTip = true;
                cancelHide();
            });

            tip.addEventListener('mouseleave', function () {
                overTip = false;
                // If no term is active either, start the hide countdown
                if (activeEl === null) scheduleHide();
            });

            // ── Term span events (delegated) ──────────────────────────────────
            document.addEventListener('mouseover', function (e) {
                const el = e.target.closest('.glossary-term') || e.target.closest('[data-sp-tip]');
                if (!el) return;
                cancelHide();
                if (el === activeEl) return;
                clearTimeout(showTimer);
                activeEl  = el;
                showTimer = setTimeout(function () { showTip(el); }, SHOW_DELAY);
            });

            document.addEventListener('mouseout', function (e) {
                const el = e.target.closest('.glossary-term') || e.target.closest('[data-sp-tip]');
                if (!el) return;
                // Ignore if still inside the same span (moving over child node)
                if (el.contains(e.relatedTarget)) return;
                clearTimeout(showTimer);
                activeEl = null;
                // Give user HIDE_DELAY ms to move mouse to the tooltip bubble
                scheduleHide();
            });

            // Hide on scroll / resize (immediate — tooltip position is stale)
            window.addEventListener('scroll', function () {
                cancelHide();
                tip.classList.remove('visible');
                isVisible = false;
                tip.style.display = 'none';
                activeEl = null;
                overTip  = false;
            }, { passive: true });

            window.addEventListener('resize', function () {
                if (isVisible) positionTip(activeEl || tip);
            }, { passive: true });

            // ── Touch: tap to toggle ──────────────────────────────────────────
            document.addEventListener('touchend', function (e) {
                const el = e.target.closest('.glossary-term') || e.target.closest('[data-sp-tip]');
                if (!el) {
                    // Tapped outside — hide immediately
                    cancelHide();
                    tip.classList.remove('visible');
                    isVisible = false;
                    tip.style.display = 'none';
                    activeEl = null;
                    return;
                }
                e.preventDefault();
                if (el === activeEl && isVisible) {
                    cancelHide();
                    tip.classList.remove('visible');
                    isVisible = false;
                    tip.style.display = 'none';
                    activeEl = null;
                } else {
                    activeEl = el;
                    showTip(el);
                }
            });

            // ── Keyboard: Escape to close ─────────────────────────────────────
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && isVisible) {
                    cancelHide();
                    tip.classList.remove('visible');
                    isVisible = false;
                    tip.style.display = 'none';
                    activeEl = null;
                }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }

    }());