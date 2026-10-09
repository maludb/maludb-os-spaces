// Headless Chromium pass of the record picker in Spaces (docs/spaces-record-picker.md; the design system's picker-browser.mjs adapted): opens, focus, search, paging,
// keyboard pick, Enter in the search box, a filter bar that reloads on a pick, a pick after an HTMX navigation (one modal for the whole shell), the phone and dark mode.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8401';
const SHOTS = process.env.SHOTS || '/tmp/sp-shots-picker';
const KEY = process.env.ACTION_TOKEN_KEY;
const W = JSON.parse(process.env.WORLD || '{}');
const fixture = JSON.parse(fs.readFileSync(new URL('../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.spaces.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...fixture.claims[String(member)], member_id: member })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
fs.mkdirSync(SHOTS, { recursive: true });
const browser = await chromium.launch();
const errors = [];
const watch = (page) => { page.on('pageerror', (e) => errors.push('pageerror: ' + e.message)); page.on('console', (m) => { if (m.type() === 'error' && !/status of (401|403|404|422)/.test(m.text())) errors.push(m.text()); }); };
const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
const page = await ctx.newPage(); watch(page);
await page.goto(mint(27), { waitUntil: 'networkidle' });
const rows = () => page.$$('#record-picker .record-picker-row');
const open = async (id) => { await page.click(`#${id}-open`); await page.waitForSelector('#record-picker.show'); await page.waitForSelector('#record-picker .record-picker-row, #record-picker-empty'); await page.waitForFunction(() => document.activeElement.id === 'record-picker-search', null, { timeout: 3000 }).catch(() => {}); };
const closed = () => page.waitForSelector('#record-picker', { state: 'hidden' });

// ---- a new page: the parent (a page, by UUID)
await page.goto(BASE + '/pages/new', { waitUntil: 'networkidle' });
ok((await page.$$('#record-picker')).length === 1, 'one #record-picker in the shell');
await page.screenshot({ path: SHOTS + '/picker-1-form-desktop.png' });
await open('page-form-field-parent');
ok((await rows()).length === 25, 'the modal opens with the first page of pages (25 rows)');
await page.waitForFunction(() => document.activeElement.id === 'record-picker-search', null, { timeout: 3000 }).catch(() => {});
ok(await page.evaluate(() => document.activeElement.id === 'record-picker-search'), 'the search box has focus');
ok((await page.textContent('#record-picker-title')).trim() === 'Pages', 'the title is the field\'s: Pages');
ok(await page.evaluate(() => document.getElementById('record-picker-footer').classList.contains('d-none')), 'no New button where the field declares no create');
await page.type('#record-picker-search', 'SMOKE Bulk'); await page.waitForTimeout(700);
ok((await rows()).length === 25 && !!(await page.$('#record-picker-more')), 'typing "SMOKE Bulk" narrows to 25 rows with a Show more');
await page.screenshot({ path: SHOTS + '/picker-2-picker-desktop.png' });
await page.click('#record-picker-more'); await page.waitForFunction(() => document.querySelectorAll('#record-picker .record-picker-row').length === 50);
ok((await rows()).length === 50 && !!(await page.$('#record-picker-more')), 'Show more appends page 2 in place and offers the last 10 (the dialog keeps its focus)');
await page.fill('#record-picker-search', 'SMOKE Bulk 07'); await page.waitForTimeout(700);
ok((await rows()).length === 1, 'a narrower query: one row');
await page.keyboard.press('ArrowDown');
ok(await page.evaluate(() => document.activeElement.classList.contains('record-picker-row')), 'ArrowDown moves from the search box to the first row');
const label = await page.evaluate(() => document.activeElement.dataset.label), value = await page.evaluate(() => document.activeElement.dataset.value);
await page.keyboard.press('Enter'); await closed();
ok((await page.textContent('#page-form-field-parent-open')).trim() === label && (await page.inputValue('#page-form-field-parent')) === value && /^[0-9a-f-]{36}$/.test(value), `Enter picks the row: the button reads "${label}", the hidden input holds its UUID`);
await page.waitForFunction(() => document.activeElement.id === 'page-form-field-parent-open', null, { timeout: 3000 }).catch(() => {});
ok(await page.evaluate(() => document.activeElement.id === 'page-form-field-parent-open'), 'focus returns to the opener');
ok(await page.evaluate(() => !document.getElementById('page-form-field-parent-clear').classList.contains('d-none')), 'the Clear button appears');
await page.click('#page-form-field-parent-clear');
ok((await page.inputValue('#page-form-field-parent')) === '' && (await page.textContent('#page-form-field-parent-open')).trim() === '—', 'Clear empties the value and shows the placeholder');
// Enter in the search box picks the first match; the chosen row is marked active on reopening
await open('page-form-field-space'); await page.type('#record-picker-search', 'SMOKE Pick 0'); await page.waitForTimeout(700);
ok((await rows()).length === 9, 'spaces "SMOKE Pick 0": 9 rows');
await page.keyboard.press('Enter'); await closed();
ok((await page.textContent('#page-form-field-space-open')).trim() === 'SMOKE Pick 01' && (await page.inputValue('#page-form-field-space')) !== '', 'Enter in the search box picks the first match (SMOKE Pick 01)');
await open('page-form-field-space');
ok(await page.evaluate(() => document.querySelector('#record-picker .record-picker-row.active')?.dataset.label === 'SMOKE Pick 01'), 'reopened: the chosen row is marked active');
await page.keyboard.press('Escape'); await closed();
ok(await page.waitForFunction(() => document.activeElement.id === 'page-form-field-space-open', null, { timeout: 3000 }).then(() => true).catch(() => false), 'Escape closes and focus returns to the opener');
ok((await page.textContent('#page-form-field-space-open')).trim() === 'SMOKE Pick 01', 'the choice is unchanged after Escape');
// a query with no match names itself, escaped
await open('page-form-field-space'); await page.type('#record-picker-search', '<b>zzz'); await page.waitForTimeout(700);
ok((await page.textContent('#record-picker-empty')).includes('No space matches “<b>zzz”.') && !(await page.$('#record-picker-results b')), 'an empty result names the query, escaped');
await page.keyboard.press('Escape'); await closed();

// ---- a filter bar reloads on a pick
await page.goto(BASE + '/pages/', { waitUntil: 'networkidle' });
await open('page-filter-space'); await page.type('#record-picker-search', 'SMOKE Product'); await page.waitForTimeout(700);
await page.click('#record-picker .record-picker-row'); await closed();
await page.waitForFunction(() => location.search.includes('space='), null, { timeout: 5000 }).catch(() => {});
ok(await page.evaluate(() => location.search.includes('space=')), 'a filter bar: picking a space reloads the list (the hidden input\'s change reaches the form: ' + new URL(page.url()).search + ')');
await page.waitForSelector('#page-filter-space-open');
ok((await page.textContent('#page-filter-space-open')).includes('SMOKE Product'), 'and the new render keeps the chosen space\'s label');
await page.click('#page-filter-space-clear'); await page.waitForFunction(() => !location.search.includes('space=') || /space=(&|$)/.test(location.search), null, { timeout: 5000 }).catch(() => {});
ok(await page.evaluate(() => !/space=\d/.test(location.search)), 'Clear removes the filter');

// ---- after an HTMX navigation the one modal still works
await page.goto(BASE + '/pages/', { waitUntil: 'networkidle' });
await page.click('#page-list-add-btn'); await page.waitForSelector('#page-form-field-space-open');
ok((await page.$$('#record-picker')).length === 1, 'after an HTMX navigation (New page): still one modal');
await open('page-form-field-space');
ok((await rows()).length > 0, 'a field that arrived in a swap opens the picker');
await page.keyboard.press('Escape'); await closed();

// ---- a person for a channel (scoped by a param) and a space's members
await page.goto(BASE + `/channels/${W.leads_channel}/members`, { waitUntil: 'networkidle' });
await open('channel-member-add-field');
const names = await page.$$eval('#record-picker .record-picker-row', (r) => r.map((x) => x.dataset.label));
ok(names.length > 0 && !names.includes('SMOKE Priya'), 'channel members: who is already in is not offered (' + names.length + ' offered)');
await page.keyboard.press('Escape'); await closed();

// ---- the phone
const phone = await browser.newContext({ viewport: { width: 375, height: 740 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
const p2 = await phone.newPage(); watch(p2);
await p2.goto(mint(27), { waitUntil: 'networkidle' });
await p2.goto(BASE + '/pages/new', { waitUntil: 'networkidle' });
ok(await p2.evaluate(() => document.documentElement.scrollWidth <= 375), 'no horizontal scroll at 375px (a new page)');
const btn = await p2.evaluate(() => document.getElementById('page-form-field-space-open').getBoundingClientRect().height);
ok(btn >= 44, `the field's button is a touch target (${btn}px)`);
await p2.screenshot({ path: SHOTS + '/picker-3-form-phone.png' });
await p2.tap('#page-form-field-parent-open'); await p2.waitForSelector('#record-picker.show'); await p2.waitForSelector('#record-picker .record-picker-row');
await p2.type('#record-picker-search', 'SMOKE'); await p2.waitForTimeout(700);
const box = await p2.evaluate(() => { const r = document.querySelector('#record-picker .modal-content').getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; });
ok(box[0] >= 374 && box[1] >= 700, `the modal fills the phone screen (${box.join('x')})`);
const rowH = await p2.evaluate(() => document.querySelector('#record-picker .record-picker-row').getBoundingClientRect().height);
ok(rowH >= 44, `rows are touch targets (${Math.round(rowH)}px)`);
ok(await p2.evaluate(() => { const s = document.querySelector('#record-picker .record-picker-search').getBoundingClientRect(); return s.top >= 0 && s.top < 90; }), 'the search box is pinned at the top');
await p2.screenshot({ path: SHOTS + '/picker-4-picker-phone.png' });
await p2.evaluate(() => document.documentElement.classList.add('app-skin-dark')); await p2.waitForTimeout(250);
await p2.screenshot({ path: SHOTS + '/picker-5-picker-phone-dark.png' });
await p2.tap('#record-picker-close'); await p2.waitForSelector('#record-picker', { state: 'hidden' });
// ---- dark on a desktop
await page.goto(BASE + '/pages/new', { waitUntil: 'networkidle' });
await page.evaluate(() => document.documentElement.classList.add('app-skin-dark'));
await open('page-form-field-space'); await page.waitForTimeout(300);
await page.screenshot({ path: SHOTS + '/picker-6-picker-desktop-dark.png' });
ok(errors.length === 0, 'no page errors or console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
