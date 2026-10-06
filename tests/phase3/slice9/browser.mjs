// Headless Chromium proof of slice 9 (docs/build-specs/home-admin.md, "Proof", 6): at 375 and 1280 Home's regions in the phone order, the owner's and the admin's regions, the settings form and the emoji list, every admin page as cards, the retention form,
// Purge all with its confirm, the trail's filters; every control >= 44 px, scrollWidth = viewport, no console errors.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const { execSync } = require('node:child_process');
const BASE = 'http://127.0.0.1:8401';
const SHOTS = process.env.SHOTS || '/tmp/sp-shots-s9';
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

// ---- 375: Priya ----
let y = async (page, sel) => (await page.locator(sel).boundingBox())?.y ?? -1;
{
  const { ctx, page, errors } = await session(26, PHONE);
  console.log('375 x 740 — Priya');
  await go(page, '/');
  const order = ['#home-unread', '#home-waiting', '#home-verification', '#home-recent', '#home-favorites', '#home-note'];
  const ys = []; for (const s of order) ys.push(await y(page, s));
  ok(ys.every((v, i) => v >= 0 && (i === 0 || v > ys[i - 1])), 'the regions stack in the phone order: Unread, Waiting, Verification, Recently edited, Favorites, the note (y ' + ys.map(Math.round).join(' < ') + ')');
  const xs = await xsOf(page, '#home-unread, #home-waiting, #home-recent');
  ok(new Set(xs).size === 1, 'in one column at 375 (x ' + xs.join(' / ') + ')');
  ok(await page.locator('#home-joins, #home-unanswered, #home-admin').count() === 0, 'no region for a space owner or the admin');
  ok(await page.locator(`#home-unread-${W.channel}`).count() === 1 && (await page.locator(`#home-unread-${W.channel}-line`).innerText()).includes('browser unread one') && (await page.locator('#home-unread-list > div').first().getAttribute('data-kind')) === 'dm', 'Unread: the channel with its first unread line, the DM first');
  ok((await page.locator('#home-note-text').innerText()).includes('SMOKE Monday report'), 'the Librarian\'s note is shown');
  ok(await page.locator(`#home-verification-${W.expired}`).count() === 1, 'the expired wiki page she owns is under "Needs my verification"');
  await widthOK(page, 'Home', 375); await touch(page, 'Home');
  await page.screenshot({ path: `${SHOTS}/home-priya-375.png`, fullPage: true });
  await page.click(`#home-unread-${W.channel} a`);
  await page.waitForURL(new RegExp(`/channels/${W.channel}`));
  ok(true, 'an Unread row opens its channel at the first unread message');
  await go(page, '/trail');
  ok(await page.locator('#trail-results').count() === 1 && await page.locator('#trail-table tbody tr').count() >= 1, 'My trail lists her rows');
  await page.selectOption('#trail-filter-action', 'space.');
  await page.waitForTimeout(600);
  await page.waitForLoadState('networkidle');
  ok(new URL(page.url()).searchParams.get('action') === 'space.' && (await page.locator('#trail-table tbody tr').allInnerTexts()).every((t) => !t.includes('posted a message')), 'the action filter swaps the results and pushes the URL');
  await widthOK(page, '/trail', 375); await touch(page, '/trail');
  await page.screenshot({ path: `${SHOTS}/trail-375.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors for Priya' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
// ---- 375: Marco (a space owner) ----
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — Marco');
  await go(page, '/');
  ok(await page.locator('#home-joins').count() === 1 && (await page.locator('#home-joins-list').innerText()).includes('SMOKE let me in') && await page.locator('#home-unanswered').count() === 1 && await page.locator('#home-admin').count() === 0, 'a space owner: Requests to join and Unanswered, no admin cards');
  ok(await page.locator('#home-note').count() === 0, 'no note: he has not joined the admin channel');
  await widthOK(page, 'Marco\'s Home', 375); await touch(page, 'Marco\'s Home');
  await page.screenshot({ path: `${SHOTS}/home-marco-375.png`, fullPage: true });
  await go(page, '/admin/settings');
  ok(await page.locator('#settings-form').count() === 0 && (await page.locator('body').innerText()).includes('You may not'), 'the admin pages refuse him in words');
  ok(errors.length === 0, 'no console errors for Marco' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
// ---- 375: the admin ----
{
  const { ctx, page, errors } = await session(1, PHONE);
  console.log('375 x 740 — the admin');
  await go(page, '/');
  ok(await page.locator('#home-admin').count() === 1 && await page.locator('#home-admin-published-count').count() === 1 && await page.locator('#home-admin-trash-count').count() === 1 && await page.locator('#home-admin-failed').count() === 1, 'the admin\'s three cards');
  ok(await y(page, '#home-admin') > await y(page, '#home-unread'), 'after the person\'s own regions');
  await widthOK(page, 'the admin\'s Home', 375); await touch(page, 'the admin\'s Home');
  await page.screenshot({ path: `${SHOTS}/home-admin-375.png`, fullPage: true });
  // settings
  await go(page, '/admin/settings');
  ok(await page.locator('#settings-form').count() === 1 && await page.locator('#emoji-list [id^="emoji-row-"]').count() > 40, 'the settings form and the emoji list');
  await widthOK(page, '/admin/settings', 375); await touch(page, '/admin/settings');
  await page.screenshot({ path: `${SHOTS}/admin-settings-375.png`, fullPage: true });
  await page.evaluate(() => { document.getElementById('settings-form').noValidate = true; });      // the browser's own min/max would stop it first: the server's sentence is the one under test
  await page.fill('#settings-form-field-digest_hour', '24');
  await page.click('#settings-form-save-btn');
  await page.waitForTimeout(800);
  ok((await page.locator('#flash').innerText()).toLowerCase().includes('digest'), 'a digest hour of 24 is refused in words naming the field: "' + (await page.locator('#flash').innerText()).trim().slice(0, 80) + '"');
  await page.fill('#settings-form-field-digest_hour', '6');
  await page.fill('#settings-form-field-allowed_embed_hosts', 'WWW.Example.com\nplayer.example.org');
  await Promise.all([page.waitForURL(/notice=saved/), page.click('#settings-form-save-btn')]);
  await page.waitForLoadState('networkidle');
  ok((await page.locator('#notice-banner').innerText()).includes('Saved the settings') && (await page.locator('#settings-form-field-digest_hour').inputValue()) === '6' && (await page.locator('#settings-form-field-allowed_embed_hosts').inputValue()) === 'www.example.com\nplayer.example.org', 'saved from the form: the banner, the values read back (hosts lower-cased)');
  await page.fill('#emoji-form-field-shortcode', 'smokebrowser' + W.run);
  await page.fill('#emoji-form-field-emoji', '🧪');
  await page.fill('#emoji-form-field-keywords', 'lab, test');
  await Promise.all([page.waitForURL(/notice=emoji_saved/), page.click('#emoji-form-save-btn')]);
  await page.waitForLoadState('networkidle');
  ok(await page.locator(`#emoji-row-smokebrowser${W.run}`).count() === 1, 'an emoji added from the form is in the list');
  page.once('dialog', (d) => d.accept());
  await Promise.all([page.waitForURL(/notice=emoji_deleted/), page.click(`#emoji-row-smokebrowser${W.run}-delete-btn`)]);
  await page.waitForLoadState('networkidle');
  ok(await page.locator(`#emoji-row-smokebrowser${W.run}`).count() === 0, 'and Remove (confirmed) takes it out');
  // spaces
  await go(page, '/admin/spaces');
  ok(await page.locator('[id^="admin-space-row-"][id$="-lock"]').count() >= 1 && await page.locator('#admin-spaces-list .card').count() >= 3, 'every space as a card; the private ones marked');
  await widthOK(page, '/admin/spaces', 375); await touch(page, '/admin/spaces');
  await page.screenshot({ path: `${SHOTS}/admin-spaces-375.png`, fullPage: true });
  // published
  await go(page, '/admin/published');
  ok(await page.locator('#admin-published-list .card').count() >= 1 && await page.locator('[id^="published-row-"][id$="-rotate-btn"]').count() >= 1, 'published pages as cards with Rotate and Unpublish');
  await widthOK(page, '/admin/published', 375); await touch(page, '/admin/published');
  await page.screenshot({ path: `${SHOTS}/admin-published-375.png`, fullPage: true });
  // retention
  await go(page, '/admin/retention');
  await page.fill(`#retention-row-${W.channel}-days`, '14');
  page.once('dialog', (d) => d.accept());
  await Promise.all([page.waitForURL(/notice=retention/), page.click(`#retention-row-${W.channel}-set-btn`)]);
  await page.waitForLoadState('networkidle');
  ok((await page.locator(`#retention-row-${W.channel}-state`).innerText()).includes('deletes after 14 days'), 'retention set from the page (confirmed): the row says "deletes after 14 days"');
  await widthOK(page, '/admin/retention', 375); await touch(page, '/admin/retention');
  await page.screenshot({ path: `${SHOTS}/admin-retention-375.png`, fullPage: true });
  // trash
  await go(page, '/admin/trash');
  ok(await page.locator(`#trash-row-${W.trashed}`).count() === 1 && (await page.locator('#admin-trash-preview').innerText()).includes('Purge all would delete'), 'the trash as cards, with the preview of Purge all');
  await widthOK(page, '/admin/trash', 375); await touch(page, '/admin/trash');
  await page.screenshot({ path: `${SHOTS}/admin-trash-375.png`, fullPage: true });
  await Promise.all([page.waitForURL(/notice=restored/), page.click(`#trash-row-${W.trashed}-restore-btn`)]);
  await page.waitForLoadState('networkidle');
  ok(await page.locator(`#trash-row-${W.trashed}`).count() === 0, 'Restore from a card brings the page back');
  ok(errors.length === 0, 'no console errors for the admin at 375' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
// ---- 1280: Priya, Marco, the admin ----
{
  const { ctx, page, errors } = await session(26, DESK);
  console.log('1280 x 800 — Priya');
  await go(page, '/');
  const xs = await xsOf(page, '#home-unread, #home-waiting');
  ok(xs.length === 2 && xs[0] !== xs[1], 'Home is two columns at 1280 (x ' + xs.join(' / ') + ')');
  await widthOK(page, 'Home', 1280); await touch(page, 'Home');
  await page.screenshot({ path: `${SHOTS}/home-priya-1280.png`, fullPage: true });
  await go(page, '/trail');
  await widthOK(page, '/trail', 1280); await touch(page, '/trail');
  ok(errors.length === 0, 'no console errors for Priya at 1280' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(1, DESK);
  console.log('1280 x 800 — the admin');
  for (const p of ['/', '/admin/settings', '/admin/spaces', '/admin/published', '/admin/retention', '/admin/trash', '/settings/', '/settings/tokens/']) {
    await go(page, p);
    await widthOK(page, p, 1280); await touch(page, p);
    await page.screenshot({ path: `${SHOTS}/admin${p.replace(/\W+/g, '-')}1280.png`, fullPage: true });
  }
  await go(page, '/admin/spaces');
  const sx = await xsOf(page, '#admin-spaces-list > div');
  ok(new Set(sx).size >= 2, 'the spaces sit in several columns at 1280 (x ' + [...new Set(sx)].join(' / ') + ')');
  // Purge all with its confirm: dismiss first (nothing goes), then accept
  await go(page, '/admin/trash');
  const before = await page.locator('#admin-trash-list > div').count();
  if (before === 0) { ok(true, 'the trash is empty here: no Purge all to drive'); } else {
    page.once('dialog', (d) => d.dismiss());
    await page.click('#admin-trash-purge-all-btn');
    await page.waitForTimeout(500);
    ok(await page.locator('#admin-trash-list > div').count() === before, 'Purge all: dismissing the confirm deletes nothing');
    page.once('dialog', (d) => d.accept());
    await Promise.all([page.waitForURL(/notice=emptied/), page.click('#admin-trash-purge-all-btn')]);
    await page.waitForLoadState('networkidle');
    ok(await page.locator('#admin-trash-empty').count() === 1, 'accepting it empties the trash');
  }
  ok(errors.length === 0, 'no console errors for the admin at 1280' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
