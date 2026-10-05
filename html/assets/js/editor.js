/* editor.js — the block editor (Spaces slice 3, THE FIRST EXEMPLAR). Vanilla JS over the one renderer's markup plus SortableJS.
 * The server renders every block; this script serialises an edited region back to runs, saves it with the version it read (600 ms after the
 * last keystroke), and obeys a 409 stale answer by reloading the block and offering the words back. Enter splits, Backspace merges, Tab nests,
 * drag moves, "/" offers the types, "@" "[[" ":" open the pickers, files upload, presence polls every 10 s, comments live in the right pane.
 * Idempotent: a boosted navigation re-runs this file; boot() tears the last instance down. */
(function () {
    'use strict';
    if (window.SPEditor) { window.SPEditor.boot(); return; }

    var E = { timers: {}, chain: {}, pollId: null, commentPollId: null, sortables: [], active: null, menu: null, picker: null, keyBound: false };
    var TEXT_TYPES = ['paragraph', 'heading_1', 'heading_2', 'heading_3', 'bulleted_list_item', 'numbered_list_item', 'to_do', 'toggle', 'quote', 'callout'];
    var MEDIA_TYPES = { image: 'image/*', video: 'video/*', audio: 'audio/*', pdf: '.pdf', file: '*' };
    var MD_SHORTCUTS = [[/^# $/, 'heading_1'], [/^## $/, 'heading_2'], [/^### $/, 'heading_3'], [/^[-*] $/, 'bulleted_list_item'], [/^1[.)] $/, 'numbered_list_item'], [/^\[ ?\] $/, 'to_do'], [/^> $/, 'quote'], [/^---$/, 'divider'], [/^```$/, 'code'], [/^>> $/, 'toggle']];

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function csrf() { var m = $('#csrf-token-meta'); return m ? m.content : ''; }
    function editorEl() { return $('#editor'); }
    function body() { return $('#page-body'); }
    function pageId() { var e = editorEl(); return e ? e.dataset.page : null; }
    function rev() { var e = editorEl(); return e ? parseInt(e.dataset.rev || '0', 10) : 0; }
    function setRev(r) { var e = editorEl(); if (e && typeof r === 'number' && r > rev()) { e.dataset.rev = String(r); } }
    function flash(kind, text) {
        var f = $('#flash'); if (!f) { return; }
        f.innerHTML = '<div class="alert alert-' + kind + ' alert-dismissible fade show" role="alert">' + escapeHtml(text) + '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    // ---- the server ------------------------------------------------------------------------------------------------------
    function api(url, data, opts) {
        opts = opts || {};
        var init = { method: opts.method || 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf(), 'X-Requested-With': 'fetch' }, credentials: 'same-origin' };
        if (init.method !== 'GET') {
            if (data instanceof FormData) { init.body = data; }
            else { var p = new URLSearchParams(); Object.keys(data || {}).forEach(function (k) { if (data[k] !== undefined && data[k] !== null) { p.append(k, data[k]); } }); init.body = p; init.headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8'; }
        }
        return fetch(url, init).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, ok: r.ok, json: j }; }); });
    }

    // ---- the serializer: DOM → runs -----------------------------------------------------------------------------------------
    function annotationsOf(el, inherited) {
        var a = Object.assign({}, inherited || {});
        if (!el || el.nodeType !== 1) { return a; }
        var cl = el.classList, tag = el.tagName;
        if (cl.contains('ed-bold') || tag === 'B' || tag === 'STRONG') { a.bold = true; }
        if (cl.contains('ed-italic') || tag === 'I' || tag === 'EM') { a.italic = true; }
        if (cl.contains('ed-underline') || tag === 'U') { a.underline = true; }
        if (cl.contains('ed-strikethrough') || tag === 'S' || tag === 'STRIKE' || tag === 'DEL') { a.strikethrough = true; }
        if (cl.contains('ed-code') || tag === 'CODE') { a.code = true; }
        if (el.dataset && el.dataset.color) { a.color = el.dataset.color; }
        if (el.dataset && el.dataset.link) { a.link = el.dataset.link; } else if (tag === 'A' && el.getAttribute('href')) { a.link = el.getAttribute('href'); }
        return a;
    }
    function mkRun(text, a) {
        var ann = { bold: !!a.bold, italic: !!a.italic, strikethrough: !!a.strikethrough, underline: !!a.underline, code: !!a.code, color: a.color || 'default' };
        return { type: 'text', text: { content: text, link: a.link ? { url: a.link } : null }, annotations: ann, plain_text: text };
    }
    function sameAnn(x, y) { return JSON.stringify(x.annotations) === JSON.stringify(y.annotations) && JSON.stringify(x.text.link) === JSON.stringify(y.text.link); }
    function runsOf(el) {
        var runs = [];
        function walk(node, inh) {
            if (node.nodeType === 3) { var t = node.nodeValue.replace(/ /g, ' '); if (t !== '') { runs.push(mkRun(t, inh)); } return; }
            if (node.nodeType !== 1) { return; }
            if (node.tagName === 'BR') { return; }
            if (node.dataset && node.dataset.mention !== undefined) {
                var m = {}; try { m = JSON.parse(node.dataset.mention); } catch (e) { m = {}; }
                var ann = annotationsOf(node, inh); var r = mkRun(node.textContent, ann); r.type = 'mention'; r.mention = { type: m.type || 'member', id: m.id || '', name: m.name || node.textContent.replace(/^@/, '') }; runs.push(r); return;
            }
            if (node.dataset && node.dataset.equation !== undefined) {
                var ann2 = annotationsOf(node, inh); var r2 = mkRun(node.textContent, ann2); r2.type = 'equation'; r2.equation = { expression: node.dataset.equation }; runs.push(r2); return;
            }
            var a = annotationsOf(node, inh);
            if (node.tagName === 'DIV' || node.tagName === 'P') { if (runs.length && !/\n$/.test(runs[runs.length - 1].plain_text)) { runs.push(mkRun('\n', inh)); } }
            Array.prototype.forEach.call(node.childNodes, function (c) { walk(c, a); });
        }
        Array.prototype.forEach.call(el.childNodes, function (c) { walk(c, {}); });
        // merge adjacent text runs with the same look; drop a trailing newline
        var out = [];
        runs.forEach(function (r) { var last = out[out.length - 1]; if (last && last.type === 'text' && r.type === 'text' && sameAnn(last, r)) { last.text.content += r.text.content; last.plain_text += r.plain_text; } else { out.push(r); } });
        if (out.length && out[out.length - 1].type === 'text') { out[out.length - 1].text.content = out[out.length - 1].text.content.replace(/\n$/, ''); out[out.length - 1].plain_text = out[out.length - 1].text.content; if (out[out.length - 1].text.content === '') { out.pop(); } }
        return out;
    }
    function plainOf(runs) { return runs.map(function (r) { return r.plain_text; }).join(''); }
    function splitRuns(runs, at) {
        var left = [], right = [], pos = 0;
        runs.forEach(function (r) {
            var len = r.plain_text.length, end = pos + len;
            if (end <= at) { left.push(r); } else if (pos >= at) { right.push(r); }
            else if (r.type !== 'text') { (at >= end ? left : right).push(r); }
            else { var l = JSON.parse(JSON.stringify(r)), rr = JSON.parse(JSON.stringify(r)); l.text.content = r.text.content.slice(0, at - pos); l.plain_text = l.text.content; rr.text.content = r.text.content.slice(at - pos); rr.plain_text = rr.text.content; if (l.text.content) { left.push(l); } if (rr.text.content) { right.push(rr); } }
            pos = end;
        });
        return [left, right];
    }
    function nodeContent(blockEl) { var id = blockEl.dataset.blockId; var n = E.nodes[id]; return n && n.content ? JSON.parse(JSON.stringify(n.content)) : {}; }
    function contentOf(blockEl) {
        var type = blockEl.dataset.type, c = nodeContent(blockEl);
        if (type === 'code') { var code = $('[data-ed-code]', blockEl); c.rich_text = code ? [mkRun(code.innerText.replace(/\n$/, ''), {})] : []; return c; }
        if (type === 'table_row') { c.cells = $$('[data-cell]', blockEl).map(function (td) { return runsOf(td); }); return c; }
        var ed = editableOf(blockEl);
        if (ed) { c.rich_text = runsOf(ed); }
        if (type === 'to_do') { var cb = $('.ed-check', blockEl); if (cb) { c.checked = cb.checked; } }
        return c;
    }
    function editableOf(blockEl) { return $$(':scope > .ed-text, :scope > summary > .ed-text, :scope > summary > * > .ed-text, :scope > label > .ed-text, :scope > .rt-callout-body > .ed-text', blockEl)[0] || null; }
    function blockOf(node) { return node && node.closest ? node.closest('.rt-block[data-block-id], tr[data-block-id]') : null; }

    // ---- the caret ------------------------------------------------------------------------------------------------------------
    function caretOffset(el) { var s = window.getSelection(); if (!s || !s.rangeCount || !el.contains(s.anchorNode)) { return null; } var r = s.getRangeAt(0).cloneRange(); r.selectNodeContents(el); r.setEnd(s.getRangeAt(0).startContainer, s.getRangeAt(0).startOffset); return r.toString().replace(/ /g, ' ').length; }
    function textBeforeCaret(el) { var o = caretOffset(el); return o === null ? '' : el.textContent.replace(/ /g, ' ').slice(0, o); }
    function placeCaret(el, at) {
        if (!el) { return; }
        el.focus();
        var s = window.getSelection(), r = document.createRange();
        if (at === 'end' || at === undefined) { r.selectNodeContents(el); r.collapse(false); }
        else if (at === 'start' || at === 0) { r.selectNodeContents(el); r.collapse(true); }
        else { var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT), n, pos = 0, done = false; while ((n = walker.nextNode())) { var len = n.nodeValue.length; if (pos + len >= at) { r.setStart(n, at - pos); r.collapse(true); done = true; break; } pos += len; } if (!done) { r.selectNodeContents(el); r.collapse(false); } }
        s.removeAllRanges(); s.addRange(r);
    }
    function atStart(el) { return caretOffset(el) === 0; }
    function atEnd(el) { var o = caretOffset(el); return o !== null && o >= el.textContent.replace(/ /g, ' ').replace(/\n$/, '').length; }

    // ---- replacing a block's markup -------------------------------------------------------------------------------------------
    function swapBlock(blockEl, html, version) {
        var tmp = document.createElement('div'); tmp.innerHTML = html;
        var fresh = tmp.firstElementChild;
        if (!fresh) { blockEl.remove(); return null; }
        if (blockEl.tagName === 'TR') { var table = tmp.querySelector('tr[data-block-id="' + blockEl.dataset.blockId + '"]'); if (table) { fresh = table; } }
        blockEl.replaceWith(fresh);
        if (version) { fresh.dataset.version = String(version); }
        decorate(fresh);
        $$('.rt-block[data-block-id]', fresh).forEach(decorate);
        return fresh;
    }
    function registerNode(id, type, content, version) { E.nodes[id] = { id: id, type: type, content: content || {}, version: version || 1 }; }

    // ---- saving: one request at a time per block, each reading the version the last one left --------------------------------------
    function serial(id, fn) { var prev = E.chain[id] || Promise.resolve(); var p = prev.catch(function () { /* the last one failed; this one still runs */ }).then(fn); E.chain[id] = p; return p; }
    function scheduleSave(blockEl) {
        var id = blockEl.dataset.blockId; if (!id) { return; }
        clearTimeout(E.timers[id]);
        blockEl.dataset.dirty = '1';
        E.timers[id] = setTimeout(function () { saveBlock(blockEl, null); }, 600);
    }
    function saveBlock(blockEl, extra) {
        var id = blockEl.dataset.blockId; if (!id) { return Promise.resolve(); }
        clearTimeout(E.timers[id]);
        return serial(id, function () { blockEl = $('[data-block-id="' + id + '"]') || blockEl; if (!document.body.contains(blockEl) || (!blockEl.dataset.dirty && extra === undefined)) { return; } return saveNow(blockEl, extra || undefined); });
    }
    function saveNow(blockEl, extra) {
        var id = blockEl.dataset.blockId;
        var content = contentOf(blockEl), myWords = (editableOf(blockEl) || $('[data-ed-code]', blockEl) || blockEl).textContent;
        var data = Object.assign({ block: id, version: blockEl.dataset.version, content: JSON.stringify(content) }, extra || {});
        return api('/blocks/update.php', data).then(function (r) {
            if (r.status === 409 && r.json.error && r.json.error.code === 'stale') { staleReload(blockEl, r.json.error, myWords); return; }
            if (!r.ok) { flash('danger', (r.json.error && r.json.error.message) || 'That could not be saved.'); return; }
            delete blockEl.dataset.dirty;
            blockEl.dataset.version = String(r.json.version);
            registerNode(id, r.json.type || blockEl.dataset.type, content, r.json.version);
            setRev(r.json.content_rev);
            if (extra && extra.type) { var focused = document.activeElement && blockEl.contains(document.activeElement); var fresh = swapBlock(blockEl, r.json.html, r.json.version); if (fresh && focused) { placeCaret(editableOf(fresh) || $('[data-ed-code]', fresh), 'end'); } }
        });
    }
    function staleReload(blockEl, err, myWords) {
        var fresh = swapBlock(blockEl, err.html || '', err.version);
        if (!fresh) { return; }
        var tpl = $('#stale-box-template'); if (!tpl) { return; }
        var box = tpl.content.firstElementChild.cloneNode(true);
        $('.sp-stale-words', box).value = myWords;
        $('.sp-stale-copy', box).addEventListener('click', function () { try { navigator.clipboard.writeText(myWords); } catch (e) { /* the textarea is selectable */ } });
        $('.sp-stale-close', box).addEventListener('click', function () { box.remove(); });
        fresh.insertAdjacentElement('afterend', box);
        var ed = editableOf(fresh); if (ed) { ed.setAttribute('data-stale', '1'); }
    }
    function flushAll() { Object.keys(E.timers).forEach(function (id) { var el = $('[data-block-id="' + id + '"][data-dirty]'); if (el) { saveBlock(el); } }); }

    // ---- structure: insert, split, merge, move, delete ---------------------------------------------------------------------------
    function insertAfter(blockEl, type, content, focusIt, parentId) {
        var parent = parentId !== undefined ? parentId : (blockEl ? (blockEl.dataset.parent || '') : '');
        return api('/blocks/insert.php', { page: pageId(), type: type, content: JSON.stringify(content || {}), parent: parent || '', after: blockEl ? blockEl.dataset.blockId : '' }).then(function (r) {
            if (!r.ok) { flash('danger', (r.json.error && r.json.error.message) || 'That could not be added.'); return null; }
            setRev(r.json.content_rev);
            var tmp = document.createElement('div'); tmp.innerHTML = r.json.html; var fresh = tmp.firstElementChild;
            if (!fresh) { return null; }
            registerNode(r.json.record_id, type, content || {}, r.json.version);
            if (blockEl) { blockEl.insertAdjacentElement('afterend', fresh); }
            else { var container = parent ? $('[data-children-of="' + parent + '"]') : body(); if (!container) { container = body(); } container.appendChild(fresh); }
            decorate(fresh); $$('.rt-block[data-block-id]', fresh).forEach(decorate);
            if (focusIt) { var ed = editableOf(fresh) || $('[data-ed-code]', fresh); if (ed) { placeCaret(ed, 'start'); } }
            return fresh;
        });
    }
    function splitAt(blockEl, ed) {
        var runs = runsOf(ed), at = caretOffset(ed); if (at === null) { at = runs.length ? plainOf(runs).length : 0; }
        clearTimeout(E.timers[blockEl.dataset.blockId]); delete blockEl.dataset.dirty;
        return serial(blockEl.dataset.blockId, function () { return splitNow(blockEl, ed, runs, at); });
    }
    function splitNow(blockEl, ed, runs, at) {
        var halves = splitRuns(runs, at), type = blockEl.dataset.type;
        var leftC = contentOf(blockEl); leftC.rich_text = halves[0];
        var rightType = (type === 'heading_1' || type === 'heading_2' || type === 'heading_3' || type === 'quote' || type === 'callout' || type === 'toggle') ? 'paragraph' : type;
        if ((type === 'bulleted_list_item' || type === 'numbered_list_item' || type === 'to_do') && halves[0].length === 0 && halves[1].length === 0) { return saveBlock(blockEl, { type: 'paragraph' }); }   // Enter on an empty item leaves the list
        var rightC = rightType === type ? Object.assign({}, leftC, { rich_text: halves[1], checked: false }) : { rich_text: halves[1] };
        return api('/blocks/split.php', { block: blockEl.dataset.blockId, version: blockEl.dataset.version, left: JSON.stringify(leftC), right: JSON.stringify(rightC), right_type: rightType }).then(function (r) {
            if (r.status === 409 && r.json.error && r.json.error.code === 'stale') { staleReload(blockEl, r.json.error, ed.textContent); return; }
            if (!r.ok) { flash('danger', (r.json.error && r.json.error.message) || 'That could not be split.'); return; }
            setRev(r.json.content_rev);
            registerNode(blockEl.dataset.blockId, type, leftC, r.json.left.version);
            registerNode(r.json.right.block_id, rightType, rightC, r.json.right.version);
            var leftEl = swapBlock(blockEl, r.json.left.html, r.json.left.version);
            var tmp = document.createElement('div'); tmp.innerHTML = r.json.right.html; var rightEl = tmp.firstElementChild;
            if (leftEl && rightEl) { leftEl.insertAdjacentElement('afterend', rightEl); decorate(rightEl); $$('.rt-block[data-block-id]', rightEl).forEach(decorate); placeCaret(editableOf(rightEl), 'start'); }
        });
    }
    function previousTextBlock(blockEl) {
        var p = blockEl.previousElementSibling;
        while (p && (!p.matches('.rt-block[data-block-id]') || p.matches('.sp-stale-box'))) { p = p.previousElementSibling; }
        if (p) { var inner = $$('.rt-block[data-block-id]', p).filter(function (x) { return editableOf(x); }); if (inner.length && (p.dataset.type === 'toggle' || p.dataset.type === 'column_list' || p.dataset.type === 'synced_block')) { return inner[inner.length - 1]; } return editableOf(p) ? p : null; }
        var parentBlock = blockOf(blockEl.parentElement);
        return parentBlock && editableOf(parentBlock) ? parentBlock : null;
    }
    function mergeBack(blockEl, ed) {
        var prev = previousTextBlock(blockEl), mine = runsOf(ed);
        clearTimeout(E.timers[blockEl.dataset.blockId]); delete blockEl.dataset.dirty;
        if (prev) { clearTimeout(E.timers[prev.dataset.blockId]); if (prev.dataset.dirty) { saveBlock(prev); } }
        return serial(blockEl.dataset.blockId, function () { return serial(prev ? prev.dataset.blockId : '-', function () { return mergeNow(blockEl, ed, prev, mine); }); });
    }
    function mergeNow(blockEl, ed, prev, mine) {
        if (!prev) {
            if (mine.length === 0 && blockEl.dataset.type !== 'paragraph') { return saveBlock(blockEl, { type: 'paragraph' }); }
            if (mine.length === 0) { return deleteBlock(blockEl, true); }
            return Promise.resolve();
        }
        var pe = editableOf(prev); if (!pe) { return Promise.resolve(); }
        var prevRuns = runsOf(pe), at = plainOf(prevRuns).length;
        var merged = contentOf(prev); merged.rich_text = prevRuns.concat(mine);
        return api('/blocks/merge.php', { block: blockEl.dataset.blockId, version: blockEl.dataset.version, into: prev.dataset.blockId, into_version: prev.dataset.version, content: JSON.stringify(merged) }).then(function (r) {
            if (r.status === 409 && r.json.error && r.json.error.code === 'stale') { staleReload(r.json.error.block_id === prev.dataset.blockId ? prev : blockEl, r.json.error, ed.textContent); return; }
            if (!r.ok) { flash('danger', (r.json.error && r.json.error.message) || 'That could not be merged.'); return; }
            setRev(r.json.content_rev);
            registerNode(prev.dataset.blockId, prev.dataset.type, merged, r.json.into.version);
            delete E.nodes[blockEl.dataset.blockId];
            var hadKids = !!blockEl.querySelector('.rt-block[data-block-id]');
            var fresh = swapBlock(prev, r.json.into.html, r.json.into.version);
            blockEl.remove();
            if (hadKids) { return reloadBody(prev.dataset.blockId); }     // the children landed where the database put them
            if (fresh) { placeCaret(editableOf(fresh), at); }
        });
    }
    function moveBlock(blockEl, parentId, afterId) {
        if (blockEl.dataset.dirty) { saveBlock(blockEl); }
        return serial(blockEl.dataset.blockId, function () { return moveNow(blockEl, parentId, afterId); });
    }
    function moveNow(blockEl, parentId, afterId) {
        return api('/blocks/move.php', { block: blockEl.dataset.blockId, parent: parentId || '', after: afterId || '' }).then(function (r) {
            if (!r.ok) { flash('danger', (r.json.error && r.json.error.message) || 'That could not be moved.'); return reloadBody(); }
            setRev(r.json.content_rev);
            return reloadBody(blockEl.dataset.blockId);
        });
    }
    function indent(blockEl) {
        var p = blockEl.previousElementSibling; while (p && !p.matches('.rt-block[data-block-id]')) { p = p.previousElementSibling; }
        if (!p || ['divider', 'image', 'code', 'table', 'column_list'].indexOf(p.dataset.type) >= 0) { return; }
        var kids = $$('[data-children-of="' + p.dataset.blockId + '"] > .rt-block[data-block-id]', p);
        moveBlock(blockEl, p.dataset.blockId, kids.length ? kids[kids.length - 1].dataset.blockId : '');
    }
    function outdent(blockEl) {
        var parentBlock = blockOf(blockEl.parentElement); if (!parentBlock) { return; }
        moveBlock(blockEl, parentBlock.dataset.parent || '', parentBlock.dataset.blockId);
    }
    function deleteBlock(blockEl, quiet) {
        if (!quiet && !window.confirm('Remove this block' + (blockEl.querySelector('.rt-block') ? ' and what is inside it' : '') + '?')) { return Promise.resolve(); }
        var prev = previousTextBlock(blockEl);
        return api('/blocks/delete.php', { block: blockEl.dataset.blockId }).then(function (r) {
            if (!r.ok) { flash('danger', (r.json.error && r.json.error.message) || 'That could not be removed.'); return; }
            setRev(r.json.content_rev); delete E.nodes[blockEl.dataset.blockId]; blockEl.remove();
            if (prev && editableOf(prev)) { placeCaret(editableOf(prev), 'end'); }
        });
    }
    function reloadBody(focusId) {
        flushAll();
        return fetch(location.pathname + location.search, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var nb = doc.getElementById('page-body'), tree = doc.getElementById('page-tree'), ed = doc.getElementById('editor');
            if (!nb || !body()) { location.reload(); return; }
            body().innerHTML = nb.innerHTML;
            if (tree) { $('#page-tree').textContent = tree.textContent; }
            if (ed && editorEl()) { editorEl().dataset.rev = ed.dataset.rev; }
            indexTree(); decorateAll(); bindSortables();
            if (focusId) { var b = $('[data-block-id="' + focusId + '"]', body()); if (b) { var e = editableOf(b); if (e) { placeCaret(e, 'end'); } } }
        });
    }

    // ---- the tree index --------------------------------------------------------------------------------------------------------
    function indexTree() {
        E.nodes = {};
        var raw = $('#page-tree'); var tree = []; try { tree = JSON.parse(raw ? raw.textContent : '[]'); } catch (e) { tree = []; }
        (function walk(nodes) { nodes.forEach(function (n) { registerNode(n.id, n.type, n.content, n.version); if (n.children && n.children.length) { walk(n.children); } }); })(tree);
    }

    // ---- decoration: tools, placeholders, markers --------------------------------------------------------------------------------
    function decorate(blockEl) {
        if (!blockEl || blockEl.tagName === 'TR' || $(':scope > .sp-block-tools', blockEl)) { return; }
        var tools = document.createElement('div'); tools.className = 'sp-block-tools'; tools.setAttribute('contenteditable', 'false');
        tools.innerHTML = '<button type="button" class="sp-add-btn" title="Add a block below" aria-label="Add a block below">+</button><button type="button" class="sp-drag-handle" title="Drag to move; click for options" aria-label="Block options">⋮⋮</button>';
        blockEl.insertAdjacentElement('afterbegin', tools);
        var ed = editableOf(blockEl);
        if (ed) { ed.dataset.placeholder = blockEl.dataset.type === 'paragraph' ? "Type '/' for a block" : (blockEl.dataset.type.replace(/_/g, ' ')); if (ed.textContent.trim() === '') { ed.setAttribute('data-empty', '1'); } }
        var count = E.counts && E.counts[blockEl.dataset.blockId];
        if (count) { var m = document.createElement('button'); m.type = 'button'; m.className = 'sp-comment-marker'; m.title = count + ' open discussion' + (count === 1 ? '' : 's'); m.textContent = String(count); m.dataset.block = blockEl.dataset.blockId; blockEl.appendChild(m); }
        if (blockEl.dataset.type === 'table') { var tt = document.createElement('div'); tt.className = 'sp-widget-tools'; tt.setAttribute('contenteditable', 'false'); tt.innerHTML = '<button type="button" class="btn btn-light btn-sm sp-table-col" data-block="' + blockEl.dataset.blockId + '">+ column</button><button type="button" class="btn btn-light btn-sm sp-table-row" data-block="' + blockEl.dataset.blockId + '">+ row</button>'; blockEl.appendChild(tt); }
        if (['image', 'file', 'pdf', 'video', 'audio'].indexOf(blockEl.dataset.type) >= 0) { var c = nodeContent(blockEl); var wt = document.createElement('div'); wt.className = 'sp-widget-tools'; wt.setAttribute('contenteditable', 'false'); wt.innerHTML = '<button type="button" class="btn btn-light btn-sm sp-upload-btn" data-block="' + blockEl.dataset.blockId + '">' + (c.attachment_id ? 'Replace file' : 'Upload a file') + '</button>' + (c.attachment_id ? '<button type="button" class="btn btn-light btn-sm sp-file-delete" data-attachment="' + c.attachment_id + '">Remove file</button>' : '<button type="button" class="btn btn-light btn-sm sp-link-btn" data-block="' + blockEl.dataset.blockId + '">Paste a link</button>'); blockEl.appendChild(wt); if (!c.attachment_id && !c.external && !c.url) { blockEl.classList.add('ed-empty'); } }
        if (['bookmark', 'embed'].indexOf(blockEl.dataset.type) >= 0 && !nodeContent(blockEl).url) { var lt = document.createElement('div'); lt.className = 'sp-widget-tools'; lt.innerHTML = '<button type="button" class="btn btn-light btn-sm sp-link-btn" data-block="' + blockEl.dataset.blockId + '">Paste a link</button>'; blockEl.appendChild(lt); }
        if (blockEl.dataset.type === 'synced_block') { var st = document.createElement('div'); st.className = 'sp-widget-tools fs-11 text-muted'; st.setAttribute('contenteditable', 'false'); st.innerHTML = (blockEl.dataset.syncedFrom ? 'Synced copy — edits show on every page' : 'Synced original — <button type="button" class="btn btn-link btn-sm p-0 sp-copy-sync" data-block="' + blockEl.dataset.blockId + '">copy its link</button>'); blockEl.appendChild(st); }
    }
    function decorateAll() { $$('.rt-block[data-block-id]', body()).forEach(decorate); }

    // ---- drag (SortableJS) -----------------------------------------------------------------------------------------------------------
    function bindSortables() {
        E.sortables.forEach(function (s) { try { s.destroy(); } catch (e) { /* gone */ } }); E.sortables = [];
        if (!window.Sortable) { return; }
        var containers = [body()].concat($$('.ed-children[data-children-of], .rt-column-box', body()));
        containers.forEach(function (c) {
            E.sortables.push(new Sortable(c, { group: 'blocks', handle: '.sp-drag-handle', draggable: '.rt-block[data-block-id]', animation: 120, ghostClass: 'sp-drag-ghost', forceFallback: true, fallbackOnBody: true, fallbackTolerance: 3, swapThreshold: 0.6,
                onEnd: function (evt) {
                    var el = evt.item, container = el.parentElement;
                    var parentBlock = blockOf(container), parentId = parentBlock ? parentBlock.dataset.blockId : '';
                    if (container.hasAttribute('data-children-of')) { parentId = container.getAttribute('data-children-of'); }
                    var prev = el.previousElementSibling; while (prev && !prev.matches('.rt-block[data-block-id]')) { prev = prev.previousElementSibling; }
                    if (evt.from === evt.to && evt.oldIndex === evt.newIndex) { return; }
                    moveBlock(el, parentId, prev ? prev.dataset.blockId : '');
                } }));
        });
    }

    // ---- the slash menu --------------------------------------------------------------------------------------------------------------
    function openSlash(blockEl, ed) {
        var menu = $('#slash-menu'); if (!menu) { return; }
        E.menu = { block: blockEl, ed: ed, start: caretOffset(ed) };
        positionUnder(menu, blockEl); menu.classList.remove('d-none'); filterSlash('');
    }
    function filterSlash(q) {
        var items = $$('.sp-slash-item', $('#slash-menu')), first = true;
        items.forEach(function (it) { var show = it.dataset.label.indexOf(q.toLowerCase()) >= 0 || it.dataset.type.indexOf(q.toLowerCase()) >= 0; it.classList.toggle('d-none', !show); it.classList.toggle('active', show && first); if (show) { first = false; } });
    }
    function closeSlash() { var m = $('#slash-menu'); if (m) { m.classList.add('d-none'); } E.menu = null; }
    function pickSlash(type) {
        var m = E.menu; closeSlash(); if (!m) { return; }
        var ed = m.ed, blockEl = m.block;
        var text = ed.textContent.replace(/ /g, ' '); var slash = text.lastIndexOf('/', (m.start || 1) - 1);
        var query = slash >= 0 ? text.slice(slash) : '';
        // remove the "/query" from the text
        if (slash >= 0) { var runs = runsOf(ed), halves = splitRuns(runs, slash), rest = splitRuns(halves[1], query.length)[1]; var c = contentOf(blockEl); c.rich_text = halves[0].concat(rest); E.nodes[blockEl.dataset.blockId].content = c; ed.innerHTML = ''; (c.rich_text).forEach(function (r) { var s = document.createElement('span'); s.setAttribute('data-run', ''); s.textContent = r.plain_text; ed.appendChild(s); }); }
        var empty = ed.textContent.trim() === '';
        if (type === 'child_page') { flushAll(); location.href = '/pages/new?parent=' + encodeURIComponent(pageId()) + (E.spaceId ? '&space=' + E.spaceId : ''); return; }
        if (type === 'link_to_page') { if (!empty) { saveBlock(blockEl); } openPicker(blockEl, ed, 'page', '', 'link'); return; }
        if (type === 'table') { return addTable(blockEl, empty); }
        if (type === 'column_list') { return addColumns(blockEl, empty); }
        if (type === 'synced_block') { return insertAfter(blockEl, 'synced_block', {}).then(function (fresh) { if (fresh) { insertAfter(null, 'paragraph', {}, true, fresh.dataset.blockId); } }); }
        if (MEDIA_TYPES[type]) {
            var go = empty ? saveBlock(blockEl, { type: type }).then(function () { return $('[data-block-id="' + blockEl.dataset.blockId + '"]'); }) : insertAfter(blockEl, type, {});
            return go.then(function (el) { if (el) { askFile(el); } });
        }
        if (type === 'bookmark' || type === 'embed') { var go2 = empty ? saveBlock(blockEl, { type: type }).then(function () { return $('[data-block-id="' + blockEl.dataset.blockId + '"]'); }) : insertAfter(blockEl, type, {}); return go2.then(function (el) { if (el) { askLink(el); } }); }
        if (type === 'equation') { var expr = window.prompt('The formula (LaTeX):', ''); if (expr === null) { return; } var content = { expression: expr }; return empty ? saveBlock(blockEl, { type: 'equation', content: JSON.stringify(content) }) : insertAfter(blockEl, 'equation', content); }
        if (type === 'divider' || type === 'table_of_contents') { return empty ? saveBlock(blockEl, { type: type }).then(function () { var el = $('[data-block-id="' + blockEl.dataset.blockId + '"]'); insertAfter(el, 'paragraph', {}, true); }) : insertAfter(blockEl, type, {}); }
        if (type === 'callout') { var cc = contentOf(blockEl); cc.icon = { type: 'emoji', emoji: '💡' }; if (empty) { return saveBlock(blockEl, { type: 'callout', content: JSON.stringify(cc) }); } return insertAfter(blockEl, 'callout', { icon: { type: 'emoji', emoji: '💡' }, rich_text: [] }, true); }
        if (type === 'code') { return empty ? saveBlock(blockEl, { type: 'code', content: JSON.stringify({ language: 'plain', rich_text: [] }) }) : insertAfter(blockEl, 'code', { language: 'plain', rich_text: [] }, true); }
        if (empty) { return saveBlock(blockEl, { type: type }); }
        return insertAfter(blockEl, type, { rich_text: [] }, true);
    }
    function addTable(blockEl, replaceEmpty) {
        var cells = function () { return [[], []]; };
        var make = function (anchor) { return insertAfter(anchor, 'table', { table_width: 2, has_column_header: true, has_row_header: false }).then(function (t) { if (!t) { return; } return api('/blocks/insert.php', { page: pageId(), type: 'table_row', content: JSON.stringify({ cells: cells() }), parent: t.dataset.blockId }).then(function () { return api('/blocks/insert.php', { page: pageId(), type: 'table_row', content: JSON.stringify({ cells: cells() }), parent: t.dataset.blockId }); }).then(function () { return reloadBody(); }); }); };
        if (replaceEmpty) { return make(blockEl).then(function () { }); }
        return make(blockEl);
    }
    function addColumns(blockEl, replaceEmpty) {
        return insertAfter(blockEl, 'column_list', {}).then(function (cl) {
            if (!cl) { return; }
            return api('/blocks/insert.php', { page: pageId(), type: 'column', content: '{}', parent: cl.dataset.blockId }).then(function (c1) {
                return api('/blocks/insert.php', { page: pageId(), type: 'column', content: '{}', parent: cl.dataset.blockId }).then(function (c2) {
                    var steps = [];
                    if (!replaceEmpty) { steps.push(api('/blocks/move.php', { block: blockEl.dataset.blockId, parent: c1.json.record_id })); } else { steps.push(api('/blocks/insert.php', { page: pageId(), type: 'paragraph', content: '{}', parent: c1.json.record_id })); }
                    steps.push(api('/blocks/insert.php', { page: pageId(), type: 'paragraph', content: '{}', parent: c2.json.record_id }));
                    return Promise.all(steps).then(function () { if (replaceEmpty) { return api('/blocks/delete.php', { block: blockEl.dataset.blockId }); } }).then(function () { return reloadBody(); });
                });
            });
        });
    }
    function positionUnder(menu, blockEl) {
        var ed = editorEl(); var r = blockEl.getBoundingClientRect(), er = ed.getBoundingClientRect();
        menu.style.top = (r.bottom - er.top + 4) + 'px'; menu.style.left = Math.max(0, r.left - er.left) + 'px';
    }

    // ---- the pickers (@ members and departments, [[ pages, : emoji) -----------------------------------------------------------------------
    function openPicker(blockEl, ed, kind, trigger, mode) {
        var p = $('#mention-picker'); if (!p) { return; }
        E.picker = { block: blockEl, ed: ed, kind: kind, trigger: trigger, start: caretOffset(ed), mode: mode || 'mention', q: '' };
        positionUnder(p, blockEl); p.classList.remove('d-none'); fetchPicker('');
    }
    function fetchPicker(q) {
        var pk = E.picker; if (!pk) { return; }
        pk.q = q;
        fetch('/pages/mentions.php?kind=' + encodeURIComponent(pk.kind) + '&q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
            if (!E.picker || E.picker.q !== q) { return; }
            var list = $('#mention-picker-list'), rows = (j.data && j.data.candidates) || [];
            list.innerHTML = rows.length ? rows.map(function (c, i) { return '<button type="button" class="list-group-item list-group-item-action sp-mention-item' + (i === 0 ? ' active' : '') + '" data-id="' + escapeHtml(c.id) + '" data-kind="' + escapeHtml(c.kind) + '" data-name="' + escapeHtml(c.name) + '"><span class="fw-semibold">' + escapeHtml(c.name) + '</span><span class="fs-12 text-muted ms-2">' + escapeHtml(c.hint || '') + '</span></button>'; }).join('') : '<div class="list-group-item text-muted fs-12">Nothing matches.</div>';
        });
    }
    function closePicker() { var p = $('#mention-picker'); if (p) { p.classList.add('d-none'); } E.picker = null; }
    function pickMention(id, kind, name) {
        var pk = E.picker; closePicker(); if (!pk) { return; }
        var ed = pk.ed, blockEl = pk.block;
        if (pk.mode === 'link') { return insertAfter(blockEl, 'link_to_page', { page_id: id }, false).then(function () { if (ed.textContent.trim() === '' && blockEl.dataset.type === 'paragraph') { deleteBlock(blockEl, true); } }); }
        var text = ed.textContent.replace(/ /g, ' ');
        var trigStart = pk.start - pk.trigger.length; if (trigStart < 0) { trigStart = 0; }
        var typedLen = pk.trigger.length + pk.q.length;
        var runs = runsOf(ed), before = splitRuns(runs, trigStart)[0], after = splitRuns(runs, trigStart + typedLen)[1];
        var run;
        if (kind === 'emoji') { run = mkRun(name + ' ', {}); }
        else { run = mkRun((kind === 'page' || kind === 'database' ? '' : '@') + name, {}); run.type = 'mention'; run.mention = { type: kind === 'database' ? 'page' : kind, id: id, name: name }; }
        var c = contentOf(blockEl); c.rich_text = before.concat([run], kind === 'emoji' ? after : [mkRun(' ', {})].concat(after));
        E.nodes[blockEl.dataset.blockId].content = c;
        saveBlock(blockEl, { content: JSON.stringify(c), type: blockEl.dataset.type }).then(function () { var fresh = $('[data-block-id="' + blockEl.dataset.blockId + '"]'); if (fresh) { placeCaret(editableOf(fresh), plainOf(before) + run.plain_text.length + (kind === 'emoji' ? 0 : 1)); } });
    }

    // ---- files -----------------------------------------------------------------------------------------------------------------------------
    function askFile(blockEl) { var input = $('#editor-file-input'); if (!input) { return; } input.accept = MEDIA_TYPES[blockEl.dataset.type] === '*' ? '' : (MEDIA_TYPES[blockEl.dataset.type] || ''); E.uploadTarget = blockEl.dataset.blockId; input.value = ''; input.click(); }
    function uploadTo(blockId, file) {
        var max = parseInt(editorEl().dataset.maxBytes || '0', 10);
        if (max && file.size > max) { flash('warning', 'A file is at most ' + Math.floor(max / 1048576) + ' MB.'); return Promise.resolve(); }
        var fd = new FormData(); fd.append('file', file); fd.append('block', blockId);
        return api('/files/upload.php', fd).then(function (r) {
            if (!r.ok) { flash('warning', (r.json.error && r.json.error.message) || 'That file was refused.'); return; }
            setRev(r.json.content_rev);
            var el = $('[data-block-id="' + blockId + '"]'); if (el && r.json.html) { var c = nodeContent(el); c.attachment_id = r.json.attachment && r.json.attachment.attachment_id; c.url = r.json.attachment && r.json.attachment.url; registerNode(blockId, r.json.attachment && r.json.attachment.mime_type && r.json.attachment.mime_type.indexOf('image/') === 0 ? 'image' : el.dataset.type, c, r.json.version); swapBlock(el, r.json.html, r.json.version); }
        });
    }
    function askLink(blockEl) {
        var url = window.prompt('The link:', ''); if (!url) { return; }
        var c = nodeContent(blockEl), t = blockEl.dataset.type;
        if (['image', 'video', 'audio', 'pdf', 'file'].indexOf(t) >= 0) { c.external = { url: url }; } else { c.url = url; }
        E.nodes[blockEl.dataset.blockId].content = c;
        saveBlock(blockEl, { content: JSON.stringify(c), type: t });
    }
    function pasteOrDropFiles(blockEl, files) {
        var f = files && files[0]; if (!f) { return; }
        var type = f.type.indexOf('image/') === 0 ? 'image' : (f.type.indexOf('video/') === 0 ? 'video' : (f.type.indexOf('audio/') === 0 ? 'audio' : (f.type === 'application/pdf' ? 'pdf' : 'file')));
        var ed = editableOf(blockEl);
        var go = ed && ed.textContent.trim() === '' && blockEl.dataset.type === 'paragraph' ? saveBlock(blockEl, { type: type }).then(function () { return $('[data-block-id="' + blockEl.dataset.blockId + '"]'); }) : insertAfter(blockEl, type, {});
        go.then(function (el) { if (el) { uploadTo(el.dataset.blockId, f); } });
    }

    // ---- the handle menu -------------------------------------------------------------------------------------------------------------------
    function openHandleMenu(blockEl, btn) {
        closeHandleMenu();
        var m = document.createElement('div'); m.className = 'sp-handle-menu card shadow'; m.id = 'handle-menu';
        var isText = TEXT_TYPES.indexOf(blockEl.dataset.type) >= 0;
        var items = [['comment', 'Comment on this block'], ['up', 'Move up'], ['down', 'Move down'], ['indent', 'Nest under the block above'], ['outdent', 'Move out a level']];
        if (isText) { items.push(['turn', 'Turn into…']); items.push(['color', 'Colour…']); }
        items.push(['columns', 'Put in two columns']); items.push(['sync', 'Make a synced block']); items.push(['paste-sync', 'Paste a synced block below']); items.push(['duplicate', 'Duplicate']); items.push(['delete', 'Delete']);
        m.innerHTML = '<div class="list-group list-group-flush">' + items.map(function (i) { return '<button type="button" class="list-group-item list-group-item-action" data-act="' + i[0] + '">' + i[1] + '</button>'; }).join('') + '</div>';
        editorEl().appendChild(m); positionUnder(m, blockEl);
        m.addEventListener('click', function (e) { var b = e.target.closest('[data-act]'); if (!b) { return; } closeHandleMenu(); handleAct(blockEl, b.dataset.act); });
    }
    function closeHandleMenu() { var m = $('#handle-menu'); if (m) { m.remove(); } }
    function handleAct(blockEl, act) {
        var id = blockEl.dataset.blockId;
        if (act === 'comment') { return openComments(id); }
        if (act === 'delete') { return deleteBlock(blockEl); }
        if (act === 'indent') { return indent(blockEl); }
        if (act === 'outdent') { return outdent(blockEl); }
        if (act === 'up') { var p = blockEl.previousElementSibling; while (p && !p.matches('.rt-block[data-block-id]')) { p = p.previousElementSibling; } if (!p) { return; } var pp = p.previousElementSibling; while (pp && !pp.matches('.rt-block[data-block-id]')) { pp = pp.previousElementSibling; } return moveBlock(blockEl, blockEl.dataset.parent || '', pp ? pp.dataset.blockId : ''); }
        if (act === 'down') { var n = blockEl.nextElementSibling; while (n && !n.matches('.rt-block[data-block-id]')) { n = n.nextElementSibling; } if (!n) { return; } return moveBlock(blockEl, blockEl.dataset.parent || '', n.dataset.blockId); }
        if (act === 'turn') { var t = window.prompt('Turn into (paragraph, heading_1, heading_2, heading_3, bulleted_list_item, numbered_list_item, to_do, toggle, quote, callout, code):', 'paragraph'); if (t && t !== blockEl.dataset.type) { saveBlock(blockEl, { type: t.trim() }); } return; }
        if (act === 'color') { var col = window.prompt('Colour (' + (E.settings.colors || []).join(', ') + '):', 'default'); if (col === null) { return; } var c = contentOf(blockEl); c.color = col.trim() || 'default'; E.nodes[id].content = c; return saveBlock(blockEl, { content: JSON.stringify(c), type: blockEl.dataset.type }); }
        if (act === 'columns') { return addColumns(blockEl, false); }
        if (act === 'sync') { return insertAfter(blockEl, 'synced_block', {}).then(function (s) { if (!s) { return; } return api('/blocks/move.php', { block: id, parent: s.dataset.blockId }).then(function () { return reloadBody(); }); }); }
        if (act === 'paste-sync') { var from = window.prompt('The synced block\'s id (copied from its original):', ''); if (!from) { return; } return api('/blocks/insert.php', { page: pageId(), type: 'synced_block', content: '{}', parent: blockEl.dataset.parent || '', after: id, synced_from: from.trim() }).then(function (r) { if (!r.ok) { flash('warning', (r.json.error && r.json.error.message) || 'That could not be pasted.'); return; } return reloadBody(); }); }
        if (act === 'duplicate') { var cc = contentOf(blockEl); return insertAfter(blockEl, blockEl.dataset.type, cc, false).then(function () { if (blockEl.querySelector('.rt-block')) { reloadBody(); } }); }
    }

    // ---- comments (the right pane) --------------------------------------------------------------------------------------------------------------
    function openComments(blockId) {
        var pid = pageId() || ($('#page-comments-btn') || {}).dataset && $('#page-comments-btn').dataset.page;
        if (!pid) { return; }
        var url = '/pages/comments.php?page=' + encodeURIComponent(pid) + (blockId ? '&block=' + encodeURIComponent(blockId) : '');
        return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
            if (window.matchMedia('(min-width: 1280px)').matches && window.SP && SP.rightPane) { SP.rightPane.open(html, 'Comments'); bindCommentPane($('#right-pane-body')); startCommentPoll(url); }
            else { var host = $('#page-comments-host'); if (!host) { host = document.createElement('div'); host.id = 'page-comments-host'; host.className = 'card mt-3'; var bar = $('#page-comments-bar'); (bar || body()).insertAdjacentElement('afterend', host); } host.innerHTML = '<div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Comments</h5><button type="button" class="btn btn-light btn-sm btn-touch" id="page-comments-close">Close</button></div><div class="card-body">' + html + '</div>'; bindCommentPane(host); $('#page-comments-close').addEventListener('click', function () { host.remove(); stopCommentPoll(); }); startCommentPoll(url); }
        });
    }
    function bindCommentPane(root) {
        if (!root) { return; }
        $$('.sp-comment-form', root).forEach(function (f) {
            f.addEventListener('submit', function (e) {
                e.preventDefault();
                if (f.dataset.confirm && !window.confirm(f.dataset.confirm)) { return; }
                var fd = new FormData(f);
                api(f.getAttribute('action'), fd).then(function (r) {
                    if (!r.ok) { flash('danger', (r.json.error && r.json.error.message) || 'That could not be saved.'); return; }
                    refreshComments();
                });
            });
        });
        $$('.sp-comment-edit', root).forEach(function (b) { b.addEventListener('click', function () { var f = $('#comment-' + b.dataset.comment + '-edit'); if (f) { f.classList.toggle('d-none'); } }); });
        var all = $('#comment-pane-all', root); if (all) { all.addEventListener('click', function (e) { e.preventDefault(); stopCommentPoll(); openComments(''); }); }
    }
    function refreshComments() {
        var pane = $('#comment-pane'); if (!pane) { return; }
        var url = '/pages/comments.php?page=' + encodeURIComponent(pane.dataset.page) + (pane.dataset.block ? '&block=' + encodeURIComponent(pane.dataset.block) : '');
        var host = pane.parentElement;
        fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) { var active = document.activeElement && host.contains(document.activeElement) && document.activeElement.value; if (active) { return; } host.innerHTML = html; bindCommentPane(host); refreshCommentBar(); });
    }
    function refreshCommentBar() { var pid = pageId(); if (!pid) { return; } fetch('/pages/comments.php?page=' + encodeURIComponent(pid) + '&open=1', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { var n = (j.data && j.data.comments || []).length; var b = $('#page-comments-btn'); if (b) { b.innerHTML = '<i class="feather-message-square me-1"></i>' + n + ' open discussion' + (n === 1 ? '' : 's'); } }); }
    function startCommentPoll() { stopCommentPoll(); E.commentPollId = setInterval(function () { if (document.hidden) { return; } if (!$('#comment-pane')) { stopCommentPoll(); return; } refreshComments(); }, 20000); }
    function stopCommentPoll() { if (E.commentPollId) { clearInterval(E.commentPollId); E.commentPollId = null; } }

    // ---- presence ---------------------------------------------------------------------------------------------------------------------------------
    function poll() {
        if (document.hidden || !editorEl() || !document.body.contains(editorEl())) { return; }
        api('/pages/presence.php', { page: pageId(), content_rev: rev() }).then(function (r) {
            if (!r.ok) { return; }
            var d = r.json.data || {};
            var bar = $('#presence-bar'), list = $('#presence-list');
            if (bar && list) { list.innerHTML = (d.others || []).map(function (o) { return '<span class="avatar-text avatar-sm me-1" title="' + escapeHtml(o.name) + '" data-member="' + o.member_id + '">' + escapeHtml(String(o.name).charAt(0).toUpperCase()) + '</span>'; }).join(''); bar.classList.toggle('d-none', !(d.others || []).length); }
            if (d.changed && !$$('[data-dirty]', body()).length) { var b = $('#changed-banner'); if (b) { $('#changed-banner-text').textContent = 'Changed by ' + (d.by || 'someone else'); b.classList.remove('d-none'); } }
            if (d.locked) { var lb = $('#locked-banner'); if (lb) { lb.classList.remove('d-none'); } $$('[contenteditable="true"]', body()).forEach(function (el) { el.setAttribute('contenteditable', 'false'); }); }
        });
    }

    // ---- events -------------------------------------------------------------------------------------------------------------------------------------
    function onInput(e) {
        var ed = e.target.closest ? e.target.closest('[contenteditable="true"]') : null; if (!ed || !body() || !body().contains(ed)) { return; }
        var blockEl = blockOf(ed); if (!blockEl) { return; }
        if (ed.textContent.trim() === '') { ed.setAttribute('data-empty', '1'); } else { ed.removeAttribute('data-empty'); }
        var before = textBeforeCaret(ed);
        if (E.menu && E.menu.block === blockEl) { var slash = before.lastIndexOf('/'); if (slash < 0 || /\s/.test(before.slice(slash + 1))) { closeSlash(); } else { filterSlash(before.slice(slash + 1)); } }
        else if (E.picker && E.picker.block === blockEl) { var trig = E.picker.trigger; var ts = before.lastIndexOf(trig); if (ts < 0 || before.length - ts - trig.length > 40 || (trig !== '[[' && /\s{2}/.test(before.slice(ts)))) { closePicker(); } else { fetchPicker(before.slice(ts + trig.length)); } }
        else if (blockEl.dataset.type !== 'code' && blockEl.tagName !== 'TR') {
            if (/(^|\s)\/$/.test(before)) { openSlash(blockEl, ed); }
            else if (/(^|\s)@$/.test(before)) { openPicker(blockEl, ed, 'any', '@'); }
            else if (/\[\[$/.test(before)) { openPicker(blockEl, ed, 'page', '[['); }
            else if (/(^|\s):[a-z0-9_+-]{2,}$/.test(before)) { var mm = before.match(/:([a-z0-9_+-]{2,})$/); openPicker(blockEl, ed, 'emoji', ':'); fetchPicker(mm[1]); }
            else {
                for (var i = 0; i < MD_SHORTCUTS.length; i++) { if (MD_SHORTCUTS[i][0].test(before) && before === ed.textContent.replace(/ /g, ' ')) { var t = MD_SHORTCUTS[i][1]; var c = contentOf(blockEl); c.rich_text = []; if (t === 'code') { c.language = 'plain'; } E.nodes[blockEl.dataset.blockId].content = c; ed.innerHTML = ''; saveBlock(blockEl, { type: t, content: JSON.stringify(c) }); return; } }
            }
        }
        scheduleSave(blockEl);
    }
    function onKeydown(e) {
        var ed = e.target.closest ? e.target.closest('[contenteditable="true"]') : null; if (!ed || !body() || !body().contains(ed)) { return; }
        var blockEl = blockOf(ed); if (!blockEl) { return; }
        if (E.menu || E.picker) {
            var listEl = E.menu ? $('#slash-menu-list') : $('#mention-picker-list'); var items = $$(E.menu ? '.sp-slash-item:not(.d-none)' : '.sp-mention-item', listEl); var cur = items.findIndex(function (x) { return x.classList.contains('active'); });
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (!items.length) { return; } items.forEach(function (x) { x.classList.remove('active'); }); cur = (cur + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length; items[cur].classList.add('active'); return; }
            if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); var it = items[cur >= 0 ? cur : 0]; if (it) { if (E.menu) { pickSlash(it.dataset.type); } else { pickMention(it.dataset.id, it.dataset.kind, it.dataset.name); } } return; }
            if (e.key === 'Escape') { e.preventDefault(); closeSlash(); closePicker(); return; }
        }
        if (blockEl.tagName === 'TR') { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); } return; }
        if (blockEl.dataset.type === 'code') { if (e.key === 'Tab') { e.preventDefault(); document.execCommand('insertText', false, '    '); } return; }
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); splitAt(blockEl, ed); return; }
        if (e.key === 'Backspace' && atStart(ed) && window.getSelection().isCollapsed) { e.preventDefault(); mergeBack(blockEl, ed); return; }
        if (e.key === 'Tab') { e.preventDefault(); flushAll(); if (e.shiftKey) { outdent(blockEl); } else { indent(blockEl); } return; }
        if (e.key === 'ArrowUp' && atStart(ed)) { var p = previousTextBlock(blockEl); if (p) { e.preventDefault(); placeCaret(editableOf(p), 'end'); } return; }
        if (e.key === 'ArrowDown' && atEnd(ed)) { var all = $$('.rt-block[data-block-id]', body()).filter(function (x) { return editableOf(x); }); var i = all.indexOf(blockEl); if (i >= 0 && all[i + 1]) { e.preventDefault(); placeCaret(editableOf(all[i + 1]), 'start'); } return; }
        if ((e.metaKey || e.ctrlKey) && !e.altKey) {
            var cmd = { b: 'bold', i: 'italic', u: 'underline', e: 'code' }[e.key.toLowerCase()];
            if (e.key.toLowerCase() === 'k') { e.preventDefault(); var url = window.prompt('Link to:', ''); if (url) { document.execCommand('createLink', false, url); scheduleSave(blockEl); } return; }
            if (cmd) { e.preventDefault(); toggleMark(ed, cmd); scheduleSave(blockEl); return; }
            if (e.key.toLowerCase() === 's') { e.preventDefault(); flushAll(); return; }
        }
    }
    function toggleMark(ed, mark) {
        var s = window.getSelection(); if (!s || s.isCollapsed || !ed.contains(s.anchorNode)) { return; }
        var range = s.getRangeAt(0), frag = range.extractContents(); var span = document.createElement('span'); span.setAttribute('data-run', ''); span.appendChild(frag);
        var already = $$('[data-run]', span).every(function (r) { return r.classList.contains('ed-' + mark); }) && $$('[data-run]', span).length > 0;
        $$('[data-run]', span).forEach(function (r) { r.classList.toggle('ed-' + mark, !already); });
        if (!$$('[data-run]', span).length) { span.classList.toggle('ed-' + mark, !already); }
        range.insertNode(span); s.removeAllRanges(); var nr = document.createRange(); nr.selectNodeContents(span); s.addRange(nr);
    }
    function onClick(e) {
        var t = e.target;
        if (!t.closest('#slash-menu') && !t.closest('#mention-picker')) { if (E.menu && !t.closest('[contenteditable]')) { closeSlash(); } if (E.picker && !t.closest('[contenteditable]')) { closePicker(); } }
        if (!t.closest('#handle-menu') && !t.closest('.sp-drag-handle')) { closeHandleMenu(); }
        var sl = t.closest('.sp-slash-item'); if (sl) { e.preventDefault(); pickSlash(sl.dataset.type); return; }
        var mi = t.closest('.sp-mention-item'); if (mi) { e.preventDefault(); pickMention(mi.dataset.id, mi.dataset.kind, mi.dataset.name); return; }
        var add = t.closest('.sp-add-btn'); if (add) { insertAfter(blockOf(add), 'paragraph', {}, true); return; }
        var handle = t.closest('.sp-drag-handle'); if (handle) { e.preventDefault(); openHandleMenu(blockOf(handle), handle); return; }
        var marker = t.closest('.sp-comment-marker'); if (marker) { openComments(marker.dataset.block); return; }
        var oc = t.closest('.sp-open-comments'); if (oc) { openComments(''); return; }
        var up = t.closest('.sp-upload-btn'); if (up) { askFile($('[data-block-id="' + up.dataset.block + '"]')); return; }
        var lk = t.closest('.sp-link-btn'); if (lk) { askLink($('[data-block-id="' + lk.dataset.block + '"]')); return; }
        var fd = t.closest('.sp-file-delete'); if (fd) { if (!window.confirm('Remove this file?')) { return; } api('/files/delete.php', { attachment: fd.dataset.attachment }).then(function (r) { if (!r.ok) { flash('danger', (r.json.error && r.json.error.message) || 'That could not be removed.'); return; } reloadBody(); }); return; }
        var tc = t.closest('.sp-table-col'); if (tc) { api('/blocks/table-column.php', { block: tc.dataset.block }).then(function (r) { if (!r.ok) { flash('danger', (r.json.error && r.json.error.message) || 'That could not be added.'); return; } reloadBody(); }); return; }
        var tr = t.closest('.sp-table-row'); if (tr) { var tb = $('[data-block-id="' + tr.dataset.block + '"]'); var w = nodeContent(tb).table_width || 2, cells = []; for (var i = 0; i < w; i++) { cells.push([]); } api('/blocks/insert.php', { page: pageId(), type: 'table_row', content: JSON.stringify({ cells: cells }), parent: tr.dataset.block }).then(function () { reloadBody(); }); return; }
        var cs = t.closest('.sp-copy-sync'); if (cs) { try { navigator.clipboard.writeText(cs.dataset.block); } catch (x) { /* shown instead */ } window.prompt('The synced block\'s id — paste it on another page with "Paste a synced block":', cs.dataset.block); return; }
        var ab = t.closest('#editor-append-btn'); if (ab) { var last = $$(':scope > .rt-block[data-block-id]', body()).pop(); insertAfter(last || null, 'paragraph', {}, true, ''); return; }
        var rl = t.closest('#changed-banner-reload'); if (rl) { e.preventDefault(); reloadBody().then(function () { $('#changed-banner').classList.add('d-none'); }); return; }
        var cb = t.closest('.ed-check'); if (cb) { var bl = blockOf(cb); if (bl) { bl.classList.toggle('rt-done', cb.checked); bl.dataset.dirty = '1'; saveBlock(bl, null); } return; }
        var fb = blockOf(t); if (fb) { $$('.sp-focused', body()).forEach(function (x) { x.classList.remove('sp-focused'); }); fb.classList.add('sp-focused'); }
    }
    function onPaste(e) {
        var ed = e.target.closest ? e.target.closest('[contenteditable="true"]') : null; if (!ed || !body() || !body().contains(ed)) { return; }
        var blockEl = blockOf(ed); if (!blockEl) { return; }
        var files = e.clipboardData && e.clipboardData.files; if (files && files.length) { e.preventDefault(); pasteOrDropFiles(blockEl, files); return; }
        var text = e.clipboardData ? e.clipboardData.getData('text/plain') : '';
        if (text && blockEl.dataset.type !== 'code' && /\n/.test(text.trim()) && ed.textContent.trim() === '') {
            e.preventDefault();
            api('/blocks/append.php', { page: pageId(), markdown: text, parent: blockEl.dataset.parent || '', after: blockEl.dataset.blockId }).then(function (r) { if (!r.ok) { flash('danger', (r.json.error && r.json.error.message) || 'That could not be pasted.'); return; } api('/blocks/delete.php', { block: blockEl.dataset.blockId }).then(function () { reloadBody(); }); });
            return;
        }
        if (text) { e.preventDefault(); document.execCommand('insertText', false, text); }
    }
    function onDrop(e) { var blockEl = blockOf(e.target); if (!blockEl || !e.dataTransfer || !e.dataTransfer.files.length) { return; } e.preventDefault(); pasteOrDropFiles(blockEl, e.dataTransfer.files); }
    function onFileChosen(e) { var f = e.target.files && e.target.files[0]; if (!f || !E.uploadTarget) { return; } uploadTo(E.uploadTarget, f); }

    // ---- boot / teardown --------------------------------------------------------------------------------------------------------------------------------
    function teardown() {
        if (E.pollId) { clearInterval(E.pollId); E.pollId = null; }
        stopCommentPoll(); closeHandleMenu();
        E.sortables.forEach(function (s) { try { s.destroy(); } catch (x) { /* gone */ } }); E.sortables = [];
        Object.keys(E.timers).forEach(function (k) { clearTimeout(E.timers[k]); }); E.timers = {};
    }
    function boot() {
        teardown();
        if (!E.keyBound) {
            document.addEventListener('input', onInput);
            document.addEventListener('keydown', onKeydown);
            document.addEventListener('click', onClick);
            document.addEventListener('paste', onPaste);
            document.addEventListener('drop', onDrop);
            document.addEventListener('dragover', function (e) { if (blockOf(e.target) && e.dataTransfer && e.dataTransfer.types.indexOf('Files') >= 0) { e.preventDefault(); } });
            document.addEventListener('change', function (e) { if (e.target.id === 'editor-file-input') { onFileChosen(e); } });
            document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
            document.body.addEventListener('htmx:beforeSwap', function (e) { if (e.detail && e.detail.target && e.detail.target.id === 'page-content') { flushAll(); teardown(); } });
            window.addEventListener('beforeunload', flushAll);
            E.keyBound = true;
        }
        var ed = editorEl(); if (!ed) { return; }
        var s = $('#editor-settings'); try { E.settings = JSON.parse(s ? s.textContent : '{}'); } catch (x) { E.settings = {}; }
        E.counts = E.settings.comment_counts || {};
        indexTree(); decorateAll(); bindSortables();
        E.pollId = setInterval(poll, 10000);
        var pc = $('#page-content'); E.spaceId = pc && pc.dataset.spaceId ? pc.dataset.spaceId : '';
    }
    window.SPEditor = { boot: boot, teardown: teardown, runsOf: runsOf, contentOf: contentOf, openComments: openComments, reloadBody: reloadBody, flush: flushAll };
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
