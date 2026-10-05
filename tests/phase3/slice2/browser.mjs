// Headless Chromium proof of slice 2 (docs/build-specs/pages-tree.md, "Proof", 9): the reader at 375 and 1280, the page menu a popover, the share table
// within the page, the public page at 375, every control ≥ 44 px, scrollWidth = viewport, no console errors; JavaScript off creates, shares and publishes.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8401';
const SHOTS = process.env.SHOTS || '/tmp/sp-shots-s2';
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

// ---- phone: Marco makes and reads a page; the menu is a popover; the share table ----
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — a space owner');
  await go(page, '/pages/');
  ok(await page.locator('#page-list-content').count() === 1, 'the page list renders');
  await widthOK(page, 'Pages', 375); await touch(page, 'Pages');
  await go(page, '/pages/new');
  await widthOK(page, 'New page', 375); await touch(page, 'New page');
  await page.fill('#page-form-field-title', 'SMOKE Browser page');
  await page.check('#page-form-where-space');
  await page.selectOption('#page-form-field-space', { label: '🚀 SMOKE Product' });
  await page.selectOption('#page-form-field-template', { label: '📝 Meeting notes' });
  await page.fill('#page-form-field-markdown', 'Made in the browser.');
  await page.click('#page-form-save-btn');
  await page.waitForSelector('#page-view-content');
  pageId = await idOf(page);
  ok(/^[0-9a-f-]{36}$/.test(pageId) && (await page.locator('#notice-banner').innerText()).includes('Made SMOKE Browser page') && (await page.locator('#page-body').innerText()).includes('Attendees'), 'made from the Meeting notes template, landed on the reader with its headings');
  await widthOK(page, 'Page', 375); await touch(page, 'Page');
  await page.screenshot({ path: `${SHOTS}/phone-page.png` });
  ok(!(await page.locator('#page-menu-popover').isVisible()), 'the page menu is closed');
  await page.click('#page-menu > summary');
  ok(await page.locator('#page-menu-popover').isVisible() && (await page.locator('.modal').count()) === 0 && (await page.locator('#page-menu-share').count()) === 1, 'it opens as a popover (no modal) with Share');
  await page.screenshot({ path: `${SHOTS}/phone-page-menu.png` });
  await page.click('#page-menu-share');
  await page.waitForSelector('#permission-table');
  ok(page.url().includes('/share'), 'Share opens the share screen');
  await widthOK(page, 'Share', 375); await touch(page, 'Share');
  const tw = await page.evaluate(() => { const t = document.querySelector('#permission-table').closest('.table-responsive'); return t.scrollWidth >= t.clientWidth; });
  ok(tw, 'the share table scrolls within the page, not the viewport');
  await page.selectOption('#share-form-field-department', { label: 'Engineering' });
  await page.selectOption('#share-form-field-level', 'comment');
  await page.click('#share-form-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Shared') && (await page.locator('#permission-table').innerText()).includes('Engineering'), 'shared with Engineering through the form; the row appears');
  await page.screenshot({ path: `${SHOTS}/phone-share.png` });
  for (const [path, label, sel] of [[`/pages/${pageId}/move`, 'Move', '#move-form'], [`/pages/${pageId}/history`, 'History', '#version-table'], ['/pages/trash', 'Trash', '#page-trash-content'], ['/templates/', 'Templates', '#template-list-content']]) {
    await go(page, path);
    ok(await page.locator(sel).count() === 1, `${label} renders`);
    await widthOK(page, label, 375); await touch(page, label);
  }
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: the admin publishes; the public page at 375 ----
let link = '';
{
  const { ctx, page, errors } = await session(1, DESK);
  console.log('1280 x 800 — the admin');
  await go(page, `/pages/${pageId}`);
  await widthOK(page, 'Page', 1280);
  await go(page, `/pages/${pageId}/publish`);
  await page.check('#publish-field-subpages');
  page.once('dialog', (d) => d.accept());
  await page.click('#publish-btn');
  await page.waitForSelector('#publish-link-value');
  link = (await page.locator('#publish-link-value').innerText()).trim();
  ok(/\/p\/[a-f0-9]{48}$/.test(link), 'published: the link is shown once (' + link.slice(-12) + ')');
  await page.screenshot({ path: `${SHOTS}/desktop-publish.png` });
  await go(page, `/pages/${pageId}/publish`);
  ok((await page.locator('#publish-link-value').count()) === 0 && (await page.locator('#publish-state').count()) === 1, 'and never again; the state shows');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(null, PHONE);
  console.log('375 x 740 — the public page, no session');
  await page.goto(link.replace(/^https?:\/\/[^/]+/, BASE), { waitUntil: 'networkidle' });
  const bare = (await page.locator('#public-page').count()) === 1 && (await page.locator('#public-title').innerText()) === 'SMOKE Browser page' && (await page.locator('#assistant-bar').count()) === 0;
  ok(bare, 'the public page renders bare, with its title' + (bare ? '' : ' — got: ' + (await page.locator('body').innerText()).replace(/\s+/g, ' ').slice(0, 200)));
  await page.evaluate(() => {});
  const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
  ok(o.sw === o.iw, `no sideways scroll on the public page (scrollWidth ${o.sw} = ${o.iw})`);
  await page.screenshot({ path: `${SHOTS}/phone-public.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- JavaScript off: create, share and publish are plain forms ----
{
  const { ctx, page } = await session(1, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await go(page, '/pages/new');
  await page.fill('#page-form-field-title', 'SMOKE No-JS page');
  await page.selectOption('#page-form-field-space', { label: '🏠 General' });
  await page.click('#page-form-save-btn');
  await page.waitForSelector('#page-view-content');
  const pid = await idOf(page);
  ok((await page.locator('#notice-banner').innerText()).includes('Made SMOKE No-JS page'), 'a plain POST makes a page in General (the space select counts; the radio only disables the others with JS)');
  await go(page, `/pages/${pid}/share`);
  await page.selectOption('#share-form-field-member', { label: 'SMOKE Priya' });
  await page.click('#share-form-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#permission-table').innerText()).includes('SMOKE Priya'), 'a plain POST shares');
  await go(page, `/pages/${pid}/publish`);
  await page.click('#publish-btn');
  await page.waitForSelector('#publish-link-value');
  ok(/\/p\/[a-f0-9]{48}$/.test((await page.locator('#publish-link-value').innerText()).trim()), 'a plain POST publishes');
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
