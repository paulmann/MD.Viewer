/**
 * Markdown Viewer — Client-side functionality
 * Version: 2.5.2
 * Author: Mikhail Deynekin
 * Site: https://Deynekin.com
 * Email: Mikhail@Deynekin.com
 *
 * Features:
 * - Theme toggle with localStorage persistence + system sync
 * - Width control (reading/article/wide) with persistence
 * - Mermaid diagram initialization
 * - Unified copy-to-clipboard controls for code blocks and blockquotes
 * - Mermaid auto-repair with English console diagnostics
 * - File browser: debounced search, tri-state sort, click/keyboard open
 *
 * v2.5.2: Added classDiagram note conversion, made every application and
 *         console message English, and made Mermaid diagnostics unconditional.
 * v2.5.0: Added Mermaid auto-repair with a grouped console report per diagram.
 * v2.4.0: Unified code and quote copying behind one copy-btn handler.
 * v2.3.0: Added one accessible copy control per rendered blockquote.
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
            theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'
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
const MDV_MERMAID_REPAIR_VERSION = '2.5.2';
const MERMAID_NODE_SHAPES = [['[[', ']]'], ['[(', ')]'], ['([', '])'], ['[', ']'], ['{{', '}}'], ['{', '}'], ['((', '))']];
const MERMAID_KEYWORD_LINE = /^(style|classDef|class|linkStyle|click|subgraph|end|graph|flowchart|direction|%%)\b/;
const MERMAID_SEQUENCE_ARROW = /^\s*([^\s:]+?)\s*(-->>|->>|-->|->|--x|-x|--\)|-\))\s*([^\s:]+?)\s*:/;

document.documentElement.dataset.mdvMermaidRepairVersion = MDV_MERMAID_REPAIR_VERSION;
console.info('[MD.Viewer] Mermaid repair engine v' + MDV_MERMAID_REPAIR_VERSION + ' loaded');

const getMermaidDiagramType = (source) => {
    const header = source.split('\n').map((line) => line.trim()).find((line) => line !== '' && !line.startsWith('%%')) || '';
    return header.split(/\s+/)[0];
};

const normalizeMermaidLine = (line) => {
    let result = '';
    let index = 0;
    while (index < line.length) {
        const idMatch = /^[A-Za-z_][\w-]*/.exec(line.slice(index));
        if (idMatch === null) { result += line[index]; index += 1; continue; }
        const nodeId = idMatch[0];
        const afterId = index + nodeId.length;
        let shape = null;
        for (const [open, close] of MERMAID_NODE_SHAPES) {
            if (!line.startsWith(open, afterId)) continue;
            const closeIndex = line.indexOf(close, afterId + open.length);
            if (closeIndex !== -1) { shape = { open, close, closeIndex }; break; }
        }
        if (shape === null) { result += nodeId; index = afterId; continue; }
        const rawLabel = line.slice(afterId + shape.open.length, shape.closeIndex);
        const trimmed = rawLabel.trim();
        const needsQuotes = trimmed !== '' && !/^["`]/.test(trimmed) && /[()]/.test(trimmed);
        result += nodeId + shape.open + (needsQuotes ? '"' + trimmed.replace(/"/g, '#quot;') + '"' : rawLabel) + shape.close;
        index = shape.closeIndex + shape.close.length;
    }
    return result;
};

const repairClassDiagramNotes = (source, fixes) => {
    const lines = source.split('\n');
    const result = [];

    for (let index = 0; index < lines.length; index += 1) {
        const match = /^(\s*)note\s+(?:right|left)\s+of\s+([A-Za-z_][\w-]*)\s*$/.exec(lines[index]);
        if (match === null) {
            result.push(lines[index]);
            continue;
        }

        const noteLines = [];
        let endIndex = index + 1;
        while (endIndex < lines.length && !/^\s*end\s+note\s*$/.test(lines[endIndex])) {
            const text = lines[endIndex].trim();
            if (text !== '') noteLines.push(text);
            endIndex += 1;
        }

        if (endIndex >= lines.length) {
            result.push(lines[index]);
            continue;
        }

        const noteText = noteLines.join('<br/>').replace(/"/g, '#quot;');
        const replacement = match[1] + 'note for ' + match[2] + ' "' + noteText + '"';
        fixes.push({
            line: index + 1,
            rule: 'plantuml-class-note',
            detail: 'PlantUML note block converted to Mermaid note-for syntax',
            before: lines[index].trim() + ' ... end note',
            after: replacement.trim()
        });
        result.push(replacement);
        index = endIndex;
    }

    return result.join('\n');
};

const repairMermaidSource = (source) => {
    const fixes = [];
    const diagramType = getMermaidDiagramType(source);
    const isSequence = diagramType === 'sequenceDiagram';
    const isFlowchart = diagramType === 'graph' || diagramType === 'flowchart';
    let prepared = source.replace(/\r\n?/g, '\n');

    if (diagramType === 'classDiagram') {
        prepared = repairClassDiagramNotes(prepared, fixes);
    }

    let lastCaller = null;
    let lastCallee = null;
    const repaired = prepared.split('\n').map((line, position) => {
        const lineNumber = position + 1;
        const original = line;
        let current = line;

        if (current.includes('\\n')) {
            current = current.split('\\n').join('<br/>');
            fixes.push({ line: lineNumber, rule: 'literal-newline', detail: 'Literal \\n replaced with <br/>', before: original.trim(), after: current.trim() });
        }
        if (/<br\s*>/i.test(current)) {
            const before = current;
            current = current.replace(/<br\s*>/gi, '<br/>');
            fixes.push({ line: lineNumber, rule: 'unclosed-br', detail: '<br> replaced with <br/>', before: before.trim(), after: current.trim() });
        }

        if (isSequence) {
            const arrow = MERMAID_SEQUENCE_ARROW.exec(current);
            if (arrow !== null) { lastCaller = arrow[1]; lastCallee = arrow[3]; }
            const returnMatch = /^(\s*)return\b\s*(.*)$/.exec(current);
            if (returnMatch !== null) {
                const before = current;
                const message = returnMatch[2].trim();
                if (lastCaller !== null && lastCallee !== null) {
                    current = returnMatch[1] + lastCallee + '-->>' + lastCaller + ': ' + (message === '' ? 'return' : message);
                    fixes.push({ line: lineNumber, rule: 'plantuml-return', detail: 'PlantUML return converted to ' + lastCallee + '-->>' + lastCaller, before: before.trim(), after: current.trim() });
                } else {
                    current = returnMatch[1] + '%% ' + before.trim();
                    fixes.push({ line: lineNumber, rule: 'plantuml-return-orphan', detail: 'Orphan PlantUML return commented out', before: before.trim(), after: current.trim() });
                }
            }
        } else if (isFlowchart && !MERMAID_KEYWORD_LINE.test(current.trim())) {
            const normalized = normalizeMermaidLine(current);
            if (normalized !== current) {
                fixes.push({ line: lineNumber, rule: 'unquoted-parentheses', detail: 'Node label containing parentheses was quoted', before: current.trim(), after: normalized.trim() });
                current = normalized;
            }
        }
        return current;
    }).join('\n');

    return { source: repaired, fixes, diagramType };
};

const extractMermaidError = (error) => {
    if (!error) return { message: 'mermaid.parse() returned false', line: null, token: null };
    const hash = error.hash || error.error?.hash || null;
    return { message: error.message || error.str || String(error), line: hash?.loc?.first_line || hash?.line || null, token: hash?.token || hash?.text || null };
};

const reportMermaidDiagnostics = (index, repair, isValid, parseError = null) => {
    const label = '[MD.Viewer] Mermaid #' + (index + 1) + ' (' + repair.diagramType + ')' + (isValid ? ' — ready' : ' — syntax error after auto-repair');
    console.group(label);
    console.info('Repair engine:', MDV_MERMAID_REPAIR_VERSION);
    console.info('Applied fixes:', repair.fixes.length);
    if (repair.fixes.length > 0) console.table(repair.fixes, ['line', 'rule', 'detail', 'before', 'after']);
    if (!isValid) {
        const details = extractMermaidError(parseError);
        console.error('Mermaid parse failed:', details.message);
        if (details.line !== null) console.error('Line:', details.line, 'Token:', details.token);
        console.debug('Repaired source:\n' + repair.source);
    }
    console.groupEnd();
};

const initMermaidIfNeeded = async () => {
    const mermaidBlocks = [...document.querySelectorAll('pre.mermaid')];
    console.info('[MD.Viewer] Mermaid blocks found:', mermaidBlocks.length);
    if (mermaidBlocks.length === 0) return;

    try {
        const { default: mermaid } = await import('https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs');
        mermaid.initialize({ startOnLoad: false, theme: root.getAttribute('data-theme') === 'dark' ? 'dark' : 'default', securityLevel: 'loose', suppressErrorRendering: true, flowchart: { useMaxWidth: true, htmlLabels: true, curve: 'basis' } });

        for (const [index, node] of mermaidBlocks.entries()) {
            const originalSource = node.textContent || '';
            const repair = repairMermaidSource(originalSource);
            node.dataset.mermaidRepairVersion = MDV_MERMAID_REPAIR_VERSION;
            node.dataset.mermaidFixes = String(repair.fixes.length);
            node.dataset.mermaidSource = originalSource;
            node.textContent = repair.source;

            let isValid = false;
            let parseError = null;
            try { isValid = await mermaid.parse(repair.source, { suppressErrors: true }) !== false; }
            catch (error) { parseError = error; }
            reportMermaidDiagnostics(index, repair, isValid, parseError);

            if (!isValid) {
                node.classList.add('mermaid-error');
                node.setAttribute('data-mermaid-error', 'true');
                continue;
            }

            const wrapper = document.createElement('div');
            wrapper.className = 'mermaid';
            wrapper.textContent = repair.source;
            wrapper.dataset.mermaidSource = originalSource;
            wrapper.dataset.mermaidFixes = String(repair.fixes.length);
            wrapper.dataset.mermaidRepairVersion = MDV_MERMAID_REPAIR_VERSION;
            wrapper.setAttribute('data-mermaid-id', 'mermaid-' + index);
            node.replaceWith(wrapper);

            try {
                await mermaid.run({ nodes: [wrapper], suppressErrors: true });
                console.info('[MD.Viewer] Mermaid #' + (index + 1) + ' rendered successfully');
            } catch (error) {
                console.error('[MD.Viewer] Mermaid #' + (index + 1) + ' render failed:', error);
                wrapper.replaceWith(node);
                node.classList.add('mermaid-error');
                node.setAttribute('data-mermaid-error', 'true');
            }
        }
    } catch (error) {
        console.error('[MD.Viewer] Mermaid module initialization failed:', error);
    }
};

const getQuoteText = (blockquote) => {
    const clone = blockquote.cloneNode(true);
    clone.querySelectorAll('.copy-btn').forEach((button) => button.remove());
    return [...clone.childNodes].map((node) => {
        const text = node.nodeType === Node.ELEMENT_NODE ? (node.innerText || node.textContent || '') : (node.textContent || '');
        return text.trim();
    }).filter(Boolean).join('\n\n');
};

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
        const mermaidElement = wrapper?.querySelector('.mermaid[data-mermaid-source], pre.mermaid[data-mermaid-source]');
        const codeElement = wrapper?.querySelector('pre code, pre.mermaid');
        const text = quote ? getQuoteText(quote) : (mermaidElement?.dataset.mermaidSource || codeElement?.textContent || '');
        const subject = quote ? 'Quote' : 'Code';
        if (!text) return;
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
