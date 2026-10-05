/* databases.js — databases and views (Spaces slice 5). Vanilla JS; the screens work without it (every cell, field and filter row is a form that posts). Adds: dragging a board card to another
 * column (SortableJS → row_update of the group property), popovers that close on an outside tap and sit above a scrolling table, the people filter inside a picker, the schema form's
 * type-specific fields, the filter editor (conditions by property type, "Add a condition"). Idempotent across HTMX navigations. */
(function () {
    'use strict';
    if (window.SPDatabases) { window.SPDatabases.boot(); return; }
    var S = { sortables: [], bound: false };
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function csrf() { var m = $('#csrf-token-meta'); return m ? m.content : ''; }
    function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
    function flash(kind, text) { var f = $('#flash'); if (!f) { return; } f.innerHTML = '<div class="alert alert-' + kind + ' alert-dismissible fade show" role="alert">' + esc(text) + '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>'; }
    function post(url, data) {
        var p = new URLSearchParams();
        Object.keys(data).forEach(function (k) { if (data[k] !== undefined && data[k] !== null) { p.append(k, data[k]); } });
        return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf(), 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: p })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, json: j }; }); });
    }
    // ---- the board: drag a card to another column -----------------------------------------------------------------------------
    function recount(col) { var n = $$('.sp-card', col).length; var b = $('.sp-board-count', col.closest('.sp-board-col')); if (b) { b.textContent = n; } }
    function bindBoards() {
        S.sortables.forEach(function (s) { try { s.destroy(); } catch (e) { /* gone with its page */ } });
        S.sortables = [];
        if (!window.Sortable) { return; }
        $$('.sp-board[data-may="1"] .sp-board-cards').forEach(function (list) {
            S.sortables.push(new Sortable(list, {
                group: 'board', animation: 120, forceFallback: true, draggable: '.sp-card', ghostClass: 'sortable-ghost', delay: 120, delayOnTouchOnly: true,
                onEnd: function (evt) {
                    if (evt.from === evt.to) { return; }
                    var card = evt.item, board = card.closest('.sp-board'), key = board.dataset.key, data = { row: card.dataset.row };
                    data['p[' + key + ']'] = evt.to.dataset.value;
                    post('/databases/rows/save.php', data).then(function (r) {
                        if (!r.ok) {
                            var ref = evt.from.children[evt.oldIndex] || null;
                            evt.from.insertBefore(card, ref);
                            flash('danger', (r.json.error && r.json.error.message) || 'That card could not be moved.');
                        }
                        recount(evt.from); recount(evt.to);
                    }).catch(function () { evt.from.appendChild(card); recount(evt.from); recount(evt.to); flash('danger', 'That card could not be moved.'); });
                }
            }));
        });
    }
    // ---- popovers ---------------------------------------------------------------------------------------------------------------
    function placeFixed(d) {
        var body = $('.sp-pop-body', d); if (!body) { return; }
        if (!d.closest('.sp-table-wrap')) { body.style.position = ''; body.style.left = ''; body.style.top = ''; return; }
        var r = d.getBoundingClientRect();
        body.style.position = 'fixed'; body.style.left = Math.max(8, Math.min(r.left, window.innerWidth - body.offsetWidth - 8)) + 'px'; body.style.top = (r.bottom + 4) + 'px';
    }
    function closeOthers(except) { $$('details.sp-pop[open]').forEach(function (d) { if (d !== except && !d.contains(except || document.body)) { d.removeAttribute('open'); } }); }
    // ---- the schema form: only the fields of the chosen type ------------------------------------------------------------------
    function typeFields() {
        var sel = $('#property-add-field-type'); if (!sel) { return; }
        var apply = function () { $$('.sp-type-fields').forEach(function (f) { f.hidden = (f.dataset['for'] || '').split(' ').indexOf(sel.value) < 0; }); };
        if (!sel.dataset.bound) { sel.dataset.bound = '1'; sel.addEventListener('change', apply); }
        apply();
    }
    // ---- the filter editor: conditions by the property's type; no value box for is-empty and the time ones ---------------------
    var NOVALUE = ['is_empty', 'is_not_empty', 'past_week', 'past_month', 'past_year', 'this_week', 'next_week', 'next_month', 'next_year'];
    function syncFilterRow(row) {
        var prop = $('.sp-filter-prop', row), cond = $('.sp-filter-cond', row), val = $('.sp-filter-value', row); if (!prop || !cond) { return; }
        var type = (prop.options[prop.selectedIndex] || {}).dataset ? prop.options[prop.selectedIndex].dataset.type : '';
        $$('option', cond).forEach(function (o) { if (!o.value) { return; } var types = (o.dataset.types || '').split(' '); o.hidden = !!type && types.indexOf(type) < 0; o.disabled = o.hidden; });
        var cur = cond.options[cond.selectedIndex]; if (cur && cur.disabled) { cond.value = ''; }
        if (val) { val.hidden = NOVALUE.indexOf(cond.value) >= 0; val.parentNode.hidden = val.hidden; }
    }
    function filterEditor() {
        var rows = $('#filter-rows'); if (!rows) { return; }
        $$('.sp-filter-row').forEach(syncFilterRow);
        var add = $('#filter-add-row');
        if (add) {
            add.hidden = false;
            if (!add.dataset.bound) {
                add.dataset.bound = '1';
                add.addEventListener('click', function () {
                    var all = $$('.sp-filter-row', rows), last = all[all.length - 1], n = all.length, c = last.cloneNode(true);
                    $$('select, input', c).forEach(function (el) { el.name = el.name.replace(/\[\d+\]\[(property|condition|value)\]$/, '[' + n + '][$1]'); if (el.tagName === 'INPUT') { el.value = ''; } else { el.selectedIndex = 0; } });
                    c.id = 'filter-row-f' + n; rows.appendChild(c); syncFilterRow(c);
                });
            }
        }
    }
    // ---- delegated events (bound once) ----------------------------------------------------------------------------------------
    function bindOnce() {
        if (S.bound) { return; }
        S.bound = true;
        document.addEventListener('toggle', function (e) { var d = e.target; if (d && d.matches && d.matches('details.sp-pop')) { if (d.open) { closeOthers(d); placeFixed(d); } } }, true);
        document.addEventListener('click', function (e) { if (!e.target.closest('details.sp-pop')) { closeOthers(null); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeOthers(null); } });
        document.addEventListener('input', function (e) {
            var f = e.target; if (f.classList && f.classList.contains('sp-pop-filter')) {
                var q = f.value.trim().toLowerCase();
                $$('[data-name]', f.closest('.sp-pop-body')).forEach(function (l) { l.hidden = q !== '' && (l.dataset.name || '').indexOf(q) < 0; });
            }
        });
        document.addEventListener('change', function (e) { var t = e.target; if (t.classList && (t.classList.contains('sp-filter-prop') || t.classList.contains('sp-filter-cond'))) { syncFilterRow(t.closest('.sp-filter-row')); } });
        window.addEventListener('scroll', function (e) { if (e.target && e.target.closest && e.target.closest('.sp-pop-body')) { return; } $$('details.sp-pop[open]').forEach(placeFixed); }, true);   // a popover over a scrolling table follows its cell
        window.addEventListener('load', function () { bindBoards(); });         // this script runs before the shell's SortableJS has loaded on a full page load
        document.body.addEventListener('htmx:afterSettle', function () { boot(); });
        document.body.addEventListener('rowChanged', function () { /* a cell or panel saved: the server re-rendered what changed */ });
    }
    function boot() { bindOnce(); bindBoards(); typeFields(); filterEditor(); $$('details.sp-pop[open]').forEach(placeFixed); }
    window.SPDatabases = { boot: boot };
    boot();
})();
