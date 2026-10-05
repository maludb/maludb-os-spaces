// Headless Chromium proof of slice 6 (docs/build-specs/notify-search-wiki.md, "Proof", 8): at 375 the bell list, the feed and the search results stack (the groups as sections, the chips wrap), Saved is two tabs,
// the report's lists are cards; at 1280 the same screens fit and Saved is two columns; every control >= 44 px, scrollWidth = viewport, no console errors; JavaScript off searches and nudges.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8401';
const SHOTS = process.env.SHOTS || '/tmp/sp-shots-s6';
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
process.on('uncaughtException', async (e) => { try { if (lastPage) { await lastPage.screenshot({ path: `${SHOTS}/failure.png` }); } } catch {} console.log('  FAIL (uncaught) ' + e.message.split('\n')[0]); failed++; console.log(`${failed} FAILED (${passed} passed)`); process.exit(1); });
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
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button.btn, #page-content select.form-select, #page-content input.form-control, #page-content .sp-chip-x, #page-content summary')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5 && !e.classList.contains('btn-sm') && !e.classList.contains('btn-link') && !e.closest('[hidden]') && !e.closest('details:not([open]) > :not(summary)'); })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and control is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.slice(0, 6).join(', ') : ''));
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const go = async (page, path) => page.goto(BASE + path, { waitUntil: 'networkidle' });
const jsonOf = async (page, path) => page.evaluate(async (p) => (await (await fetch(p, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })).json()).data, path);
const xsOf = async (page, sel) => page.evaluate((s) => [...document.querySelectorAll(s)].map((c) => Math.round(c.getBoundingClientRect().x)), sel);
const reportPath = `/spaces/${W.product}/wiki/report`;
const searchPath = '/search?q=' + encodeURIComponent('rate limit in:#smoke-launch from:@marco has:link');

// ---- 375: Priya reads the bell, the feed, Saved and the search ----
{
  const { ctx, page, errors } = await session(26, PHONE);
  console.log('375 x 740 — Priya');
  await go(page, '/notifications');
  const unread0 = Number(await page.locator('#bell-count').innerText());
  ok(unread0 >= 4 && await page.locator('[id^="notification-row-"][id$="-chip"]').count() === unread0, `the bell list: ${unread0} unread rows, each with its chip`);
  ok(await page.locator('#header-bell-count').innerText() === String(unread0), 'the header bell shows the same count');
  const xs = await xsOf(page, '#notification-list > .card');
  ok(xs.length >= 4 && new Set(xs).size === 1, 'the rows stack in one column');
  await widthOK(page, 'the bell list', 375); await touch(page, 'the bell list');
  await page.screenshot({ path: `${SHOTS}/bell-375.png` });
  const first = page.locator('[id^="notification-row-"][id$="-read-btn"]').first();
  const rid = (await first.getAttribute('id')).match(/notification-row-(\d+)-read-btn/)[1];
  await first.click();
  await page.waitForFunction((n) => document.getElementById('bell-count') && Number(document.getElementById('bell-count').innerText) === n, unread0 - 1, { timeout: 8000 });
  ok(await page.locator(`#notification-row-${rid}-chip`).count() === 0 && await page.locator('#bell-count').innerText() === String(unread0 - 1), 'Mark read: the chip goes and the count drops');
  await page.waitForFunction((n) => { const e = document.getElementById('header-bell-count'); return n === 0 ? !e : e && e.innerText === String(n); }, unread0 - 1, { timeout: 8000 });
  ok(true, 'and the header bell follows');
  await page.locator('#notifications-read-all-btn').click();
  await page.waitForFunction(() => document.getElementById('bell-count') && document.getElementById('bell-count').innerText === '0', null, { timeout: 8000 });
  await page.waitForFunction(() => !document.getElementById('header-bell-count') && document.getElementById('notifications-read-all-btn') && document.getElementById('notifications-read-all-btn').disabled, null, { timeout: 8000 });
  ok(true, 'Mark all read: nothing unread, no count on the bell, the button spent');

  await go(page, '/activity');
  ok(await page.locator('[id^="activity-row-"]').count() >= 4, 'the feed has its rows');
  const fx = await xsOf(page, '#activity-list > .card');
  ok(new Set(fx).size === 1, 'the feed rows stack');
  await widthOK(page, 'the feed', 375); await touch(page, 'the feed');
  await page.screenshot({ path: `${SHOTS}/activity-375.png` });
  await page.locator('#activity-kind-reaction').click();
  await page.waitForFunction(() => location.search.includes('kind=reaction'), null, { timeout: 8000 });
  ok(await page.locator('[id^="activity-row-"]').count() >= 1 && await page.locator('[data-kind]:not([data-kind="reaction"])').count() === 0, 'a kind filter narrows the feed to reactions');

  await go(page, '/saved');
  ok(await page.locator('#saved-tabs').isVisible() && await page.locator('[id^="saved-row-"][id$="-unsave-btn"]').count() >= 1 && !(await page.locator('#saved-pane-reminders').isVisible()), 'Saved on a phone: two tabs, the saved messages first, the reminders hidden');
  await widthOK(page, 'Saved', 375); await touch(page, 'Saved');
  await page.locator('#saved-tab-reminders').click();
  await page.waitForFunction(() => location.search.includes('tab=reminders'), null, { timeout: 8000 });
  await page.waitForFunction(() => { const e = document.getElementById('saved-pane-reminders'); return e && e.getBoundingClientRect().width > 0; }, null, { timeout: 8000 }).catch(() => {});
  const rv = [await page.locator('#saved-pane-reminders').isVisible(), await page.locator('#saved-pane-saved').isVisible(), await page.locator('[id^="saved-reminder-"][id$="-done-btn"]').count()];
  ok(rv[0] && !rv[1] && rv[2] >= 1, 'the Reminders tab shows the reminders with Done (' + rv.join(', ') + ')');
  await widthOK(page, 'Saved, reminders', 375); await touch(page, 'Saved, reminders');
  await page.screenshot({ path: `${SHOTS}/saved-375.png` });

  await go(page, searchPath);
  ok(await page.locator('#search-chips .badge').count() === 3 && await page.locator('#search-group-message').count() === 1, 'the search shows its three chips and the Messages group');
  await widthOK(page, 'the search results', 375); await touch(page, 'the search results');
  await page.screenshot({ path: `${SHOTS}/search-375.png` });
  const chips = await page.evaluate(() => [...document.querySelectorAll('#search-chips .badge')].map((c) => Math.round(c.getBoundingClientRect().right) <= window.innerWidth));
  ok(chips.every(Boolean), 'the chips wrap inside the screen');
  await page.locator('#search-chip-has-remove').click();
  await page.waitForFunction(() => !document.getElementById('search-chip-has'), null, { timeout: 8000 });
  ok(!(await page.url()).includes('has=') && await page.locator('#search-chip-in').count() === 1, 'a chip\'s x removes it and re-runs the search (the others stay)');
  await go(page, '/search?q=' + encodeURIComponent('rate limit'));
  const gx = await xsOf(page, '#search-results > section');
  ok(gx.length >= 2 && new Set(gx).size === 1, 'the groups are sections stacked one over the other');
  ok(await page.locator('#search-group-page mark').count() >= 1 && await page.locator('#search-group-message mark').count() >= 1, 'the match is marked in the page and in the message');
  const before = page.url();
  await page.locator('#search-form-field-q').fill('rate limit has:link is:message');
  await sleep(900);
  ok(page.url() === before, 'typing does not search');
  await page.locator('#search-form-field-q').press('Enter');
  await page.waitForFunction(() => document.getElementById('search-chip-is'), null, { timeout: 8000 });
  ok(await page.locator('#search-chip-has').count() === 1 && await page.locator('#search-group-page').count() === 0, 'Enter searches: the modifiers become chips, only messages are left');
  await go(page, '/settings/');
  await widthOK(page, 'settings', 375); await touch(page, 'settings');
  ok(errors.length === 0, 'no console errors at 375' + (errors.length ? ' — ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- 375: Marco reads the report and nudges ----
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — Marco, the wiki report');
  await go(page, reportPath);
  ok(await page.locator('#wiki-list-expired .card').count() >= 1 && await page.locator('#wiki-list-broken .card').count() >= 1 && await page.locator('#wiki-list-duplicates .card').count() >= 1 && await page.locator('#wiki-list-unanswered .card').count() >= 1, 'the lists are cards');
  const cx = await xsOf(page, '#wiki-list-unverified .card');
  ok(cx.length >= 2 && new Set(cx).size === 1, 'the cards stack in one column');
  await widthOK(page, 'the report', 375); await touch(page, 'the report');
  await page.screenshot({ path: `${SHOTS}/report-375.png`, fullPage: true });
  const btn = page.locator(`#nudge-${W.fresh_a}`);
  ok(await btn.count() === 1 && (await btn.boundingBox()).height >= 43.5, 'Nudge beside a page whose owner is someone else, a full-size button');
  await btn.click();
  await page.waitForFunction(() => document.body.innerText.includes('Nudged') || document.body.innerText.includes('Already nudged'), null, { timeout: 8000 });
  ok(await page.evaluate(() => /Nudged|Already nudged/.test(document.body.innerText)), 'Nudge answers in words');
  ok(errors.length === 0, 'no console errors on the report' + (errors.length ? ' — ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- 1280 ----
{
  const { ctx, page, errors } = await session(26, DESK);
  console.log('1280 x 800');
  await go(page, '/notifications');
  await widthOK(page, 'the bell list', 1280); await touch(page, 'the bell list');
  await go(page, '/activity');
  await widthOK(page, 'the feed', 1280); await touch(page, 'the feed');
  await go(page, '/saved');
  ok(!(await page.locator('#saved-tabs').isVisible()) && await page.locator('#saved-pane-saved').isVisible() && await page.locator('#saved-pane-reminders').isVisible(), 'Saved: the two tabs become two columns');
  const [a, b] = await Promise.all([page.locator('#saved-pane-saved').boundingBox(), page.locator('#saved-pane-reminders').boundingBox()]);
  ok(b.x > a.x + a.width - 2, 'side by side');
  await widthOK(page, 'Saved', 1280); await touch(page, 'Saved');
  await page.screenshot({ path: `${SHOTS}/saved-1280.png` });
  await go(page, '/search?q=' + encodeURIComponent('rate limit'));
  await widthOK(page, 'the search', 1280); await touch(page, 'the search');
  await page.screenshot({ path: `${SHOTS}/search-1280.png` });
  await page.locator('#search-narrow summary').click();
  await page.locator('#search-form-field-is').selectOption('message');
  await page.locator('#search-form-btn').click();
  await page.waitForFunction(() => document.getElementById('search-chip-is'), null, { timeout: 8000 });
  ok(await page.locator('#search-group-page').count() === 0 && await page.locator('#search-group-message').count() === 1, 'the selects narrow the search the same way');
  ok(errors.length === 0, 'no console errors at 1280' + (errors.length ? ' — ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
  const m = await session(27, DESK);
  await go(m.page, reportPath);
  await widthOK(m.page, 'the report', 1280); await touch(m.page, 'the report');
  await m.page.screenshot({ path: `${SHOTS}/report-1280.png`, fullPage: true });
  ok(m.errors.length === 0, 'no console errors on the report at 1280' + (m.errors.length ? ' — ' + m.errors.slice(0, 3).join(' | ') : ''));
  await m.ctx.close();
}

// ---- JavaScript off: search and nudge ----
{
  const { ctx, page } = await session(27, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await go(page, '/search');
  await page.locator('#search-form-field-q').fill('rate limit is:page');
  await Promise.all([page.waitForURL(/\/search\?q=/, { timeout: 10000 }), page.locator('#search-form-btn').click()]);
  ok(await page.locator('#search-group-page').count() === 1 && await page.locator('#search-chip-is').count() === 1, 'the search form works without JavaScript');
  await go(page, reportPath);
  await Promise.all([page.waitForURL(/notice=nudged/, { timeout: 10000 }), page.locator(`#nudge-${W.fresh_b}`).click()]);
  ok(await page.locator('#notice-banner').innerText().then((t) => t.includes('Nudged')), 'Nudge works without JavaScript and lands back on the report with its notice');
  await ctx.close();
}
await browser.close();

console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed === 0 ? 0 : 1);
