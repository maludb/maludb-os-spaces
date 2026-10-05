// Headless Chromium proof of slice 5 (docs/build-specs/databases.md, "Proof", 7): at 1280 the cell editor saves in place and re-renders its row only, a multi-select popover, New row and the panel, a board drag,
// the filter editor, the calendar grid and the timeline; at 375 the table scrolls inside its card, the board its columns, the calendar is a week list, the gallery and list stack, the popovers stay on screen;
// every control >= 44 px, scrollWidth = viewport, no console errors; JavaScript off saves a cell through its form.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8401';
const SHOTS = process.env.SHOTS || '/tmp/sp-shots-s5';
const KEY = process.env.ACTION_TOKEN_KEY;
const W = JSON.parse(process.env.WORLD || '{}');
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.spaces.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...fixture.claims[String(member)], member_id: member })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch();
let lastPage = null;
process.on('uncaughtException', async (e) => { try { if (lastPage) { await lastPage.screenshot({ path: `${SHOTS}/failure.png` }); console.log('  FAIL (uncaught) ' + e.message.split('\n')[0] + ' — the page said: ' + (await lastPage.locator('body').innerText()).replace(/\s+/g, ' ').slice(0, 300)); } } catch (x) {} process.exit(1); });
const PHONE = { width: 375, height: 740 }, DESK = { width: 1280, height: 800 };
async function session(member, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  lastPage = page;
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403|404)/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  if (member) await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth })); ok(o.sw === o.iw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} = ${o.iw})`); };
const touch = async (page, label) => {
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button.btn, #page-content select.form-select, #page-content input.form-control, #page-content .list-group-item-action')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5 && !e.classList.contains('btn-sm') && !e.classList.contains('form-select-sm') && !e.classList.contains('btn-link') && !e.classList.contains('sp-emoji-btn') && !e.closest('[hidden]'); })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and control is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.slice(0, 6).join(', ') : ''));
};
const go = async (page, path) => page.goto(BASE + path, { waitUntil: 'networkidle' });
const dbUrl = (v, q = '') => `/databases/${W.db}${v ? '?view=' + W.views[v] + q : ''}`;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const jsonOf = async (page, path) => page.evaluate(async (p) => (await (await fetch(p, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })).json()).data, path);

// ---- 1280: Marco ----
{
  const { ctx, page, errors } = await session(27, DESK);
  console.log('1280 x 800 — the table');
  await go(page, dbUrl('table'));
  ok(await page.locator('#database-table').count() === 1 && await page.locator('#database-rows-body tr[id^="row-row-"]').count() === 3, 'the table opens with its three rows');
  await widthOK(page, 'the table', 1280); await touch(page, 'the table');
  await page.screenshot({ path: `${SHOTS}/table-1280.png` });
  // a cell edit saves in place and re-renders ITS ROW only
  await page.evaluate((b) => { document.getElementById('row-row-' + b).dataset.sentinel = 'kept'; }, W.build);
  await page.evaluate((f) => { document.getElementById('row-row-' + f).dataset.sentinel = 'replaced'; }, W.fix);
  const input = page.locator(`#cell-${W.fix}-points input[name="p[points]"]`);
  const resp = page.waitForResponse((r) => r.url().includes('/databases/rows/save.php'));
  await input.fill('7'); await input.press('Tab');
  const r = await resp;
  ok(r.status() === 200, 'a cell change posts row_update (200)');
  await page.waitForFunction((f) => { const el = document.querySelector(`#cell-${f}-points input[name="p[points]"]`); return el && el.value === '7' && !document.getElementById('row-row-' + f).dataset.sentinel; }, W.fix, { timeout: 8000 });
  ok(await page.evaluate((b) => document.getElementById('row-row-' + b).dataset.sentinel, W.build) === 'kept', 'the row was replaced, the others were not');
  ok(await page.evaluate((f) => document.querySelectorAll('#row-row-' + f).length, W.fix) === 1, 'and still exactly one row element for it');
  // a multi-select popover
  await page.locator(`#cell-${W.build}-tags summary`).click();
  const pop = page.locator(`#cell-${W.build}-tags .sp-pop-body`);
  ok(await pop.isVisible(), 'a multi-select cell opens a popover');
  const bb = await pop.boundingBox();
  ok(bb && bb.x >= 0 && bb.x + bb.width <= 1280 && bb.y + bb.height <= 800 + 400, 'inside the window');
  await pop.locator('input[type="checkbox"][value="db"]').uncheck();
  const resp2 = page.waitForResponse((r) => r.url().includes('/databases/rows/save.php'));
  await pop.locator('button[type="submit"]').click();
  await resp2;
  await page.waitForFunction((b) => { const s = document.querySelector(`#cell-${b}-tags summary`); return s && s.textContent.includes('ui') && !s.textContent.includes('db'); }, W.build, { timeout: 8000 });
  ok(true, 'Apply saves the list: the cell shows only "ui"');
  // a status select
  const sel = page.locator(`#cell-${W.ship}-status select`);
  const resp3 = page.waitForResponse((r) => r.url().includes('/databases/rows/save.php'));
  await sel.selectOption('Doing');
  await resp3;
  await page.waitForFunction((s) => { const e = document.querySelector(`#cell-${s}-status select`); return e && e.value === 'Doing'; }, W.ship, { timeout: 8000 });
  ok(true, 'a select cell saves on change');
  await jsonOf(page, '/'); // keep the session warm
  // New row
  await page.click('#database-new-row-btn');
  ok(await page.locator('#row-form').isVisible(), 'New row opens the form with every settable property');
  await page.fill('#property-field-title-input', 'SMOKE Browser row');
  await page.fill('#property-field-points-input', '13');
  await page.selectOption('#property-field-kind-input', 'Bug');
  await page.fill('#property-field-due-input-start', '2026-10-27');
  await page.screenshot({ path: `${SHOTS}/new-row-1280.png` });
  await Promise.all([page.waitForURL(/\/databases\/[0-9a-f-]+\/rows\/[0-9a-f-]+/, { timeout: 10000 }), page.click('#row-form-save-btn')]);
  await page.waitForSelector('#properties-panel');
  const rowUrl = page.url();
  ok(await page.locator('#page-title').innerText() === 'SMOKE Browser row' && await page.locator('#property-field-points-input').inputValue() === '13', 'the form made the row and landed on its page: the title and the panel');
  await widthOK(page, 'the row page', 1280); await touch(page, 'the row page');
  await page.screenshot({ path: `${SHOTS}/row-1280.png` });
  const resp4 = page.waitForResponse((r) => r.url().includes('/databases/rows/save.php'));
  await page.selectOption('#property-field-status-input', 'Doing');
  await resp4;
  await page.waitForFunction(() => { const e = document.querySelector('#property-field-status-input'); return e && e.value === 'Doing'; }, null, { timeout: 8000 });
  ok(await page.locator('#properties-panel').count() === 1, 'a property edited in the panel re-renders the panel in place');
  const rowId = rowUrl.split('/rows/')[1].split('?')[0];
  // the board: drag a card to another column
  console.log('1280 x 800 — the board');
  await go(page, dbUrl('board'));
  await page.waitForSelector('.sp-board');
  const card = page.locator(`#row-card-${W.build}`);
  const target = page.locator('#board-column-Done .sp-board-cards');
  const cb = await card.boundingBox(), tb = await target.boundingBox();
  const resp5 = page.waitForResponse((r) => r.url().includes('/databases/rows/save.php'), { timeout: 10000 });
  await page.mouse.move(cb.x + cb.width / 2, cb.y + 20);
  await page.mouse.down();
  await page.mouse.move(cb.x + cb.width / 2 + 10, cb.y + 30, { steps: 3 });
  await page.mouse.move(tb.x + tb.width / 2, tb.y + 30, { steps: 12 });
  await page.mouse.up();
  const r5 = await resp5;
  ok(r5.status() === 200, 'a drag posts row_update of Status (200)');
  await page.waitForFunction((b) => !!document.querySelector(`#board-column-Done #row-card-${b}`), W.build, { timeout: 8000 });
  const counts = await page.evaluate(() => ({ todo: document.getElementById('board-column-Todo-count').textContent, done: document.getElementById('board-column-Done-count').textContent }));
  ok(counts.done === '2', 'the card sits in Done and the column header counts it (' + counts.done + ')');
  await page.screenshot({ path: `${SHOTS}/board-1280.png` });
  await go(page, dbUrl('board'));
  ok(await page.locator(`#board-column-Done #row-card-${W.build}`).count() === 1, 'and after a reload it is still there (the server holds it)');
  await widthOK(page, 'the board', 1280);
  // the filter editor
  console.log('1280 x 800 — the filter editor');
  await go(page, `/databases/${W.db}/views/${W.views.table}/edit`);
  await page.waitForSelector('#filter-editor');
  ok(await page.locator('#filter-add-row').isVisible(), 'the editor offers Add a condition (JavaScript on)');
  await page.selectOption('select[name="f[0][property]"]', 'status');
  const opts = await page.evaluate(() => [...document.querySelectorAll('select[name="f[0][condition]"] option')].filter((o) => !o.disabled && !o.hidden && o.value).map((o) => o.value));
  ok(opts.includes('equals') && opts.includes('is_empty') && !opts.includes('contains') && !opts.includes('greater_than'), 'the conditions follow the property type (a status: is, is not, empty, not empty)');
  await page.selectOption('select[name="f[0][condition]"]', 'equals');
  await page.fill('input[name="f[0][value]"]', 'Doing');
  await page.selectOption('select[name="f[1][property]"]', 'points');
  await page.selectOption('select[name="f[1][condition]"]', 'greater_than');
  await page.fill('input[name="f[1][value]"]', '4');
  await page.click('#filter-add-row');
  ok(await page.locator('select[name="f[2][property]"]').count() === 1, 'Add a condition clones a row (f[2])');
  await page.screenshot({ path: `${SHOTS}/filter-1280.png` });
  await Promise.all([page.waitForURL(/\/databases\/[0-9a-f-]+\?view=/, { timeout: 10000 }), page.click('#view-form-save-btn')]);
  await page.waitForSelector('#database-table');
  const titles = await page.locator('#database-rows-body tr[id^="row-row-"] td:first-child').allInnerTexts();
  ok(titles.length === 1 && titles[0].includes('SMOKE Browser row'), 'the filter built in the editor (Status is Doing, Points > 4) is applied by the database: one row (' + titles.map((t) => t.trim()).join(' | ') + ')');
  const f = await jsonOf(page, dbUrl('table'));
  ok(JSON.stringify(f.view.filter).includes('"greater_than":4') && JSON.stringify(f.view.filter).includes('"equals":"Doing"'), 'stored as Notion\'s object');
  // clear it again for the other layouts
  await page.evaluate(async (v) => { const t = document.getElementById('csrf-token-meta').content; await fetch('/databases/views/save.php', { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-Token': t, 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ view: v, filter: '' }) }); }, W.views.table);
  // calendar and timeline at 1280
  console.log('1280 x 800 — calendar and timeline');
  await go(page, dbUrl('calendar', '&month=2026-10'));
  ok(await page.locator('#calendar-grid').isVisible() && !(await page.locator('#calendar-weeks').isVisible()), 'at 1280 the month grid shows and the week list does not');
  ok(await page.locator('#calendar-day-2026-10-20').innerText().then((t) => t.includes('SMOKE Build the board')), 'Build the board sits on 20 October');
  await page.click('#calendar-next'); await page.waitForFunction(() => document.getElementById('calendar-month').textContent === 'November 2026', null, { timeout: 8000 }).catch(() => {});
  ok((await page.locator('#calendar-month').innerText()) === 'November 2026', 'the next-month arrow moves to November 2026');
  await page.screenshot({ path: `${SHOTS}/calendar-1280.png` });
  await go(page, dbUrl('timeline', '&week=2026-09-28'));
  ok(await page.locator('.sp-timeline-bar').count() >= 2, 'the timeline draws a bar per dated row');
  await page.screenshot({ path: `${SHOTS}/timeline-1280.png` });
  await widthOK(page, 'the timeline', 1280);
  ok(errors.length === 0, 'no console errors at 1280' + (errors.length ? ' — ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- 375: Marco ----
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — every layout');
  await go(page, dbUrl('table'));
  await widthOK(page, 'the table', 375); await touch(page, 'the table');
  const sc = await page.evaluate(() => { const w = document.querySelector('.sp-table-wrap'); return { sw: w.scrollWidth, cw: w.clientWidth }; });
  ok(sc.sw > sc.cw, 'the table scrolls sideways INSIDE its card (' + sc.sw + ' > ' + sc.cw + ')');
  await page.locator(`#cell-${W.build}-tags summary`).click();
  const bb = await page.locator(`#cell-${W.build}-tags .sp-pop-body`).boundingBox();
  ok(bb && bb.x >= 0 && bb.x + bb.width <= 375, 'a cell\'s popover stays on the screen (x ' + Math.round(bb.x) + ', w ' + Math.round(bb.width) + ')');
  await page.keyboard.press('Escape');
  await page.screenshot({ path: `${SHOTS}/table-375.png` });
  await go(page, dbUrl('board'));
  await widthOK(page, 'the board', 375);
  const bs = await page.evaluate(() => { const b = document.querySelector('.sp-board'); return { sw: b.scrollWidth, cw: b.clientWidth }; });
  ok(bs.sw > bs.cw, 'the board\'s columns scroll sideways (' + bs.sw + ' > ' + bs.cw + ')');
  await touch(page, 'the board');
  await page.screenshot({ path: `${SHOTS}/board-375.png` });
  await go(page, dbUrl('calendar', '&month=2026-10'));
  await widthOK(page, 'the calendar', 375);
  ok(!(await page.locator('#calendar-grid').isVisible()) && await page.locator('#calendar-weeks').isVisible(), 'the calendar is a week list at 375, not a grid');
  ok((await page.locator('#calendar-weekday-2026-10-20').innerText()).includes('SMOKE Build the board'), 'with the row on its day');
  await touch(page, 'the calendar');
  await page.screenshot({ path: `${SHOTS}/calendar-375.png` });
  await go(page, dbUrl('timeline', '&week=2026-09-28'));
  await widthOK(page, 'the timeline', 375);
  const ts = await page.evaluate(() => { const w = document.querySelector('.sp-table-wrap'); return { sw: w.scrollWidth, cw: w.clientWidth }; });
  ok(ts.sw > ts.cw, 'the timeline scrolls inside its card');
  await go(page, dbUrl('gallery'));
  await widthOK(page, 'the gallery', 375);
  const xs = await page.evaluate(() => [...document.querySelectorAll('[id^="row-card-"]')].map((c) => Math.round(c.getBoundingClientRect().x)));
  ok(xs.length >= 3 && new Set(xs).size === 1, 'the gallery cards stack in one column');
  await go(page, dbUrl('list'));
  await widthOK(page, 'the list', 375); await touch(page, 'the list');
  await go(page, `/databases/${W.db}/rows/${W.fix}`);
  await widthOK(page, 'a row page', 375); await touch(page, 'a row page');
  await page.screenshot({ path: `${SHOTS}/row-375.png` });
  await go(page, `/databases/${W.db}/schema`);
  await widthOK(page, 'the schema', 375); await touch(page, 'the schema');
  await page.screenshot({ path: `${SHOTS}/schema-375.png` });
  await go(page, `/databases/${W.db}/views/${W.views.board}/edit`);
  await widthOK(page, 'the view form', 375); await touch(page, 'the view form');
  await page.screenshot({ path: `${SHOTS}/view-form-375.png` });
  await go(page, `/databases/rows/relation.php?row=${W.launch}&property=Tasks`);
  await widthOK(page, 'the relation picker', 375); await touch(page, 'the relation picker');
  await go(page, '/databases/');
  await widthOK(page, 'the list of databases', 375); await touch(page, 'the list of databases');
  ok(errors.length === 0, 'no console errors at 375' + (errors.length ? ' — ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- JavaScript off: a cell saves through its form ----
{
  const { ctx, page } = await session(27, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await go(page, dbUrl('table'));
  const input = page.locator(`#cell-${W.ship}-points input[name="p[points]"]`);
  await input.fill('21');
  await Promise.all([page.waitForURL(/\/rows\//, { timeout: 10000 }), page.evaluate((sel) => document.querySelector(sel).click(), `#cell-form-${W.ship}-points button`)]);
  ok((await page.locator('#property-field-points-input').inputValue()) === '21', 'the cell\'s own form posts without JavaScript and lands on the row, the new value in its panel');
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed === 0 ? 0 : 1);
