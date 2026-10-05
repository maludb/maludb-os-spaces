/* channel.js — the conversation (Spaces slice 4, THE SECOND EXEMPLAR). Polling without a socket: HTMX asks /since every 3 s while visible
 * (20 s hidden) with the last id and the shown rows' hashes; the server answers 204, or the new rows plus out-of-band swaps of rows that
 * changed. This script keeps the hashes, scrolls when at the bottom, marks read when the bottom is reached, runs the composer (runs from
 * richtext.js, @ and : pickers, attachments, send later, Enter sends), the row menus (react, save, pin, remind, edit, delete) and the
 * thread in the right pane at 1280. Idempotent across boosted navigations. */
(function () {
    'use strict';
    if (window.SPChannel) { window.SPChannel.boot(); return; }
    var R = window.SPRich;
    var S = { bound: false, hashes: {}, threadHashes: {}, picker: null, readTimer: null, lastRead: 0 };
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function csrf() { var m = $('#csrf-token-meta'); return m ? m.content : ''; }
    function root() { return $('#channel-view-content'); }
    function wrap() { return $('#messages-wrap'); }
    function list() { return $('#messages'); }
    function threadEl() { return $('#thread-pane'); }
    function flash(kind, text) { var f = $('#flash'); if (!f) { return; } f.innerHTML = '<div class="alert alert-' + kind + ' alert-dismissible fade show" role="alert">' + R.escapeHtml(text) + '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>'; }
    function api(url, data) {
        var init = { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf() }, credentials: 'same-origin' };
        if (data instanceof FormData) { init.body = data; } else { var p = new URLSearchParams(); Object.keys(data || {}).forEach(function (k) { if (data[k] !== undefined && data[k] !== null) { p.append(k, data[k]); } }); init.body = p; init.headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8'; }
        return fetch(url, init).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, ok: r.ok, json: j }; }); });
    }
    function lastIdIn(container) { var rows = $$('.sp-message[data-id]', container); return rows.length ? parseInt(rows[rows.length - 1].dataset.id, 10) : 0; }
    function after() { var l = list(); return l ? Math.max(lastIdIn(l), parseInt((root() || {}).dataset ? root().dataset.last || '0' : '0', 10)) : 0; }
    function threadAfter() { var t = threadEl(); return t ? Math.max(lastIdIn($('#thread-messages', t) || t), parseInt(t.dataset.last || '0', 10)) : 0; }
    function hashes() { var out = {}; $$('.sp-message[data-id]', list()).forEach(function (r) { out[r.dataset.id] = r.dataset.hash; }); return JSON.stringify(out); }
    function threadHashes() { var t = threadEl(); var out = {}; if (t) { $$('.sp-message[data-id]', t).forEach(function (r) { out[r.dataset.id] = r.dataset.hash; }); } return JSON.stringify(out); }
    function atBottom() { var w = wrap(); return !w || (w.scrollHeight - w.scrollTop - w.clientHeight) < 40; }
    function scrollBottom() { var w = wrap(); if (w) { w.scrollTop = w.scrollHeight; } }
    function markRead() {
        var r = root(); if (!r || r.dataset.member !== '1') { return; }
        var last = after(); if (!last || last <= S.lastRead) { return; }
        S.lastRead = last;
        api('/channels/read.php', { channel: r.dataset.channel, message: last }).then(function () { var line = $('#unread-line'); if (line) { setTimeout(function () { line.remove(); }, 4000); } $$('.sp-tree-badge').forEach(function () { /* the sidebar refreshes on the next navigation */ }); });
    }
    function maybeRead() { if (document.hidden) { return; } clearTimeout(S.readTimer); S.readTimer = setTimeout(function () { if (atBottom()) { markRead(); } }, 600); }
    function appendRow(container, html) {
        var tmp = document.createElement('div'); tmp.innerHTML = html;
        var row = tmp.firstElementChild; if (!row) { return null; }
        var existing = row.id ? document.getElementById(row.id) : null;
        if (existing) { existing.replaceWith(row); } else { var empty = $('#messages-empty', container); if (empty) { empty.remove(); } container.appendChild(row); }
        if (window.htmx) { htmx.process(row); }
        return row;
    }
    // ---- the poll's answers ----
    function onAfterRequest(e) {
        var el = e.detail && e.detail.elt; if (!el || (el.id !== 'messages' && el.id !== 'thread-messages')) { return; }
        var xhr = e.detail.xhr; if (!xhr) { return; }
        var running = xhr.getResponseHeader('X-Running');
        var ind = el.id === 'messages' ? $('#channel-thinking') : $('#thread-thinking');
        if (ind && running !== null) { if (parseInt(running, 10) > 0) { if (ind.textContent.trim() === '') { ind.textContent = 'Thinking…'; } ind.classList.remove('d-none'); } else { ind.classList.add('d-none'); } }
    }
    function onAfterSwap(e) {
        var el = e.detail && e.detail.target; if (!el) { return; }
        if (el.id === 'messages') { if (S.wasAtBottom) { scrollBottom(); maybeRead(); } }
        if (el.id === 'thread-messages') { var t = $('.sp-thread-messages'); if (t && S.threadWasAtBottom) { t.scrollTop = t.scrollHeight; } }
        if (el.id === 'right-pane-body' && threadEl()) { bindThread(); }
    }
    function onBeforeRequest(e) { var el = e.detail && e.detail.elt; if (el && el.id === 'messages') { S.wasAtBottom = atBottom(); } if (el && el.id === 'thread-messages') { var t = $('.sp-thread-messages'); S.threadWasAtBottom = !t || (t.scrollHeight - t.scrollTop - t.clientHeight) < 40; } }
    document.body.addEventListener('readMoved', function () { if (S.wasAtBottom) { scrollBottom(); maybeRead(); } });
    // ---- load earlier ----
    function loadEarlier(e) {
        var a = e.target.closest('#load-earlier-btn'); if (!a) { return; }
        e.preventDefault();
        var w = wrap(), l = list(); var before = a.dataset.before; var oldH = w.scrollHeight;
        fetch(a.getAttribute('href'), { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html'); var nl = doc.getElementById('messages'); var nb = doc.getElementById('load-earlier-btn');
            if (!nl) { return; }
            var frag = document.createDocumentFragment(); Array.prototype.slice.call(nl.children).forEach(function (c) { if (!c.id || !document.getElementById(c.id)) { frag.appendChild(c); } });
            l.insertBefore(frag, l.firstChild); if (window.htmx) { htmx.process(l); }
            w.scrollTop = w.scrollHeight - oldH;
            if (nb) { a.dataset.before = nb.dataset.before; a.setAttribute('href', nb.getAttribute('href')); } else { a.remove(); }
        });
    }
    // ---- the composer ----
    function composers() { return $$('form.sp-composer'); }
    function composerRuns(form) { var box = $('.sp-composer-input', form); return box ? R.runsOf(box) : []; }
    function clearComposer(form) { var box = $('.sp-composer-input', form); if (box) { box.innerHTML = ''; } $('.sp-composer-attachments', form).value = ''; var files = $('.sp-composer-files', form); if (files) { files.innerHTML = ''; files.classList.add('d-none'); } $('.sp-composer-schedule', form).value = ''; var lr = $('.sp-composer-later-row', form); if (lr) { lr.classList.add('d-none'); } }
    function send(form) {
        var runs = composerRuns(form), att = $('.sp-composer-attachments', form).value;
        if (!runs.length && !att) { return; }
        $('.sp-composer-runs', form).value = JSON.stringify(runs);
        var later = $('.sp-composer-later-at', form) || $('[id$="-later-at"]', form); var sched = $('.sp-composer-schedule', form);
        if (later && later.value && !later.closest('.d-none')) { sched.value = later.value; }
        var fd = new FormData(form);
        var btn = $('button[type="submit"]', form); if (btn) { btn.disabled = true; }
        api(form.getAttribute('action'), fd).then(function (r) {
            if (btn) { btn.disabled = false; }
            if (!r.ok) { flash('warning', (r.json.error && r.json.error.message) || 'That could not be sent.'); return; }
            clearComposer(form);
            var isReply = form.dataset.action === 'reply';
            if (r.json.html) {
                if (isReply) { var t = $('#thread-messages'); if (t) { appendRow(t, r.json.html); var tm = $('.sp-thread-messages'); if (tm) { tm.scrollTop = tm.scrollHeight; } } if (fd.get('also_to_channel') === 'yes' && list()) { appendRow(list(), r.json.html.replace('hx-swap-oob="outerHTML"', '')); } }
                else if (r.json.message && r.json.message.scheduled_for) { flash('success', r.json.did + '. See Scheduled messages.'); }
                else if (list()) { appendRow(list(), r.json.html); scrollBottom(); S.lastRead = Math.max(S.lastRead, r.json.record_id || 0); root().dataset.last = String(Math.max(parseInt(root().dataset.last || '0', 10), r.json.record_id || 0)); }
            }
            $('.sp-composer-input', form).focus();
        });
    }
    function onSubmit(e) {
        var form = e.target.closest ? e.target.closest('form') : null; if (!form) { return; }
        if (form.classList.contains('sp-composer')) { e.preventDefault(); send(form); return; }
        if (form.classList.contains('sp-msg-form')) {
            e.preventDefault();
            if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) { return; }
            var fd = new FormData(form);
            api(form.getAttribute('action'), fd).then(function (r) {
                if (!r.ok) { flash('warning', (r.json.error && r.json.error.message) || 'That could not be done.'); return; }
                var menu = form.closest('details'); if (menu) { menu.removeAttribute('open'); }
                if (r.json.html && r.json.record_id && !form.classList.contains('sp-remind-form')) {
                    var row = form.closest('.sp-message');
                    if (row) { var inThread = !!row.closest('#thread-messages'); fetch('/channels/messages/get.php?message=' + r.json.record_id + (inThread ? '&thread=1' : ''), { credentials: 'same-origin' }).then(function (x) { return x.text(); }).then(function (html) { var tmp = document.createElement('div'); tmp.innerHTML = html; var fresh = tmp.firstElementChild; if (fresh) { row.replaceWith(fresh); if (window.htmx) { htmx.process(fresh); } } }); }
                } else if (r.json.did) { flash('success', r.json.did); }
                if (form.classList.contains('sp-remind-form')) { form.remove(); }
                if (form.classList.contains('sp-edit-form')) { form.classList.add('d-none'); }
            });
        }
    }
    function onKeydown(e) {
        var box = e.target.closest ? e.target.closest('.sp-composer-input') : null; if (!box) { return; }
        if (S.picker) {
            var items = $$('.sp-mention-item', S.picker.el); var cur = items.findIndex(function (x) { return x.classList.contains('active'); });
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (!items.length) { return; } items.forEach(function (x) { x.classList.remove('active'); }); cur = (cur + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length; items[cur].classList.add('active'); return; }
            if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); var it = items[cur >= 0 ? cur : 0]; if (it) { pick(it); } return; }
            if (e.key === 'Escape') { e.preventDefault(); closePicker(); return; }
        }
        if (e.key === 'Enter' && !e.shiftKey && !(window.matchMedia('(max-width: 600px)').matches && e.isComposing)) { e.preventDefault(); send(box.closest('form')); return; }
        if ((e.metaKey || e.ctrlKey) && !e.altKey) { var cmd = { b: 'bold', i: 'italic', e: 'code' }[e.key.toLowerCase()]; if (cmd) { e.preventDefault(); toggleMark(box, cmd); } }
    }
    function toggleMark(box, mark) {
        var s = window.getSelection(); if (!s || s.isCollapsed || !box.contains(s.anchorNode)) { return; }
        var range = s.getRangeAt(0), frag = range.extractContents(); var span = document.createElement('span'); span.setAttribute('data-run', ''); span.className = 'ed-' + mark; span.appendChild(frag); range.insertNode(span);
        s.removeAllRanges(); var nr = document.createRange(); nr.selectNodeContents(span); s.addRange(nr);
    }
    function onInput(e) {
        var box = e.target.closest ? e.target.closest('.sp-composer-input') : null; if (!box) { return; }
        var before = R.textBeforeCaret(box);
        if (S.picker) { var trig = S.picker.trigger; var ts = before.lastIndexOf(trig); if (ts < 0 || before.length - ts - trig.length > 40 || /\s{2}/.test(before.slice(ts))) { closePicker(); } else { fetchPicker(before.slice(ts + trig.length)); } return; }
        if (/(^|\s)@$/.test(before)) { openPicker(box, 'member', '@'); }
        else if (/(^|\s):[a-z0-9_+-]{2,}$/.test(before)) { var mm = before.match(/:([a-z0-9_+-]{2,})$/); openPicker(box, 'emoji', ':'); fetchPicker(mm[1]); }
    }
    function openPicker(box, kind, trigger) {
        var form = box.closest('form'); var el = $('.sp-mention-picker', form);
        if (!el) { el = document.createElement('div'); el.className = 'sp-mention-picker card shadow'; el.innerHTML = '<div class="list-group list-group-flush"></div>'; form.appendChild(el); }
        S.picker = { el: el, box: box, kind: kind, trigger: trigger, start: R.caretOffset(box), q: '', channel: form.dataset.channel };
        fetchPicker('');
    }
    function fetchPicker(q) {
        var pk = S.picker; if (!pk) { return; } pk.q = q;
        fetch('/channels/mentions.php?channel=' + encodeURIComponent(pk.channel) + '&kind=' + pk.kind + '&q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
            if (!S.picker || S.picker.q !== q) { return; }
            var rows = (j.data && j.data.candidates) || [];
            $('.list-group', pk.el).innerHTML = rows.length ? rows.map(function (c, i) { return '<button type="button" class="list-group-item list-group-item-action sp-mention-item' + (i === 0 ? ' active' : '') + '" data-id="' + R.escapeHtml(c.id) + '" data-kind="' + R.escapeHtml(c.kind) + '" data-name="' + R.escapeHtml(c.name) + '"><span class="fw-semibold">' + (pk.kind === 'emoji' ? '' : '@') + R.escapeHtml(c.name) + '</span>' + (c.kind === 'agent' ? '<span class="badge bg-soft-info text-info ms-2 sp-mention-chip">agent</span>' : '<span class="fs-12 text-muted ms-2">' + R.escapeHtml(c.hint || '') + '</span>') + '</button>'; }).join('') : '<div class="list-group-item text-muted fs-12">Nothing matches.</div>';
        });
    }
    function closePicker() { if (S.picker) { S.picker.el.remove(); } S.picker = null; }
    function pick(it) {
        var pk = S.picker; if (!pk) { return; }
        var box = pk.box, id = it.dataset.id, kind = it.dataset.kind, name = it.dataset.name;
        closePicker();
        var runs = R.runsOf(box); var text = R.plainOf(runs);
        var trigStart = Math.max(0, pk.start - pk.trigger.length); var typedLen = pk.trigger.length + pk.q.length;
        var before = R.splitRuns(runs, trigStart)[0], afterRuns = R.splitRuns(runs, trigStart + typedLen)[1];
        var run;
        if (kind === 'emoji') { run = R.mkRun(name + ' ', {}); }
        else { run = R.mkRun('@' + name, {}); run.type = 'mention'; run.mention = { type: kind, id: id, name: name }; }
        var all = before.concat([run], kind === 'emoji' ? afterRuns : [R.mkRun(' ', {})].concat(afterRuns));
        box.innerHTML = all.map(function (r) {
            if (r.type === 'mention') { return '<span data-run class="rt-mention ed-mention" contenteditable="false" data-mention=\'' + R.escapeHtml(JSON.stringify(r.mention)) + '\'>' + R.escapeHtml(r.plain_text) + '</span>'; }
            return '<span data-run' + (r.annotations.bold ? ' class="ed-bold"' : '') + '>' + R.escapeHtml(r.text.content) + '</span>';
        }).join('');
        R.placeCaret(box, 'end');
    }
    // ---- attachments and send later ----
    function onClick(e) {
        var t = e.target;
        if (S.picker && !t.closest('.sp-mention-picker')) { closePicker(); }
        var mi = t.closest('.sp-mention-item'); if (mi) { e.preventDefault(); pick(mi); return; }
        var at = t.closest('.sp-composer-attach'); if (at) { var fi = $('.sp-composer-file-input', at.closest('form')); if (fi) { fi.value = ''; fi.click(); } return; }
        var em = t.closest('.sp-composer-emoji'); if (em) { var box = $('.sp-composer-input', em.closest('form')); box.focus(); R.placeCaret(box, 'end'); document.execCommand('insertText', false, ':'); openPicker(box, 'emoji', ':'); return; }
        var lt = t.closest('.sp-composer-later'); if (lt) { var row = $('.sp-composer-later-row', lt.closest('form')); row.classList.toggle('d-none'); if (!row.classList.contains('d-none')) { var inp = $('input[type="datetime-local"]', row); inp.classList.add('sp-composer-later-at'); inp.focus(); } return; }
        var lc = t.closest('.sp-composer-later-clear'); if (lc) { var row2 = lc.closest('.sp-composer-later-row'); $('input', row2).value = ''; row2.classList.add('d-none'); return; }
        var fx = t.closest('.sp-file-chip-remove'); if (fx) { var chip = fx.closest('.sp-file-chip'); var form = chip.closest('form'); chip.remove(); syncAttachments(form); return; }
        var th = t.closest('.sp-open-thread'); if (th && window.htmx) { e.preventDefault(); var menu = th.closest('details'); if (menu) { menu.removeAttribute('open'); } var url = th.dataset.thread || th.getAttribute('href');
            if (window.matchMedia('(min-width: 1280px)').matches && window.SP && SP.rightPane) { htmx.ajax('GET', url, { target: '#right-pane-body', swap: 'innerHTML' }); } else { htmx.ajax('GET', url, { target: '#page-content', swap: 'innerHTML' }).then(function () { history.pushState({}, '', url); }); } return; }
        var rb = t.closest('.sp-remind-btn'); if (rb) { var tpl = $('#remind-template'); var row3 = rb.closest('.sp-message'); if (tpl && row3 && !$('.sp-remind-form', row3)) { var f = tpl.content.firstElementChild.cloneNode(true); $('input[name="message"]', f).value = rb.dataset.message; row3.appendChild(f); $('input[name="remind_at"]', f).focus(); } var m2 = rb.closest('details'); if (m2) { m2.removeAttribute('open'); } return; }
        var rc = t.closest('.sp-remind-cancel'); if (rc) { rc.closest('form').remove(); return; }
        var eb = t.closest('.sp-edit-btn'); if (eb) { var ef = $('.sp-edit-form', eb.closest('.sp-message')); if (ef) { ef.classList.remove('d-none'); $('textarea', ef).focus(); } var m3 = eb.closest('details'); if (m3) { m3.removeAttribute('open'); } return; }
        var ec = t.closest('.sp-edit-cancel'); if (ec) { ec.closest('form').classList.add('d-none'); return; }
        var an = t.closest('#channel-menu-announce'); if (an) { var af = $('#announce-form'); if (af) { af.classList.remove('d-none'); $('textarea', af).focus(); } var m4 = an.closest('details'); if (m4) { m4.removeAttribute('open'); } return; }
        if (!t.closest('details.sp-menu')) { $$('details.sp-menu[open]').forEach(function (d) { d.removeAttribute('open'); }); }
    }
    function syncAttachments(form) { var ids = $$('.sp-file-chip', form).map(function (c) { return c.dataset.id; }); $('.sp-composer-attachments', form).value = ids.join(','); var files = $('.sp-composer-files', form); files.classList.toggle('d-none', ids.length === 0); }
    function onFile(e) {
        var input = e.target; if (!input.classList || !input.classList.contains('sp-composer-file-input')) { return; }
        var form = input.closest('form'); var f = input.files && input.files[0]; if (!f) { return; }
        var fd = new FormData(); fd.append('file', f); fd.append('message', form.dataset.channel);
        api('/files/upload.php', fd).then(function (r) {
            if (!r.ok) { flash('warning', (r.json.error && r.json.error.message) || 'That file was refused.'); return; }
            var a = r.json.attachment; var files = $('.sp-composer-files', form);
            var chip = document.createElement('span'); chip.className = 'sp-file-chip'; chip.dataset.id = a.attachment_id;
            chip.innerHTML = (a.mime_type.indexOf('image/') === 0 ? '<img src="' + a.url + '" alt="">' : '<i class="feather-paperclip"></i>') + '<span>' + R.escapeHtml(a.filename) + '</span><button type="button" class="btn btn-link btn-sm p-0 sp-file-chip-remove" aria-label="Remove">✕</button>';
            files.appendChild(chip); syncAttachments(form);
        });
    }
    function onPaste(e) {
        var box = e.target.closest ? e.target.closest('.sp-composer-input') : null; if (!box) { return; }
        var files = e.clipboardData && e.clipboardData.files; var form = box.closest('form');
        if (files && files.length) { e.preventDefault(); var input = $('.sp-composer-file-input', form); var dt = new DataTransfer(); dt.items.add(files[0]); input.files = dt.files; input.dispatchEvent(new Event('change', { bubbles: true })); return; }
        var text = e.clipboardData ? e.clipboardData.getData('text/plain') : ''; if (text) { e.preventDefault(); document.execCommand('insertText', false, text); }
    }
    // ---- the thread pane ----
    function reveal() { $$('.sp-js[hidden]').forEach(function (el) { el.removeAttribute('hidden'); }); }
    function bindThread() { reveal(); var t = threadEl(); if (!t) { return; } var tm = $('.sp-thread-messages', t); if (tm) { tm.scrollTop = tm.scrollHeight; } }
    // ---- boot ----
    function boot() {
        if (!S.bound) {
            document.addEventListener('submit', onSubmit);
            document.addEventListener('keydown', onKeydown);
            document.addEventListener('input', onInput);
            document.addEventListener('click', onClick);
            document.addEventListener('click', loadEarlier);
            document.addEventListener('change', onFile);
            document.addEventListener('paste', onPaste);
            document.body.addEventListener('htmx:beforeRequest', onBeforeRequest);
            document.body.addEventListener('htmx:afterRequest', onAfterRequest);
            document.body.addEventListener('htmx:afterSwap', onAfterSwap);
            document.addEventListener('visibilitychange', function () { if (!document.hidden) { maybeRead(); } });
            S.bound = true;
        }
        reveal();
        var r = root(); if (r) {
            S.lastRead = 0;
            var w = wrap(); if (w) { w.addEventListener('scroll', maybeRead); }
            var focus = r.dataset.focus && r.dataset.focus !== '0' ? document.getElementById('message-row-' + r.dataset.focus) : null;
            var line = $('#unread-line');
            if (focus) { focus.scrollIntoView({ block: 'center' }); focus.classList.add('sp-focused'); }
            else if (line) { line.scrollIntoView({ block: 'center' }); }
            else { scrollBottom(); maybeRead(); }
            var box = $('#composer-box'); if (box && window.matchMedia('(min-width: 768px)').matches) { box.focus(); }
        }
        bindThread();
    }
    window.SPChannel = { boot: boot, after: after, hashes: hashes, threadAfter: threadAfter, threadHashes: threadHashes, markRead: markRead, send: send };
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
