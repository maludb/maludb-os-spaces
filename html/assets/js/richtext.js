/* richtext.js — the one rich-text serializer in the browser (Spaces): the runs of a contenteditable region (<span data-run> runs, mentions and
 * equations atomic, the browser's own <b>/<i>/<div> read back), the caret's offset in plain text, placing it. editor.js (slice 3) and
 * channel.js (slice 4, the composer) share it. Loaded before either. */
(function () {
    'use strict';
    if (window.SPRich) { return; }
    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
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
    window.SPRich = { escapeHtml: escapeHtml, annotationsOf: annotationsOf, mkRun: mkRun, sameAnn: sameAnn, runsOf: runsOf, plainOf: plainOf, splitRuns: splitRuns, caretOffset: caretOffset, textBeforeCaret: textBeforeCaret, placeCaret: placeCaret, atStart: atStart, atEnd: atEnd };
})();
