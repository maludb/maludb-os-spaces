// Headless Chromium proof of the shell (docs/build-specs/sso-shell.md, "Proof: browser"):
//   at 375 x 740 and 1280 x 800 — scrollWidth = viewport, the bottom tab bar (Home · Channels · Pages · Search · Me) on a phone and
//   the sidebar on a desktop, the offcanvas from the menu button, the three panes at 1280 (the right pane opens to 380 px and closes),
//   the sidebar's spaces and channels from the fixture, the bell count, the command bar answering the fake kernel and following a
//   navigate, My settings saving prefs and a status, a token minted and shown once then revoked, HTMX navigation pushing URL and
//   title, every control ≥ 44 px, no console errors, the home whole with JavaScript off, the web app manifest installable.
// Run through tests/phase2/run.sh (it starts the servers and passes the environment). Playwright comes from the kernel's
// web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';

const BASE = 'http://127.0.0.1:8401';
const SHOTS = process.env.SHOTS || '/tmp/sp-shots';
const KEY = process.env.ACTION_TOKEN_KEY;
const STATE = process.env.FAKE_KERNEL_STATE;
const fixture = JSON.parse(fs.readFileSync(new URL('../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.spaces.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const urls = { get member() { return mint(26); }, get owner() { return mint(27); }, get guest() { return mint(29); }, get admin() { return mint(1); } };
const kernelState = (change) => { const s = JSON.parse(fs.readFileSync(STATE, 'utf8')); const n = change(s) || s; fs.writeFileSync(STATE, JSON.stringify(n)); };
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };

const browser = await chromium.launch();
async function session(signOn, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(signOn, { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
// Shown = rendered AND not parked off screen sideways (the theme's off-canvas sidebar keeps its size while hidden to the left); below the fold still counts.
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0 && r.right > 0 && r.left < window.innerWidth; }, sel);
const toggleMenu = (page) => page.evaluate(() => document.getElementById('mobile-collapse').click());
const groupsOf = async (page) => (await page.locator('.nxl-navbar .nxl-caption label').allInnerTexts()).map((g) => g.trim().toLowerCase());   // the theme upper-cases captions by CSS
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]), ' + s + ' select, ' + s + ' .app-tab')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && (r.height < 44 || r.width < 44); }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().width) + 'x' + Math.round(a.getBoundingClientRect().height)), scope);

// ---- phone: a space owner (Marco) ----
{
  const { ctx, page, errors } = await session(urls.owner, { width: 375, height: 740 });
  console.log('375 x 740 — a space owner');
  ok(page.url() === BASE + '/', 'signed on and landed on the home (' + page.url() + ')');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll on the home (scrollWidth ${o.sw} = ${o.iw})`);
  ok(await shown(page, '#app-tabbar') && !(await shown(page, '#shell-sidebar')), 'the bottom tab bar is shown on a phone; the sidebar is not');
  const box = await page.locator('#app-tabbar').boundingBox();
  ok(Math.abs(box.y + box.height - 740) < 1 && box.height >= 44, `the tab bar sits on the bottom edge (bottom ${box.y + box.height}, height ${box.height})`);
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.y + bar.height <= box.y + 1 && bar.y > 400, `the command bar sits just above the tab bar (bar bottom ${Math.round(bar.y + bar.height)} <= tab top ${Math.round(box.y)})`);
  const tabs = await page.locator('#app-tabbar .app-tab').allInnerTexts();
  ok(tabs.map((t) => t.trim()).join(',') === 'Home,Channels,Pages,Search,Me', `five tabs: ${tabs.map((t) => t.trim()).join(', ')}`);
  const small = await smallControls(page, '#app-tabbar');
  ok(small.length === 0, 'every tab is at least 44 x 44 px' + (small.length ? ': ' + small.join(', ') : ''));
  ok(await shown(page, '#home-unread') && await shown(page, '#home-spaces') && await shown(page, '#home-joins'), 'the home regions (Unread, Your spaces, Requests to join) are shown');
  ok((await page.locator('#header-role-badge').innerText()).includes('Space owner'), 'the header badge reads Space owner');
  ok(!(await shown(page, '#right-pane')), 'the right pane is hidden while empty');
  await page.screenshot({ path: `${SHOTS}/phone-home-owner.png` });
  // the menu button opens the sidebar as an offcanvas: the tree with General and its channel
  await page.click('#mobile-collapse');
  await page.waitForSelector('nav.nxl-navigation.mob-navigation-active');
  await page.waitForTimeout(400);
  ok(await shown(page, '#shell-sidebar') && (await page.locator('#shell-sidebar').innerText()).includes('General') && (await page.locator('[id^="sidebar-channel-"]').count()) >= 1, 'the menu button opens the sidebar: General and its channel are listed');
  const groups = await groupsOf(page);
  ok(groups.join(',') === 'browse,me', 'a space owner\'s menu groups: ' + groups.join(', '));
  await page.screenshot({ path: `${SHOTS}/phone-sidebar.png` });
  await page.evaluate(() => { const l = document.querySelector('.navbar-content'); l.scrollTop = l.scrollHeight; });
  const lastAfter = await page.locator('#shell-menu-groups .nxl-item:last-child .nxl-link').boundingBox();
  ok(lastAfter.y + lastAfter.height <= 740 - 56, `the last menu item can be scrolled clear of the bars (bottom ${Math.round(lastAfter.y + lastAfter.height)} <= ${740 - 56})`);
  await toggleMenu(page);
  await page.waitForTimeout(400);
  ok(!(await shown(page, '#shell-sidebar')), 'the menu button closes it again');
  // HTMX navigation: a tab swaps #page-content, pushes the URL and sets the title
  await page.click('#tab-my-settings');
  await page.waitForURL(BASE + '/settings/');
  await page.waitForSelector('#prefs-save-btn');
  ok(page.url() === BASE + '/settings/', 'the Me tab pushed /settings/ without a page load');
  ok((await page.title()).startsWith('My settings'), 'and set the title (' + (await page.title()) + ')');
  ok(await page.evaluate(() => document.querySelector('#tab-my-settings').classList.contains('active')), 'and highlighted the tab');
  ok(await page.evaluate(() => document.getElementById('page-content').dataset.screen === 'settings'), 'and re-stamped #page-content data-screen for the command bar');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on My settings');
  const smallS = await smallControls(page, '#page-content');
  ok(smallS.length === 0, 'every control on My settings is at least 44 px tall' + (smallS.length ? ': ' + smallS.slice(0, 6).join(', ') : ''));
  await page.screenshot({ path: `${SHOTS}/phone-settings.png` });
  // a status set from the phone
  await page.fill('#status-form-field-text', 'On the road');
  await page.fill('#status-form-field-emoji', '🚗');
  await page.click('#status-form-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Your status is set') && (await page.locator('#status-current-text').innerText()) === 'On the road', 'setting a status lands back on the settings with the notice and the status shown');
  await page.click('#tab-channels');
  await page.waitForURL(BASE + '/channels/');
  await page.waitForSelector('#channels-coming');
  ok((await page.title()).startsWith('Channels'), 'the Channels tab opens its placeholder (slice 4), title "' + (await page.title()) + '"');
  await page.goto(BASE + '/trail', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375, 'no sideways scroll on My trail');
  await page.goto(BASE + '/notifications', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375, 'no sideways scroll on Notifications');
  // the command bar: the fake expert answers in place; Send shows only while in use
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok(!(await shown(page, '#assistant-send-btn')), 'the Send button is hidden while the bar is idle');
  await page.fill('#assistant-input', 'What changed in General today?');
  ok(await shown(page, '#assistant-send-btn'), 'and shown once something is typed');
  await page.click('#assistant-send-btn');
  await page.waitForSelector('#assistant-reply-text');
  ok((await page.locator('#assistant-reply-text').innerText()).includes('Hello from the fake expert'), 'the command bar shows the expert\'s reply above the bar');
  await page.screenshot({ path: `${SHOTS}/phone-assistant.png` });
  // the right pane is a full page on a phone
  await page.evaluate(() => SP.rightPane.open('<p id="pane-proof">hello</p>', 'Thread'));
  const rp = await page.locator('#right-pane').boundingBox();
  ok(await shown(page, '#right-pane') && rp.width === 375 && (await page.locator('#right-pane-title').innerText()) === 'Thread', `the right pane opens as a full page (${Math.round(rp.width)} px wide)`);
  await page.click('#right-pane-close');
  ok(!(await shown(page, '#right-pane')), 'and closes');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- phone: a guest (Ann) ----
{
  const { ctx, page, errors } = await session(urls.guest, { width: 375, height: 740 });
  console.log('375 x 740 — a guest');
  ok((await page.locator('#header-role-badge').innerText()).includes('Guest'), 'the badge reads Guest');
  await page.click('#mobile-collapse');
  await page.waitForSelector('nav.nxl-navigation.mob-navigation-active');
  await page.waitForTimeout(300);
  const t = (await page.locator('#shell-sidebar').innerText()).toLowerCase();   // the theme upper-cases captions by CSS
  ok(t.includes('shared with me') && t.includes('direct messages') && !t.includes('private') && !t.includes('general'), 'a guest\'s sidebar holds Shared with me and Direct messages only');
  ok((await page.locator('#nav-spaces').count()) === 0 && (await page.locator('#nav-pages').count()) === 1, 'no Spaces item; Pages is there');
  await page.screenshot({ path: `${SHOTS}/phone-sidebar-guest.png` });
  ok(errors.length === 0, 'no console errors');
  await ctx.close();
}

// ---- desktop: the three panes, the admin ----
{
  const { ctx, page, errors } = await session(urls.admin, { width: 1280, height: 800 });
  console.log('1280 x 800 — the admin');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll (scrollWidth ${o.sw} = ${o.iw})`);
  ok(!(await shown(page, '#app-tabbar')), 'the tab bar is hidden on a desktop');
  ok(await shown(page, '#left-sidenav') && await shown(page, '#shell-sidebar') && await shown(page, '#nav-admin-settings'), 'the sidebar is shown, with the tree and the Admin group');
  const groups = await groupsOf(page);
  ok(groups.join(',') === 'browse,me,admin', 'the admin\'s groups: ' + groups.join(', '));
  ok((await page.locator('#shell-sidebar').innerText()).includes('General') && (await page.locator('[id^="sidebar-channel-"]').count()) >= 1 && (await page.locator('#sidebar-private-caption').count()) === 1 && (await page.locator('#sidebar-dms-caption').count()) === 1, 'the tree: General with its channel, Private, Direct messages');
  const nav = await page.locator('#left-sidenav').boundingBox();
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.x >= nav.width - 1 && bar.y + bar.height >= 780, `the command bar sits at the bottom, right of the sidebar (x ${Math.round(bar.x)}, sidebar ${Math.round(nav.width)})`);
  ok((await page.locator('#header-role-badge').innerText()).includes('Super-admin'), 'the badge reads Super-admin');
  const beforeW = (await page.locator('#page-content').boundingBox()).width;
  await page.evaluate(() => SP.rightPane.open('<p id="pane-proof">hello</p>', 'Thread'));
  const rp = await page.locator('#right-pane').boundingBox();
  const afterW = (await page.locator('#page-content').boundingBox()).width;
  ok(await shown(page, '#right-pane') && Math.round(rp.width) === 380 && afterW < beforeW && rp.x + rp.width <= 1280, `three panes: the right pane opens beside the main pane at ${Math.round(rp.width)} px (main ${Math.round(beforeW)} → ${Math.round(afterW)})`);
  ok((await overflow(page)).sw === 1280, 'and nothing scrolls sideways');
  await page.screenshot({ path: `${SHOTS}/desktop-three-panes.png` });
  await page.keyboard.press('Escape');
  ok(!(await shown(page, '#right-pane')) && Math.round((await page.locator('#page-content').boundingBox()).width) === Math.round(beforeW), 'Escape closes it and the main pane takes the width back');
  await page.screenshot({ path: `${SHOTS}/desktop-home.png` });
  await page.click('#nav-admin-trash .nxl-link');                 // a placeholder (slice 9) — HTMX navigation pushes its URL
  await page.waitForURL(BASE + '/admin/trash');
  await page.waitForSelector('#admin-trash-coming');
  ok((await page.title()).startsWith('Trash'), 'HTMX navigation: /admin/trash pushed, title "' + (await page.title()) + '"');
  ok(await page.evaluate(() => document.querySelector('#nav-admin-trash .nxl-link').classList.contains('active') && !document.querySelector('#nav-home .nxl-link').classList.contains('active')), 'the sidebar highlights the screen, not Home');
  await page.screenshot({ path: `${SHOTS}/desktop-placeholder.png` });
  await page.goBack();
  await page.waitForURL(BASE + '/');
  ok(true, 'the browser back button returns to /');
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  await page.fill('#token-form-field-label', 'proof');
  await page.click('#token-form-save-btn');
  await page.waitForSelector('#tokens-minted');
  ok((await page.locator('#tokens-minted-value').innerText()).startsWith('mcp_'), 'minting a token shows it once (mcp_…)');
  await page.screenshot({ path: `${SHOTS}/desktop-tokens.png` });
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  ok((await page.locator('#tokens-minted').count()) === 0, 'and never again');
  page.once('dialog', (d) => d.accept());
  await page.click('[id^="token-row-"][id$="-revoke-btn"]');
  await page.waitForSelector('.badge:has-text("revoked")');
  ok((await page.locator('.badge:has-text("revoked")').count()) >= 1, 'revoking it (with the confirm) marks it revoked');
  // the settings form saves over HTMX and lands back with the notice
  await page.goto(BASE + '/settings/', { waitUntil: 'networkidle' });
  await page.uncheck('#prefs-field-kind-reaction');
  await page.check('#prefs-field-digest');
  await page.click('#prefs-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Saved how you are told'), 'saving the settings lands back on them with the notice');
  ok(!(await page.isChecked('#prefs-field-kind-reaction')) && (await page.isChecked('#prefs-field-digest')), 'and the unchecked kind stayed unchecked, the digest on');
  // the bell count and a navigate from the command bar
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok((await page.locator('#header-bell').count()) === 1 && (await page.locator('#header-bell-count').count()) === 0, 'the bell shows no count (nothing unread)');
  kernelState((s) => { s.chat = { run_id: 9, status: 'succeeded', finished: true, reply: 'Opening your tokens.', actions: [], navigate: '/settings/tokens/' }; return s; });
  await page.fill('#assistant-input', 'open my tokens');
  await page.click('#assistant-send-btn');
  await page.waitForURL(BASE + '/settings/tokens/');
  await page.waitForSelector('#tokens-connect');
  ok(page.url() === BASE + '/settings/tokens/', 'a navigate answer from the command bar opened /settings/tokens/');
  kernelState((s) => { delete s.chat; return s; });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- JavaScript off: the home is whole ----
{
  const { ctx, page } = await session(urls.owner, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  const text = await page.locator('#page-content').innerText();
  ok(['Unread', 'Your spaces', 'Requests to join', 'General'].every((t) => text.includes(t)), 'the home regions are in the server-rendered page');
  ok(await shown(page, '#app-tabbar') && (await page.locator('#tab-channels').getAttribute('href')) === '/channels/' && (await page.locator('#tab-my-settings').getAttribute('href')) === '/settings/', 'the tabs are plain links');
  await ctx.close();
}

// ---- installable: the manifest, the icons, no service worker ----
{
  const ctx = await browser.newContext({ viewport: { width: 375, height: 740 } });
  const page = await ctx.newPage();
  await page.goto(urls.member, { waitUntil: 'networkidle' });
  console.log('installable');
  const href = await page.getAttribute('link[rel="manifest"]', 'href');
  const res = await page.request.get(BASE + href);
  const m = await res.json();
  ok(res.status() === 200 && /manifest\+json|application\/json/.test(res.headers()['content-type'] || ''), 'the manifest is served (' + res.headers()['content-type'] + ')');
  ok(m.display === 'standalone' && m.start_url === '/' && m.name === 'Spaces', 'standalone, start_url /, named Spaces');
  const sizes = [];
  for (const i of m.icons) { const r = await page.request.get(BASE + i.src); sizes.push(r.status() === 200 && i.sizes); }
  ok(sizes.includes('192x192') && sizes.includes('512x512'), 'the 192 and 512 px icons are served');
  const sw = await page.evaluate(async () => (await navigator.serviceWorker?.getRegistrations?.() || []).length);
  ok(sw === 0, 'no service worker registered (none in version 1)');
  const html = await page.content();
  ok(html.includes('name="theme-color"') && html.includes('rel="apple-touch-icon"') && html.includes('name="viewport"'), 'theme-color, apple-touch-icon and viewport are set');
  const cdp = await ctx.newCDPSession(page);
  const inst = await cdp.send('Page.getInstallabilityErrors').catch((e) => ({ installabilityErrors: [{ errorId: 'cdp:' + e.message }] }));
  const errs = (inst.installabilityErrors || []).map((e) => e.errorId);
  ok(!errs.some((e) => /manifest|icon|start-url|display|name/i.test(e)), 'Chromium reports no manifest installability error' + (errs.length ? ' (remaining: ' + errs.join(', ') + ')' : ''));
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
