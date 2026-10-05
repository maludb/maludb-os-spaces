// Headless Chromium proof of slice 7 (docs/build-specs/agents-in-spaces.md, "Proof", 9): at 375 the thinking placeholder and the reply in the thread, the proposal cards, the admin pages as cards;
// at 1280 the same screens fit; every control >= 44 px, scrollWidth = viewport, no console errors.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8401';
const SHOTS = process.env.SHOTS || '/tmp/sp-shots-s7';
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
const STATE = process.env.FAKE_KERNEL_STATE;
const kernelState = (change) => { const s = JSON.parse(fs.readFileSync(STATE, 'utf8')); change(s); fs.writeFileSync(STATE, JSON.stringify(s)); };
const { execSync } = require('node:child_process');
const workerPass = () => execSync('php bin/worker.php dispatches', { env: process.env, cwd: new URL('../../../', import.meta.url).pathname }).toString();
const threadPath = (id) => `/channels/${W.launch}/threads/${id}`;

// ---- 375: Priya in the thread, the picker, the proposals ----
{
  const { ctx, page, errors } = await session(26, PHONE);
  console.log('375 x 740 — Priya');
  await go(page, threadPath(W.running));
  const msgs = await jsonOf(page, threadPath(W.running));
  const ph = (msgs.messages || []).find((m) => m.kind === 'agent_pending');
  ok(!!ph && await page.locator(`#message-pending-${ph.message_id}`).count() === 1, 'the thread shows the placeholder row');
  const pend = page.locator(`#message-pending-${ph.message_id}`);
  ok((await pend.innerText()).includes('is thinking') && await pend.getAttribute('aria-live') === 'polite' && await pend.locator('.spinner-border').count() === 1, '"SMOKE Seamus is thinking…" with a spinner, aria-live polite');
  await widthOK(page, 'the thread with the placeholder', 375); await touch(page, 'the thread');
  await page.screenshot({ path: `${SHOTS}/thinking-375.png` });
  const dmPage = await ctx.newPage();
  await dmPage.goto(BASE + `/dm/${W.dm}`, { waitUntil: 'networkidle' });
  ok(await dmPage.locator('[id^="message-pending-"]').count() === 1 && (await dmPage.locator('[id^="message-pending-"]').innerText()).includes('is thinking') && await dmPage.locator('.sp-pending').count() === 1, 'the DM shows "SMOKE Seamus is thinking…" inline (a top-level row, no thread)');
  await widthOK(dmPage, 'the DM with the placeholder', 375);
  await dmPage.screenshot({ path: `${SHOTS}/dm-thinking-375.png` });
  kernelState((s) => { s.chat_mode = 'running'; s.run_pending = false; s.run_reply = 'SMOKE browser run done: the answer is **forty-two**.'; });
  workerPass();
  await page.waitForFunction((id) => !document.getElementById('message-pending-' + id) && document.body.innerText.includes('the answer is forty-two'), ph.message_id, { timeout: 12000 });
  ok(true, 'the worker finished the run: the poll replaced the placeholder with the reply, no reload');
  await dmPage.waitForFunction(() => document.querySelectorAll('.sp-pending').length === 0 && document.body.innerText.includes('the answer is forty-two'), null, { timeout: 12000 });
  ok(await dmPage.locator('text=1 reply').count() === 0, 'the DM\'s placeholder became the reply in place (the poll\'s hash swap), inline, no thread');
  await dmPage.screenshot({ path: `${SHOTS}/dm-reply-375.png` });
  await dmPage.close();
  ok(await page.locator('.badge.bg-soft-info', { hasText: 'agent' }).count() >= 1, 'the reply carries the agent chip');
  await widthOK(page, 'the thread with the reply', 375);
  await page.screenshot({ path: `${SHOTS}/reply-375.png` });

  await go(page, `/channels/${W.launch}`);
  const box = page.locator('.sp-composer-input').first();
  await box.click();
  await page.keyboard.type('@SMOKE Sea');
  await page.waitForSelector('.sp-mention-item', { timeout: 6000 });
  const item = page.locator('.sp-mention-item', { hasText: 'SMOKE Seamus' }).first();
  ok(await item.count() === 1 && await item.locator('.sp-mention-chip').innerText().then((t) => t === 'agent'), '`@` lists SMOKE Seamus with the agent chip');
  ok(await page.locator('.sp-mention-item', { hasText: 'Watcher' }).count() === 0, 'and not the Watcher');
  await page.screenshot({ path: `${SHOTS}/picker-375.png` });
  await page.keyboard.press('Escape');

  await go(page, '/proposals/');
  const cards = page.locator('[id^="proposal-card-"][id$="-kind"]');
  ok(await cards.count() >= 2, 'the proposal cards are listed');
  const xs = await xsOf(page, '#proposal-cards > .card');
  ok(xs.length >= 2 && new Set(xs).size === 1, 'the cards stack in one column');
  await widthOK(page, 'the proposals', 375); await touch(page, 'the proposals');
  await page.screenshot({ path: `${SHOTS}/proposals-375.png` });
  await page.locator(`#proposal-dismiss-${W.proposal_orphan}-reason`).fill('SMOKE browser: scratch on purpose');
  await page.locator(`#proposal-dismiss-${W.proposal_orphan}-btn`).click();
  await page.waitForSelector('#notice-banner', { timeout: 10000 });
  ok(await page.locator('#notice-banner').innerText().then((t) => t.includes('Dismissed')) && await page.locator(`#proposal-card-${W.proposal_orphan}`).count() === 0, 'Dismiss: the card leaves the open list with a notice');
  await go(page, '/proposals/?status=dismissed');
  ok(await page.locator(`#proposal-card-${W.proposal_orphan}-note`).innerText().then((t) => t.includes('scratch on purpose')), 'the Dismissed tab shows it with the reason');
  ok(errors.length === 0, 'no console errors at 375' + (errors.length ? ': ' + errors[0] : ''));
  await ctx.close();
}

// ---- 375: Marco accepts, with the picker ----
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — Marco');
  await go(page, '/proposals/');
  await page.locator(`#proposal-accept-${W.proposal_thread}-parent`).selectOption(W.parent);
  await Promise.all([page.waitForURL(new RegExp('/pages/' + W.draft), { timeout: 10000 }), page.locator(`#proposal-accept-${W.proposal_thread}-btn`).click()]);
  ok(page.url().includes('/pages/' + W.draft), 'Accept (naming a parent page) lands on the moved draft');
  await go(page, '/proposals/?status=accepted');
  ok(await page.locator(`#proposal-card-${W.proposal_thread}-status`).innerText() === 'accepted', 'it is under Accepted');
  ok(errors.length === 0, 'no console errors (Marco)' + (errors.length ? ': ' + errors[0] : ''));
  await ctx.close();
}

// ---- 375 and 1280: the admin pages ----
for (const [label, vp] of [['375 x 740', PHONE], ['1280 x 800', DESK]]) {
  const { ctx, page, errors } = await session(1, vp);
  console.log(label + ' — the admin');
  const n = vp.width;
  await go(page, '/admin/agents');
  const xs = await xsOf(page, '#agent-cards .card');
  ok(xs.length >= 2 && (n === 375 ? new Set(xs).size === 1 : new Set(xs).size === 2), n === 375 ? 'the agents are cards in one column' : 'the agents are cards in two columns');
  await widthOK(page, 'the agents', n); await touch(page, 'the agents');
  await page.screenshot({ path: `${SHOTS}/agents-${n}.png` });
  await go(page, '/admin/dispatches');
  ok(await page.locator('[id^="dispatch-row-"][id$="-status"]').count() >= 3, 'the dispatches are rows (cards)');
  await widthOK(page, 'the dispatches', n); await touch(page, 'the dispatches');
  await page.screenshot({ path: `${SHOTS}/dispatches-${n}.png` });
  await go(page, '/admin/connections');
  ok(await page.locator('#share-pages_index').count() === 1 && await page.locator('#share-page_markdown').count() === 1, 'the two shares');
  await widthOK(page, 'the connections', n); await touch(page, 'the connections');
  if (n === 375) {
    await go(page, '/admin/dispatches?status=failed');
    const retry = page.locator(`#dispatch-row-${W.failed_dispatch}-retry-btn`);
    ok(await retry.count() === 1, 'Retry is on the failed dispatch');
    await Promise.all([page.waitForURL(/notice=retried/, { timeout: 10000 }), retry.click()]);
    ok(await page.locator('#notice-banner').innerText().then((t) => t.includes('Back in the queue')), 'Retry lands with its notice');
    await go(page, `/admin/dispatches?status=pending`);
    ok(await page.locator(`#dispatch-row-${W.failed_dispatch}`).count() === 1, 'the retried dispatch is pending again');
  }
  await go(page, '/proposals/?status=accepted');
  await widthOK(page, 'the proposals', n);
  ok(errors.length === 0, `no console errors (${label})` + (errors.length ? ': ' + errors[0] : ''));
  await ctx.close();
}
await browser.close();

console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed === 0 ? 0 : 1);
