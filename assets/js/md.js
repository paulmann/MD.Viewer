/**
 * Markdown Viewer — Client-side functionality
 * Version: 2.4.2
 * Author: Mikhail Deynekin
 * Site: https://Deynekin.com
 * Email: Mikhail@Deynekin.com
 *
 * Features:
 * - Theme toggle with localStorage persistence + system sync
 * - Width control (reading/article/wide) with persistence
 * - Mermaid diagram initialization
 * - Unified copy-to-clipboard controls for code blocks and blockquotes
 * - File browser: debounced search, tri-state sort, click/keyboard open
 *
 * v2.4.2: Added a client-side Mermaid compatibility fallback, per-diagram
 *         validation, and isolation of invalid diagrams.
 * v2.4.0: Unified code and quote copying behind one copy-btn handler and reused
 *         the server-rendered icon, label, feedback, and fallback behavior.
 * v2.3.0: Added one accessible copy control per rendered blockquote, preserved
 *         paragraph boundaries, and enforced the server clipboard lock.
 * v2.2.1: Width selection persisted and re-applied to every width target;
 *         hardened theme/listener guards; const-grouped selectors; safer
 *         clipboard fallback; defensive null checks throughout.
 */

(() => {
    'use strict';

    const root = document.documentElement;

    const THEME_KEY = 'radio-viewer-theme';

    const themeBtn = document.querySelector('[data-theme-toggle]');
    const themeIcon = document.querySelector('[data-theme-icon]');

    // ============================================================
    // Theme management
    // ============================================================
    const getPreferredTheme = () => {
        const stored = localStorage.getItem(THEME_KEY);
        if (stored === 'light' || stored === 'dark') {
            return stored;
        }
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    };

    const applyTheme = (theme) => {
        root.setAttribute('data-theme', theme);
        root.classList.toggle('dark', theme === 'dark');

        if (themeIcon) {
            themeIcon.innerHTML = theme === 'dark'
                ? '<svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2.2M12 19.8V22M4.93 4.93l1.55 1.55M17.52 17.52l1.55 1.55M2 12h2.2M19.8 12H22M4.93 19.07l1.55-1.55M17.52 6.48l1.55-1.55"></path></svg>'
                : '<svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 15.2A8.5 8.5 0 0 1 8.8 4 9 9 0 1 0 20 15.2Z"></path></svg>';
        }

        themeBtn?.setAttribute(
            'aria-label',
            theme === 'dark' ? 'Включить светлую тему' : 'Включить темную тему'
        );
    };

    applyTheme(getPreferredTheme());

    themeBtn?.addEventListener('click', () => {
        const current = root.getAttribute('data-theme') || getPreferredTheme();
        const next = current === 'dark' ? 'light' : 'dark';
        localStorage.setItem(THEME_KEY, next);
        applyTheme(next);
    });

    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const onSystemChange = (e) => {
        if (!localStorage.getItem(THEME_KEY)) {
            applyTheme(e.matches ? 'dark' : 'light');
        }
    };
    if (typeof media.addEventListener === 'function') {
        media.addEventListener('change', onSystemChange);
    } else if (typeof media.addListener === 'function') {
        media.addListener(onSystemChange);
    }

// ============================================================
// Mermaid diagram initialization (lazy-load)
// Loads Mermaid only when rendered Markdown contains <pre class="mermaid">.
// ============================================================
const MERMAID_NODE_SHAPES = [['[[', ']]'], ['[(', ')]'], ['([', '])'], ['[', ']'], ['{{', '}}'], ['{', '}'], ['((', '))']];
const MERMAID_KEYWORD_LINE = /^(style|classDef|class|linkStyle|click|subgraph|end|graph|flowchart|direction|%%)\b/;

/**
 * Quotes Mermaid node labels that contain parentheses.
 *
 * Function version: 1.0.0
 *
 * @param {string} line
 * @returns {string}
 */
const normalizeMermaidLine = (line) => {
    let result = '';
    let index = 0;

    while (index < line.length) {
        const idMatch = /^[A-Za-z_][\w-]*/.exec(line.slice(index));
        if (idMatch === null) {
            result += line[index];
            index += 1;
            continue;
        }

        const nodeId = idMatch[0];
        const afterId = index + nodeId.length;
        let shape = null;

        for (const [open, close] of MERMAID_NODE_SHAPES) {
            if (!line.startsWith(open, afterId)) {
                continue;
            }
            const closeIndex = line.indexOf(close, afterId + open.length);
            if (closeIndex !== -1) {
                shape = { open, close, closeIndex };
                break;
            }
        }

        if (shape === null) {
            result += nodeId;
            index = afterId;
            continue;
        }

        const rawLabel = line.slice(afterId + shape.open.length, shape.closeIndex);
        const trimmed = rawLabel.trim();
        const needsQuotes = trimmed !== '' && !/^["`]/.test(trimmed) && /[()]/.test(trimmed);
        const label = needsQuotes ? '"' + trimmed.replace(/"/g, '#quot;') + '"' : rawLabel;

        result += nodeId + shape.open + label + shape.close;
        index = shape.closeIndex + shape.close.length;
    }

    return result;
};

/**
 * Normalizes a Mermaid diagram for Mermaid 11 compatibility. Mirrors the
 * server-side normalizer so cached pages keep rendering correctly.
 *
 * Function version: 1.0.0
 *
 * @param {string} source
 * @returns {string}
 */
const normalizeMermaidSource = (source) => source
    .replace(/<br\s*\/?\s*>/gi, '<br/>')
    .split('\n')
    .map((line) => (MERMAID_KEYWORD_LINE.test(line.trim()) ? line : normalizeMermaidLine(line)))
    .join('\n');

/**
 * Validates and renders Mermaid blocks independently so one malformed diagram
 * cannot prevent the remaining diagrams from rendering.
 *
 * Function version: 2.0.0
 *
 * @returns {Promise<void>}
 */
const initMermaidIfNeeded = async () => {
    const mermaidBlocks = [...document.querySelectorAll('pre.mermaid')];
    if (mermaidBlocks.length === 0) {
        return;
    }

    try {
        const { default: mermaid } = await import('https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs');

        mermaid.initialize({
            startOnLoad: false,
            theme: root.getAttribute('data-theme') === 'dark' ? 'dark' : 'default',
            securityLevel: 'loose',
            suppressErrorRendering: true,
            flowchart: {
                useMaxWidth: true,
                htmlLabels: true,
                curve: 'basis'
            }
        });

        const renderTargets = [];

        for (const [index, node] of mermaidBlocks.entries()) {
            const originalSource = node.textContent || '';
            const normalizedSource = normalizeMermaidSource(originalSource);
            const isValid = await mermaid.parse(normalizedSource, { suppressErrors: true });

            if (!isValid) {
                node.classList.add('mermaid-error');
                node.setAttribute('data-mermaid-error', 'true');
                continue;
            }

            const wrapper = document.createElement('div');
            wrapper.className = 'mermaid';
            wrapper.textContent = normalizedSource;
            wrapper.dataset.mermaidSource = originalSource;
            wrapper.setAttribute('data-mermaid-id', 'mermaid-' + index);
            node.replaceWith(wrapper);
            renderTargets.push(wrapper);
        }

        if (renderTargets.length > 0) {
            await mermaid.run({ nodes: renderTargets, suppressErrors: true });
        }
    } catch (error) {
        console.error('Mermaid initialization failed:', error);

        mermaidBlocks
            .filter((node) => node.isConnected)
            .forEach((node) => {
                node.classList.add('mermaid-error');
                node.setAttribute('data-mermaid-error', 'true');
            });
    }
};

/**
 * Returns plain text for an entire quote while preserving top-level paragraph
 * and line boundaries. Interactive controls are excluded.
 *
 * Function version: 1.1.0
 *
 * @param {HTMLElement} blockquote
 * @returns {string}
 */
const getQuoteText = (blockquote) => {
    const clone = blockquote.cloneNode(true);
    clone.querySelectorAll('.copy-btn').forEach((button) => button.remove());

    return [...clone.childNodes]
        .map((node) => {
            const text = node.nodeType === Node.ELEMENT_NODE
                ? (node.innerText || node.textContent || '')
                : (node.textContent || '');
            return text.trim();
        })
        .filter(Boolean)
        .join('\n\n');
};

/**
 * Marks server-rendered blockquotes as copy targets.
 *
 * Function version: 3.0.0
 *
 * @returns {void}
 */
const initQuoteBlocks = () => {
    document.querySelectorAll('blockquote').forEach((blockquote) => {
        blockquote.classList.add('quote-block');
        blockquote.setAttribute('data-quote-block', '');
    });
};

initQuoteBlocks();

window.addEventListener('load', () => {
    initMermaidIfNeeded();
}, { once: true });

    // ============================================================
    // Copy-to-clipboard (event delegation)
    // ============================================================
    const fallbackCopy = (text) => {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0';
        textarea.setAttribute('readonly', '');
        document.body.appendChild(textarea);
        textarea.select();
        textarea.setSelectionRange(0, textarea.value.length);
        let ok = false;
        try {
            ok = document.execCommand('copy');
        } finally {
            document.body.removeChild(textarea);
        }
        if (!ok) {
            throw new Error('execCommand copy failed');
        }
    };

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.copy-btn');
        if (!btn || btn.disabled) {
            return;
        }

        const quote = btn.closest('blockquote[data-quote-block]');
        const wrapper = btn.closest('.code-block-wrapper');
        const mermaidElement = wrapper?.querySelector('.mermaid[data-mermaid-source]');
        const codeElement = wrapper?.querySelector('pre code, pre.mermaid');
        const text = quote
            ? getQuoteText(quote)
            : (mermaidElement?.dataset.mermaidSource || codeElement?.textContent || '');
        const subject = quote ? 'Quote' : 'Code';

        if (!text) {
            return;
        }
        const copyIcon = btn.querySelector('.copy-icon');
        const checkIcon = btn.querySelector('.check-icon');
        const copyText = btn.querySelector('.copy-text');
        const checkText = btn.querySelector('.check-text');
        const statusEl = document.getElementById('copy-status');

        const announce = (message) => {
            if (statusEl) {
                statusEl.textContent = message;
                setTimeout(() => { statusEl.textContent = ''; }, 3000);
            }
        };

        const resetButton = () => {
            copyIcon?.classList.remove('hidden');
            checkIcon?.classList.add('hidden');
            copyText?.classList.remove('hidden');
            checkText?.classList.add('hidden');
            btn.disabled = false;
            btn.classList.remove('opacity-75', 'text-green-400');
        };

        const showSuccess = () => {
            copyIcon?.classList.add('hidden');
            checkIcon?.classList.remove('hidden');
            copyText?.classList.add('hidden');
            checkText?.classList.remove('hidden');
            btn.classList.remove('opacity-75');
            btn.classList.add('text-green-400');
            announce(subject + ' copied to clipboard');
            setTimeout(resetButton, 2000);
        };

        btn.disabled = true;
        btn.classList.add('opacity-75');

        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
            } else {
                fallbackCopy(text);
            }
            showSuccess();
        } catch (err) {
            console.error('Copy to clipboard failed:', err);
            resetButton();
            announce('Failed to copy ' + subject.toLowerCase() + '. Please select and copy manually.');
        }
    });

    // ============================================================
    // File browser: search, sort, open
    // ============================================================
    const filesTable = document.getElementById('files-table');
    const searchInput = document.getElementById('files-search');

    if (!filesTable || !searchInput) {
        return;
    }

    const tbody = filesTable.querySelector('tbody');
    if (!tbody) {
        return;
    }

    const rows = [...tbody.querySelectorAll('tr.file-row')];
    const headers = [...filesTable.querySelectorAll('th.sortable')];

    let currentSort = { key: null, direction: null };
    let searchDebounceTimer = null;

    const performSearch = (query) => {
        const q = query.trim().toLowerCase();
        let visibleCount = 0;

        rows.forEach((row) => {
            if (q === '') {
                row.classList.remove('filtered-out');
                visibleCount++;
                return;
            }
            const fileName = row.dataset.file || '';
            const dirName = row.dataset.dir || '';
            const matches = fileName.includes(q) || dirName.includes(q);
            row.classList.toggle('filtered-out', !matches);
            if (matches) {
                visibleCount++;
            }
        });

        const noResults = document.getElementById('no-results-msg');
        if (noResults) {
            noResults.style.display = (visibleCount === 0 && q !== '') ? 'block' : 'none';
        }
    };

    searchInput.addEventListener('input', (e) => {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => performSearch(e.target.value), 150);
    });

    const NUMERIC_KEYS = ['size', 'created', 'modified'];

    const compareRows = (a, b, key, direction) => {
        if (NUMERIC_KEYS.includes(key)) {
            const valA = parseInt(a.dataset[key], 10) || 0;
            const valB = parseInt(b.dataset[key], 10) || 0;
            return direction === 'asc' ? valA - valB : valB - valA;
        }
        const valA = a.dataset[key] || '';
        const valB = b.dataset[key] || '';
        const cmp = valA.localeCompare(valB, undefined, { sensitivity: 'base', numeric: true });
        return direction === 'asc' ? cmp : -cmp;
    };

    const reorder = (sortedRows) => {
        const fragment = document.createDocumentFragment();
        sortedRows.forEach((row) => fragment.appendChild(row));
        tbody.appendChild(fragment);
    };

    const sortTable = (key) => {
        let newDirection;
        if (currentSort.key !== key) {
            newDirection = 'asc';
        } else if (currentSort.direction === 'asc') {
            newDirection = 'desc';
        } else {
            newDirection = null;
        }

        headers.forEach((th) => {
            th.classList.remove('asc', 'desc');
            if (th.dataset.sort === key && newDirection) {
                th.classList.add(newDirection);
            }
        });

        currentSort = { key: newDirection ? key : null, direction: newDirection };

        if (!newDirection) {
            reorder([...rows].sort((a, b) =>
                (a.dataset.path || '').localeCompare(b.dataset.path || '')
            ));
            return;
        }

        reorder([...rows].sort((a, b) => compareRows(a, b, key, newDirection)));
    };

    headers.forEach((th) => {
        th.addEventListener('click', () => {
            const key = th.dataset.sort;
            if (key) {
                sortTable(key);
            }
        });
    });

    tbody.addEventListener('click', (e) => {
        if (e.target.closest('th')) {
            return;
        }
        const row = e.target.closest('tr.file-row');
        const path = row?.dataset.path;
        if (!path) {
            return;
        }
        // Defense in depth: reject control chars and traversal/absolute paths.
        if (/[\x00-\x1F\x7F]/.test(path) || /\.\.(?:\/|$)|^\//.test(path)) {
            return;
        }
        const url = window.location.pathname + '?file=' + encodeURIComponent(path);
        window.open(url, '_blank', 'noopener,noreferrer');
    });

    rows.forEach((row) => {
        row.setAttribute('tabindex', '0');
        row.setAttribute('role', 'button');
        row.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                row.click();
            }
        });
    });
})();

// ── Inline code: click to copy ───────────────────────────────────────────────
// Reuses the existing navigator.clipboard API, consistent with copy-btn logic.
document.addEventListener('click', (e) => {
    const el = e.target.closest('code.copy-on-click');
    if (!el) return;

    const text = el.textContent ?? '';
    if (!text) return;

    navigator.clipboard.writeText(text).then(() => {
        // Brief visual feedback — reuse the same "copied" style as copy-btn
        el.classList.add('copied');
        const prev = el.title;
        el.title = 'Copied!';
        setTimeout(() => {
            el.classList.remove('copied');
            el.title = prev;
        }, 1500);
    }).catch(() => {
        // Fallback for HTTP contexts without clipboard API
        const sel = window.getSelection();
        const range = document.createRange();
        range.selectNodeContents(el);
        sel?.removeAllRanges();
        sel?.addRange(range);
    });
});
