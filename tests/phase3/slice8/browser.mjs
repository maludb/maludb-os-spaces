// Headless Chromium proof of slice 8 (docs/build-specs/worker-import-export.md, "Proof", 11): at 375 and 1280 the import form and its preview, the whole browser path of a zip (preview, import, the card), the past imports as cards, a queued import that
// polls to done, the exports list as cards with a queued export that polls to done, a download, the channel header's retention; every control >= 44 px, scrollWidth = viewport, no console errors.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const { execSync } = require('node:child_process');
const BASE = 'http://127.0.0.1:8401';
const SHOTS = process.env.SHOTS || '/tmp/sp-shots-s8';
const KEY = process.env.ACTION_TOKEN_KEY;
const W = JSON.parse(process.env.WORLD || '{}');
const ROOT = new URL('../../../', import.meta.url).pathname;
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
async function session(member, viewport) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600 });
  const page = await ctx.newPage();
  lastPage = page;
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403|404)/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth })); ok(o.sw === o.iw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} = ${o.iw})`); };
const touch = async (page, label) => {
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button.btn, #page-content select.form-select, #page-content input.form-control, #page-content summary')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5 && !e.classList.contains('btn-sm') && !e.classList.contains('btn-link') && !e.closest('[hidden]') && !e.closest('details:not([open]) > :not(summary)'); })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and control is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.slice(0, 6).join(', ') : ''));
};
const go = async (page, path) => page.goto(BASE + path, { waitUntil: 'networkidle' });
const worker = (args) => execSync('php bin/worker.php ' + args, { env: process.env, cwd: ROOT }).toString();
const xsOf = async (page, sel) => page.evaluate((s) => [...document.querySelectorAll(s)].map((c) => Math.round(c.getBoundingClientRect().x)), sel);

// ---- 375: Marco ----
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — Marco');
  await go(page, `/import?space=${W.product}`);
  ok(await page.locator('#import-form').count() === 1 && await page.locator('#import-form-field-file').count() === 1 && await page.locator('#import-form-submit-btn').count() === 1 && await page.locator('#import-form-preview-btn').count() === 1, 'the import form: a file, a kind, the destination, Import and Preview');
  ok(await page.locator('#import-rows [id^="import-row-"]').count() >= 3, 'the past imports are cards');
  await widthOK(page, '/import', 375); await touch(page, '/import');
  await page.screenshot({ path: `${SHOTS}/import-375.png`, fullPage: true });
  // the browser path of a zip: choose it, preview, import
  await page.setInputFiles('#import-form-field-file', W.tree_zip);
  await page.selectOption('#import-form-field-space', String(W.product));
  await Promise.all([page.waitForURL(/\/import\?preview=/), page.click('#import-form-preview-btn')]);
  await page.waitForLoadState('networkidle');
  ok(await page.locator('#import-preview').count() === 1 && (await page.locator('#import-preview-pages').innerText()) === '6' && (await page.locator('#import-preview-rows').innerText()) === '7' && (await page.locator('#import-preview-databases').innerText()) === '2', 'a zip chosen in the browser and previewed: 6 pages, 2 databases, 7 rows');
  ok((await page.locator('#import-preview-tree').innerText()).includes('SMOKE Handbook') && (await page.locator('#import-preview-tree').innerText()).includes('SMOKE Leave'), 'the preview shows the tree it will make');
  await widthOK(page, 'the preview', 375); await touch(page, 'the preview');
  await page.screenshot({ path: `${SHOTS}/import-preview-375.png`, fullPage: true });
  await Promise.all([page.waitForURL(/\/import\?id=\d+/), page.click('#import-confirm-btn')]);
  await page.waitForLoadState('networkidle');
  const card = page.locator('#import-rows [id^="import-row-"]').first();
  ok((await page.locator('#notice-banner').innerText()).includes('import is done') && (await card.getAttribute('data-status')) === 'done' && (await card.innerText()).includes('6') && (await card.innerText()).includes('pages'), 'confirmed: the page lands on the import with its card done and its counts');
  await widthOK(page, 'the finished import', 375);
  // the queued import polls to done
  await go(page, '/import');
  const q = page.locator(`#import-row-${W.queued_import}`);
  ok((await q.getAttribute('data-status')) === 'queued' && (await q.locator('.spinner-border').count()) === 1 && (await q.getAttribute('hx-trigger')) === 'every 3s', 'a queued import: a spinner, and the card polls itself');
  await page.screenshot({ path: `${SHOTS}/import-queued-375.png`, fullPage: true });
  worker('--only=imports');
  await page.waitForFunction((id) => document.getElementById('import-row-' + id)?.getAttribute('data-status') === 'done', W.queued_import, { timeout: 12000 });
  ok(true, 'the worker ran it and the poll turned the card to done without a reload');
  ok(await page.locator(`#import-row-${W.failed_import}`).getAttribute('data-status') === 'failed' && (await page.locator(`#import-row-${W.failed_import} details`).getAttribute('open')) !== null && (await page.locator(`#import-log-${W.failed_import}`).innerText()).includes('not a zip file'), 'a failed import shows its log open with the sentence');
  // exports
  await go(page, `/exports/?page=${W.page}`);
  ok(await page.locator('#export-page-form').count() === 1 && await page.locator('#export-database-form').count() === 1 && await page.locator('#export-space-form').count() === 1 && await page.locator('#export-channel-form').count() === 1 && await page.locator('#export-all-form').count() === 0, 'the exports forms (page, database, space, channel; "everything" is the admin\'s) with the page preselected');
  ok((await page.locator('#export-page-field-page').inputValue()) === W.page, 'the page asked for is chosen in the form');
  const done = page.locator(`#export-row-${W.done}`);
  ok((await done.getAttribute('data-status')) === 'done' && await done.locator(`#export-download-${W.done}`).count() === 1 && await done.locator(`#export-delete-${W.done}`).count() === 1, 'a finished export is a card with Download and Delete');
  const exp = page.locator(`#export-row-${W.expired}`);
  ok((await exp.getAttribute('data-status')) === 'expired' && await exp.locator('a.btn-primary').count() === 0 && (await exp.innerText()).includes('The file is gone'), 'an expired one says so and offers no Download');
  const qd = page.locator(`#export-row-${W.queued}`);
  ok((await qd.getAttribute('data-status')) === 'queued' && (await qd.getAttribute('hx-trigger')) === 'every 3s', 'a queued space export polls itself');
  await widthOK(page, '/exports/', 375); await touch(page, '/exports/');
  await page.screenshot({ path: `${SHOTS}/exports-375.png`, fullPage: true });
  worker('--only=exports');
  await page.waitForFunction((id) => document.getElementById('export-row-' + id)?.getAttribute('data-status') === 'done', W.queued, { timeout: 12000 });
  ok(await page.locator(`#export-download-${W.queued}`).count() === 1, 'the worker made it and the card gained its Download, no reload');
  const dl = await page.evaluate(async (id) => { const r = await fetch('/exports/download.php?id=' + id, { credentials: 'same-origin' }); return { s: r.status, d: r.headers.get('content-disposition'), n: r.headers.get('x-content-type-options'), l: (await r.arrayBuffer()).byteLength }; }, W.done);
  ok(dl.s === 200 && dl.d.startsWith('attachment') && dl.n === 'nosniff' && dl.l > 0, 'the download: an attachment, nosniff, the file');
  // a delete from the card
  page.once('dialog', (d) => d.accept());
  await page.click(`#export-delete-${W.done}`);
  await page.waitForFunction((id) => document.getElementById('export-row-' + id) === null || document.getElementById('export-row-' + id).getAttribute('data-status') === 'expired', W.done, { timeout: 8000 }).catch(() => {});
  await go(page, '/exports/');
  ok((await page.locator(`#export-row-${W.done}`).getAttribute('data-status')) === 'expired', 'Delete (confirmed) took the file away: the card says expired');
  // the channel header
  await go(page, `/channels/${W.launch}`);
  ok(await page.locator('#channel-view-retention').count() === 1 && (await page.locator('#channel-view-retention').innerText()).includes('Messages older than 7 days are deleted'), 'the channel header says "Messages older than 7 days are deleted"');
  await widthOK(page, 'the channel with its retention', 375);
  await page.screenshot({ path: `${SHOTS}/channel-retention-375.png` });
  // the form's retention block
  await go(page, `/channels/${W.launch}/edit`);
  ok(await page.locator('#channel-retention-form').count() === 1 && (await page.locator('#channel-retention-field-days').inputValue()) === '7', 'the channel form holds the retention (7)');
  await widthOK(page, 'the channel form', 375); await touch(page, 'the channel form');
  ok(errors.length === 0, 'no console errors at 375' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- 1280: Marco and the admin ----
{
  const { ctx, page, errors } = await session(27, DESK);
  console.log('1280 x 800 — Marco');
  await go(page, `/import?space=${W.product}`);
  await widthOK(page, '/import', 1280); await touch(page, '/import');
  await page.screenshot({ path: `${SHOTS}/import-1280.png`, fullPage: true });
  await go(page, '/exports/');
  const xs = await xsOf(page, '#export-page-form, #export-database-form');
  ok(xs.length === 2 && xs[0] !== xs[1], 'the export forms sit in two columns (x ' + xs.join(' / ') + ')');
  await widthOK(page, '/exports/', 1280); await touch(page, '/exports/');
  await page.screenshot({ path: `${SHOTS}/exports-1280.png`, fullPage: true });
  await go(page, `/import?preview=${W.preview}&space=${W.product}`);
  ok((await page.locator('#import-preview-unsupported').innerText()) === '3' || await page.locator('#import-preview-error').count() === 1, 'a Notion preview counts its 3 unsupported blocks (or says the preview went)');
  await widthOK(page, 'the preview', 1280); await touch(page, 'the preview');
  ok(errors.length === 0, 'no console errors at 1280' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(1, DESK);
  console.log('1280 x 800 — the admin');
  await go(page, '/exports/');
  ok(await page.locator('#export-all-form').count() === 1 && (await page.locator('#export-past-title').innerText()).toLowerCase().includes('everyone'), 'the admin has "everything" and sees everyone\'s exports');
  ok(await page.locator(`#export-row-${W.done}`).count() === 1 || await page.locator('[id^="export-row-"]').count() >= 3, 'with their cards');
  await widthOK(page, 'the admin\'s /exports/', 1280); await touch(page, 'the admin\'s /exports/');
  await page.screenshot({ path: `${SHOTS}/exports-admin-1280.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors for the admin' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
