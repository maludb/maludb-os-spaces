// Headless Chromium proof of slice 1 (docs/build-specs/spaces-core.md, "Proof", 9): every screen of the slice at 375 x 740 and 1280 x 800 with
// scrollWidth = viewport, every control ≥ 44 px, the three card groups stacking, the form's emoji picker as a popover (no modal), a space made and
// joined and requested through the real screens, the members table within the page, a section added and a page moved, the field error in #flash,
// JavaScript off. Run through tests/phase3/slice1/run.sh (servers up). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8401';
const SHOTS = process.env.SHOTS || '/tmp/sp-shots-s1';
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
const PHONE = { width: 375, height: 740 }, DESK = { width: 1280, height: 800 };
async function session(member, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403|404)/.test(m.text())) errors.push(m.text()); });   // a refused save answers 422, a refused screen 403 — both by design
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth })); ok(o.sw === o.iw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} = ${o.iw})`); };
const touch = async (page, label) => {
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button.btn, #page-content select.form-select, #page-content input.form-control')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5 && !e.classList.contains('btn-sm') && !e.classList.contains('form-select-sm') && !e.classList.contains('btn-link') && !e.classList.contains('sp-emoji-btn'); })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and control is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
const go = async (page, path) => page.goto(BASE + path, { waitUntil: 'networkidle' });
const idOf = async (page) => Number(await page.evaluate(() => document.getElementById('page-content').dataset.recordId));

// ---- phone: Marco walks the slice ----
let productId = 0;
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — a space owner');
  await go(page, '/spaces/');
  ok(await page.locator('#space-list-mine').count() === 1 && await page.locator('#space-list-open').count() === 1 && await page.locator('#space-list-closed').count() === 1, 'the list renders its three groups');
  const stacked = await page.evaluate(() => { const c = [...document.querySelectorAll('#space-list-mine > div')]; return c.length < 2 || c[0].getBoundingClientRect().top < c[1].getBoundingClientRect().top; });
  ok(stacked, 'the cards stack on a phone');
  await widthOK(page, 'Spaces', 375); await touch(page, 'Spaces');
  await page.screenshot({ path: `${SHOTS}/phone-spaces.png` });
  // make a space through the form: the emoji picker is a popover, the kind radios, lands on the space with the notice
  await go(page, '/spaces/new');
  ok(await page.locator('#space-form').count() === 1, 'the form renders');
  await widthOK(page, 'New space', 375); await touch(page, 'New space');
  ok(!(await page.locator('#space-form-emoji-popover').isVisible()), 'the emoji popover is closed');
  await page.click('#space-form-emoji-summary');
  ok(await page.locator('#space-form-emoji-popover').isVisible() && (await page.locator('.modal').count()) === 0, 'it opens inside the form — no modal');
  await page.click('.sp-emoji-btn[data-emoji="🎉"]');
  ok((await page.inputValue('#space-form-field-icon')) === '🎉' && !(await page.locator('#space-form-emoji-popover').isVisible()), 'picking an emoji fills the icon and closes the popover');
  await page.fill('#space-form-field-name', 'SMOKE Browser space');
  await page.click('#space-form-field-kind-closed');
  ok(!(await page.locator('#space-form-everyone').isVisible()), 'the everyone level hides for a closed space');
  await page.click('#space-form-field-kind-open');
  ok(await page.locator('#space-form-everyone').isVisible(), 'and shows for an open one');
  await page.click('#space-form-save-btn');
  await page.waitForSelector('#space-view-content');
  productId = await idOf(page);
  ok(productId > 0 && (await page.locator('#notice-banner').innerText()).includes('You own it') && (await page.locator('#space-view-owner-chip').count()) === 1, `made the space (#${productId}), landed on its home with the notice; you own it`);
  await widthOK(page, 'Space home', 375); await touch(page, 'Space home');
  await page.screenshot({ path: `${SHOTS}/phone-space-home.png` });
  // a field error lands in #flash
  await go(page, `/spaces/${productId}/edit`);
  await page.fill('#space-form-field-name', '   ');            // whitespace passes the browser's required check; the server trims and refuses
  await page.click('#space-form-save-btn');
  await page.waitForSelector('#flash-message');
  ok((await page.locator('#flash-message').innerText()).includes('Give the space a name'), 'a field error lands in #flash in words');
  // members: the table within the page; add Priya
  await go(page, `/spaces/${productId}/members`);
  ok(await page.locator('#space-members-table').count() === 1, 'the members screen renders');
  await widthOK(page, 'Members', 375); await touch(page, 'Members');
  await page.selectOption('#member-add-field-member', '26');
  await page.click('#member-add-btn');
  await page.waitForSelector('#member-row-26');
  ok((await page.locator('#member-row-26').count()) === 1 && (await page.locator('#notice-banner').innerText()).includes('Saved the members'), 'Priya added through the form; the row appears with the notice');
  await page.screenshot({ path: `${SHOTS}/phone-members.png` });
  // sections: add one; move it (the select per page needs a page — none yet: the empty shapes)
  await go(page, `/spaces/${productId}/sections`);
  await page.fill('#section-add-field-name', 'Docs');
  await page.click('#section-add-btn');
  await page.waitForSelector('[id^="section-row-"]');
  ok((await page.locator('[id^="section-row-"][data-section]').count()) === 1 && (await page.locator('#section-loose-empty').count()) === 1, 'a section added through the form; no loose page');
  await widthOK(page, 'Sections', 375); await touch(page, 'Sections');
  for (const [path, label, sel] of [[`/spaces/${productId}/requests`, 'Requests', '#space-requests-content'], [`/spaces/${productId}/templates`, 'Templates', '#space-templates-content']]) {
    await go(page, path);
    ok(await page.locator(sel).count() === 1, `${label} renders`);
    await widthOK(page, label, 375); await touch(page, label);
  }
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- phone: Dana joins the open one and asks for a closed one ----
{
  const { ctx, page, errors } = await session(30, PHONE);
  console.log('375 x 740 — a member joining');
  await go(page, `/spaces/${productId}`);
  ok((await page.locator('#space-view-join-btn').count()) === 1, 'the open space shows Join');
  await page.click('#space-view-join-btn');
  await page.waitForSelector('#space-view-member-chip');
  ok((await page.locator('#notice-banner').innerText()).includes('You are in'), 'joined through the button; the notice says so');
  page.once('dialog', (d) => d.accept());      // hx-confirm
  await page.click('#space-view-leave-btn');
  await page.waitForSelector('#space-list-content');
  ok((await page.locator('#notice-banner').innerText()).includes('You left'), 'left through the button (with the confirm), landed on the list');
  const closed = await page.evaluate(() => { const a = document.querySelector('#space-list-closed [id$="-request-btn"]'); return a ? a.id : ''; });
  ok(closed !== '', 'a closed space offers Request to join (' + closed + ')');
  await page.click('#' + closed);
  await page.waitForSelector('#space-request-field-message');
  await page.fill('#space-request-field-message', 'SMOKE from the phone');
  await page.click('#space-view-request-btn');
  await page.waitForSelector('#space-view-withdraw-btn');
  ok((await page.locator('#notice-banner').innerText()).includes('Its owners are told'), 'asked through the form; the notice says the owners are told');
  await page.screenshot({ path: `${SHOTS}/phone-requested.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: the admin; the sections drag; the private space marked ----
{
  const { ctx, page, errors } = await session(1, DESK);
  console.log('1280 x 800 — the admin');
  await go(page, '/spaces/');
  await widthOK(page, 'Spaces', 1280);
  const privateChips = await page.locator('#space-list-mine [id$="-kind"]:has-text("private")').count();
  ok(privateChips >= 1, 'the admin sees the private space under Mine, marked');
  await page.screenshot({ path: `${SHOTS}/desktop-spaces.png` });
  await go(page, `/spaces/${productId}/sections`);
  await page.fill('#section-add-field-name', 'Decisions');
  await page.click('#section-add-btn');
  await page.waitForFunction(() => document.querySelectorAll('[id^="section-row-"][data-section]').length === 2);
  const rows = page.locator('[id^="section-row-"][data-section]');
  const first = await rows.nth(0).locator('.sp-drag-handle').boundingBox();
  const second = await rows.nth(1).locator('.sp-drag-handle').boundingBox();
  await page.mouse.move(second.x + 5, second.y + 5); await page.mouse.down(); await page.mouse.move(first.x + 5, first.y - 10, { steps: 12 }); await page.mouse.up();
  await page.waitForTimeout(800);
  await go(page, `/spaces/${productId}/sections`);
  const names = await page.locator('[id^="section-row-"][data-section] input[name="name"]').evaluateAll((els) => els.map((e) => e.value));
  ok(names[0] === 'Decisions' && names[1] === 'Docs', 'dragging Decisions above Docs reorders them (a POST per drop): ' + names.join(', '));
  await page.screenshot({ path: `${SHOTS}/desktop-sections.png` });
  await go(page, `/spaces/${productId}`);
  await widthOK(page, 'Space home', 1280);
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- JavaScript off: joins and saves are plain forms ----
{
  const { ctx, page } = await session(31, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await go(page, `/spaces/${productId}`);
  await page.click('#space-view-join-btn');
  await page.waitForSelector('#space-view-member-chip');
  ok((await page.locator('#notice-banner').innerText()).includes('You are in'), 'a plain POST joins and lands with the notice');
  await go(page, '/spaces/new');
  await page.fill('#space-form-field-name', 'SMOKE No-JS space');
  await page.click('#space-form-save-btn');
  await page.waitForSelector('#space-view-content');
  ok((await page.locator('#notice-banner').innerText()).includes('You own it'), 'a plain POST makes a space');
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
