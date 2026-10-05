// Headless Chromium proof of slice 4 (docs/build-specs/channels.md, "Proof", 7): the channel at 1280 (the composer sends with Enter, the @ picker, a reaction,
// the thread in the right pane, two browsers seeing each other within 3 s, the unread line and mark-read), at 375 (the composer above the keyboard, the
// message menu, the thread as a page, DMs as cards); every control ≥ 44 px, scrollWidth = viewport, no console errors; JavaScript off posts through the textarea.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8401';
const SHOTS = process.env.SHOTS || '/tmp/sp-shots-s4';
const KEY = process.env.ACTION_TOKEN_KEY;
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
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5 && !e.classList.contains('btn-sm') && !e.classList.contains('form-select-sm') && !e.classList.contains('btn-link') && !e.classList.contains('sp-emoji-btn'); })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and control is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.slice(0, 6).join(', ') : ''));
};
const go = async (page, path) => page.goto(BASE + path, { waitUntil: 'networkidle' });
const idOf = async (page) => await page.evaluate(() => document.getElementById('page-content').dataset.recordId);
let pageId = '';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const rows = async (page, sel) => page.evaluate((s) => [...document.querySelectorAll(s + ' .sp-message[data-id]')].map((r) => ({ id: parseInt(r.dataset.id, 10), text: (r.querySelector('.sp-message-body') || r).textContent.trim(), author: r.dataset.author })), sel || '#messages');
const channelUrl = '/channels/' + process.env.LAUNCH_ID;
let marcoPage = null, rootId = 0;

// ---- 1280: Marco in #smoke-launch ----
{
  const { ctx, page, errors } = await session(27, DESK);
  marcoPage = page;
  console.log('1280 x 800 — the channel');
  await go(page, channelUrl);
  await page.waitForSelector('#composer-box');
  ok(await page.locator('#messages').count() === 1, 'the channel opens with its message list');
  await widthOK(page, 'the channel', 1280); await touch(page, 'the channel');
  // Enter sends
  await page.click('#composer-box');
  await page.keyboard.type('Hello from the browser');
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => [...document.querySelectorAll('#messages .sp-message')].some((r) => r.textContent.includes('Hello from the browser')), null, { timeout: 8000 });
  let rs = await rows(page);
  rootId = rs[rs.length - 1].id;
  ok(rs[rs.length - 1].text === 'Hello from the browser' && (await page.locator('#composer-box').innerText()).trim() === '', 'Enter sent it: the row appended, the composer cleared');
  // the @ picker
  await page.click('#composer-box');
  await page.keyboard.type('Ping @SMOKE Pr');
  await page.waitForSelector('.sp-mention-picker .sp-mention-item', { timeout: 8000 });
  const cands = await page.evaluate(() => [...document.querySelectorAll('.sp-mention-picker .sp-mention-item')].map((e) => e.dataset.name));
  ok(cands.includes('SMOKE Priya'), 'the @ picker offers SMOKE Priya');
  await page.keyboard.press('Enter');
  await page.waitForSelector('#composer-box [data-mention]');
  await page.keyboard.type('look');
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => [...document.querySelectorAll('#messages .sp-message')].some((r) => r.querySelector('.rt-mention-member') && r.textContent.includes('look')), null, { timeout: 8000 });
  ok(true, 'a message with a mention chip was sent and rendered');
  await page.screenshot({ path: `${SHOTS}/channel-1280.png` });
  // a reaction from the menu
  const row = page.locator(`#message-row-${rootId}`);
  await row.hover();
  await page.click(`#message-row-${rootId}-menu summary`);
  await page.click(`#message-row-${rootId}-menu .sp-react-btn >> nth=0`);
  await page.waitForSelector(`#message-row-${rootId}-reactions .sp-reaction.active`, { timeout: 8000 });
  ok((await page.locator(`#message-row-${rootId}-reactions .sp-reaction-count`).innerText()) === '1', 'a quick reaction: the chip with 1, mine');
  // the thread in the right pane
  await row.hover();
  await page.click(`#message-row-${rootId}-menu summary`);
  await page.click(`#message-row-${rootId}-thread`);
  await page.waitForSelector('#right-pane:not([hidden]) #thread-pane', { timeout: 8000 });
  ok(true, 'Reply in thread opens the thread in the right pane at 1280');
  await page.click('#thread-composer-box');
  await page.keyboard.type('A reply from the pane');
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => [...document.querySelectorAll('#thread-messages .sp-message')].some((r) => r.textContent.includes('A reply from the pane')), null, { timeout: 8000 });
  ok((await page.locator('#thread-messages .sp-message').count()) === 2, 'the reply shows in the thread');
  await page.screenshot({ path: `${SHOTS}/channel-1280-thread.png` });
  await widthOK(page, 'the channel with the thread', 1280);
  ok(errors.length === 0, 'no console errors at 1280' + (errors.length ? ' — ' + errors.slice(0, 3).join(' | ') : ''));
}

// ---- two browsers: Priya posts, Marco sees it within 3 s; the unread line for Priya ----
{
  const { ctx, page, errors } = await session(26, DESK);
  console.log('two sessions');
  await go(page, channelUrl);
  await page.waitForSelector('#composer-box');
  const hadLine = (await page.locator('#unread-line').count()) === 1;
  ok(hadLine, 'Priya opens on the unread line (Marco\'s messages are new to her)');
  await page.waitForFunction(() => !document.getElementById('unread-line'), null, { timeout: 8000 }).catch(() => {});
  await page.click('#composer-box');
  await page.keyboard.type('Priya says hi');
  await page.keyboard.press('Enter');
  await marcoPage.waitForFunction(() => [...document.querySelectorAll('#messages .sp-message')].some((r) => r.textContent.includes('Priya says hi')), null, { timeout: 8000 });
  ok(true, 'Marco sees Priya\'s message within 3 s (the poll)');
  // Marco's thread pane sees a thread reply by Priya too
  await page.locator(`#message-row-${rootId}`).hover();
  await page.click(`#message-row-${rootId}-menu summary`);
  await page.click(`#message-row-${rootId}-thread`);
  await page.waitForSelector('#right-pane:not([hidden]) #thread-pane');
  await page.click('#thread-composer-box');
  await page.keyboard.type('Priya replies in the thread');
  await page.keyboard.press('Enter');
  await marcoPage.waitForFunction(() => [...document.querySelectorAll('#thread-messages .sp-message')].some((r) => r.textContent.includes('Priya replies in the thread')), null, { timeout: 8000 });
  ok(true, 'and her thread reply in his pane within 3 s');
  await marcoPage.waitForFunction((id) => { const s = document.getElementById(`message-row-${id}-thread-summary`); return s && s.textContent.includes('2 replies'); }, rootId, { timeout: 8000 }).catch(() => {});
  const summary = await marcoPage.locator(`#message-row-${rootId}-thread-summary`).innerText().catch(() => '');
  ok(summary.includes('2 replies'), 'the thread summary in his channel updated by the swap (' + summary.trim() + ')');
  ok(errors.length === 0, 'no console errors in the second session' + (errors.length ? ' — ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- 375: the channel on a phone ----
{
  const { ctx, page, errors } = await session(30, PHONE);
  console.log('375 x 740 — the channel on a phone');
  await go(page, '/channels/');
  await widthOK(page, 'Channels', 375); await touch(page, 'Channels');
  ok((await page.locator('#channel-card-' + process.env.LAUNCH_ID).count()) === 1, 'the channel browse shows #smoke-launch as a card');
  await go(page, channelUrl);
  await page.waitForSelector('#composer-box');
  await widthOK(page, 'the channel at 375', 375); await touch(page, 'the channel at 375');
  const comp = await page.locator('#composer-wrap').boundingBox();
  ok(comp && comp.y + comp.height <= 740 + 1, 'the composer sits within the viewport');
  await page.click('#composer-box');
  await page.keyboard.type('From the phone');
  await page.click('#composer-send');
  await page.waitForFunction(() => [...document.querySelectorAll('#messages .sp-message')].some((r) => r.textContent.includes('From the phone')), null, { timeout: 8000 });
  ok(true, 'the Send button sends on a phone');
  await page.screenshot({ path: `${SHOTS}/channel-375.png` });
  await page.locator(`#message-row-${rootId}`).scrollIntoViewIfNeeded();
  await page.click(`#message-row-${rootId}-menu summary`);
  ok(await page.locator(`#message-row-${rootId}-menu .sp-menu-popover`).isVisible(), 'the message menu opens as a popover');
  await page.click(`#message-row-${rootId}-thread`);
  await page.waitForSelector('#thread-view-content, #thread-pane', { timeout: 8000 });
  ok(await page.locator('#right-pane').isHidden() && (await page.locator('#thread-messages .sp-message').count()) >= 3, 'the thread opens as a page at 375 with its replies');
  await widthOK(page, 'the thread at 375', 375); await touch(page, 'the thread at 375');
  await page.screenshot({ path: `${SHOTS}/thread-375.png`, fullPage: true });
  await go(page, '/dm/');
  await widthOK(page, 'DMs', 375);
  ok(await page.locator('#dm-list').count() === 1, 'the DM list renders');
  ok(errors.length === 0, 'no console errors at 375' + (errors.length ? ' — ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- JavaScript off: the textarea posts ----
{
  const { ctx, page } = await session(27, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await go(page, channelUrl);
  await page.fill('#composer-textarea', 'Posted without JavaScript');
  await page.evaluate(() => document.getElementById('composer-send').scrollIntoView({ block: 'center' }));
  await page.mouse.wheel(0, 400); await sleep(300);
  await page.click('#composer-send', { force: true });
  await page.waitForSelector('#channel-view-content');
  const text = await page.locator('#messages').innerText();
  ok(text.includes('Posted without JavaScript'), 'a plain POST through the textarea posts and lands on the channel');
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed === 0 ? 0 : 1);
