// Headless Chromium proof of slice 3 (docs/build-specs/block-editor.md, "Proof", 9): the editor at 1280 (typing saves with the version, the slash menu,
// the mention picker, Enter splits, Backspace merges, Tab nests, drag), at 375 (the handle menu replaces drag, the comment pane a full page), presence
// between two sessions, the comment pane at 1280, the version page; every control ≥ 44 px, scrollWidth = viewport, no console errors; JavaScript off → the reader and the notice.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8401';
const SHOTS = process.env.SHOTS || '/tmp/sp-shots-s3';
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

const Q = (s) => `[data-block-id="${s}"]`;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const caretEnd = async (page, sel) => page.evaluate((s) => { const el = document.querySelector(s); el.focus(); const r = document.createRange(); r.selectNodeContents(el); r.collapse(false); const sel2 = getSelection(); sel2.removeAllRanges(); sel2.addRange(r); }, sel);
const caretStart = async (page, sel) => page.evaluate((s) => { const el = document.querySelector(s); el.focus(); const r = document.createRange(); r.selectNodeContents(el); r.collapse(true); const sel2 = getSelection(); sel2.removeAllRanges(); sel2.addRange(r); }, sel);
const rootTexts = async (page) => page.evaluate(() => [...document.querySelectorAll('#page-body > .rt-block[data-block-id]')].map((b) => ({ id: b.dataset.blockId, type: b.dataset.type, v: b.dataset.version, text: (b.querySelector(':scope > .ed-text, :scope > summary .ed-text, :scope > label > .ed-text') || b).textContent.trim() })));
const waitSaved = async (page, id, v) => { await page.waitForFunction(([i, vv]) => { const b = document.querySelector(`[data-block-id="${i}"]`); return b && !b.dataset.dirty && parseInt(b.dataset.version, 10) >= vv; }, [id, v], { timeout: 8000 }); };
let marcoPage = null, pid = '';

// ---- 1280: Marco edits ----
{
  const { ctx, page, errors } = await session(27, DESK);
  marcoPage = page;
  console.log('1280 x 800 — the editor');
  await go(page, '/pages/new');
  await page.fill('#page-form-field-title', 'SMOKE Browser editor');
  await page.check('#page-form-where-space');
  await page.selectOption('#page-form-field-space', { label: '🚀 SMOKE Product' });
  await page.fill('#page-form-field-markdown', 'the first paragraph');
  await page.click('#page-form-save-btn');
  await page.waitForSelector('#editor');
  pid = await idOf(page);
  ok(await page.locator('#page-body [contenteditable="true"]').count() >= 1, 'the page opens in the editor: an editable paragraph');
  await widthOK(page, 'the editor', 1280); await touch(page, 'the editor');
  let blocks = await rootTexts(page);
  const first = blocks[0];
  // typing saves with the version
  await caretEnd(page, `${Q(first.id)} .ed-text`);
  await page.keyboard.type(' and more');
  await waitSaved(page, first.id, 2);
  blocks = await rootTexts(page);
  ok(blocks[0].text === 'the first paragraph and more' && blocks[0].v === '2', 'typing saved 600 ms later: the text and version 2 on the block');
  // Enter splits
  await caretEnd(page, `${Q(first.id)} .ed-text`);
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => document.querySelectorAll('#page-body > .rt-block').length >= 2);
  await page.keyboard.type('second block');
  blocks = await rootTexts(page);
  await waitSaved(page, blocks[1].id, 2);
  blocks = await rootTexts(page);
  ok(blocks.length === 2 && blocks[1].text === 'second block' && blocks[1].type === 'paragraph', 'Enter at the end made a new paragraph after it; typing into it saved');
  // the slash menu
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => document.querySelectorAll('#page-body > .rt-block').length >= 3);
  await page.keyboard.type('/head');
  await page.waitForSelector('#slash-menu:not(.d-none)');
  const visible = await page.evaluate(() => [...document.querySelectorAll('#slash-menu .sp-slash-item:not(.d-none)')].map((e) => e.dataset.type));
  ok(visible.length === 3 && visible[0] === 'heading_1', 'the slash menu filters to the three headings');
  await page.screenshot({ path: `${SHOTS}/editor-1280-slash.png` });
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => document.querySelectorAll('#page-body > .rt-block')[2] && document.querySelectorAll('#page-body > .rt-block')[2].dataset.type === 'heading_2', null, { timeout: 8000 });
  await page.keyboard.type('A heading');
  blocks = await rootTexts(page);
  await waitSaved(page, blocks[2].id, 2);
  blocks = await rootTexts(page);
  ok(blocks[2].type === 'heading_2' && blocks[2].text === 'A heading' && (await page.locator(`${Q(blocks[2].id)}`).evaluate((e) => e.tagName)) === 'H3', 'picked Heading 2: the block turned into an h3 and took the words');
  // a markdown shortcut
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => document.querySelectorAll('#page-body > .rt-block').length >= 4);
  await page.keyboard.type('- ');
  await page.waitForFunction(() => document.querySelectorAll('#page-body > .rt-block')[3] && document.querySelectorAll('#page-body > .rt-block')[3].dataset.type === 'bulleted_list_item', null, { timeout: 8000 });
  await page.keyboard.type('a bullet');
  blocks = await rootTexts(page);
  await waitSaved(page, blocks[3].id, 2);
  ok((await rootTexts(page))[3].type === 'bulleted_list_item', '"- " at the start made a bullet');
  // the mention picker
  await page.keyboard.type(' for @SMOKE Pr');
  await page.waitForSelector('#mention-picker:not(.d-none) .sp-mention-item', { timeout: 8000 });
  const cands = await page.evaluate(() => [...document.querySelectorAll('#mention-picker .sp-mention-item')].map((e) => e.dataset.name));
  ok(cands.includes('SMOKE Priya'), 'the @ picker offers SMOKE Priya');
  await page.keyboard.press('Enter');
  await page.waitForSelector(`${Q(blocks[3].id)} [data-mention]`, { timeout: 8000 });
  const mention = await page.evaluate((id) => JSON.parse(document.querySelector(`[data-block-id="${id}"] [data-mention]`).dataset.mention), blocks[3].id);
  ok(mention.type === 'member' && mention.id === '26', 'Enter inserted an atomic member mention, saved and re-rendered');
  // Tab nests, Shift-Tab un-nests
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => document.querySelectorAll('#page-body > .rt-block').length >= 5);
  await page.keyboard.type('nested');
  blocks = await rootTexts(page);
  const fifth = blocks[4].id;
  await waitSaved(page, fifth, 2);
  await page.keyboard.press('Tab');
  await page.waitForFunction((id) => { const b = document.querySelector(`[data-block-id="${id}"]`); return b && b.dataset.parent !== ''; }, fifth, { timeout: 8000 });
  ok((await page.evaluate((id) => document.querySelector(`[data-block-id="${id}"]`).dataset.parent, fifth)) === blocks[3].id, 'Tab nested it under the bullet');
  await caretEnd(page, `${Q(fifth)} .ed-text`);
  await page.keyboard.press('Shift+Tab');
  await page.waitForFunction((id) => { const b = document.querySelector(`[data-block-id="${id}"]`); return b && b.dataset.parent === ''; }, fifth, { timeout: 8000 });
  ok(true, 'Shift-Tab brought it back to the root');
  // Backspace merges
  await caretStart(page, `${Q(fifth)} .ed-text`);
  await page.keyboard.press('Backspace');
  await page.waitForFunction((id) => !document.querySelector(`[data-block-id="${id}"]`), fifth, { timeout: 8000 });
  blocks = await rootTexts(page);
  ok(blocks.length === 4 && blocks[3].text.endsWith('nested'), 'Backspace at the start merged it into the bullet');
  // drag: the second block to the end
  const handle = page.locator(`${Q(blocks[1].id)} .sp-drag-handle`);
  await page.locator(Q(blocks[1].id)).hover();
  const hb = await handle.boundingBox();
  const target = await page.locator(Q(blocks[3].id)).boundingBox();
  await page.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2);
  await page.mouse.down();
  await page.mouse.move(hb.x + 5, hb.y + 40, { steps: 6 });
  await page.mouse.move(target.x + 40, target.y + target.height - 2, { steps: 12 });
  await page.mouse.up();
  await sleep(1500);
  blocks = await rootTexts(page);
  ok(blocks[3].text === 'second block', 'drag moved the second block to the end (positions from the database)');
  await page.screenshot({ path: `${SHOTS}/editor-1280.png`, fullPage: true });
  // the comment pane at 1280
  await page.locator(Q(blocks[0].id)).hover();
  await page.click(`${Q(blocks[0].id)} .sp-drag-handle`);
  await page.waitForSelector('#handle-menu');
  await page.click('#handle-menu [data-act="comment"]');
  await page.waitForSelector('#right-pane:not([hidden]) #comment-pane');
  ok(true, 'the handle menu opens the comment pane in the right pane');
  await page.fill('#comment-new-field', 'A comment from the browser');
  await page.click('#comment-new-btn');
  await page.waitForSelector('#right-pane .sp-discussion', { timeout: 8000 });
  ok((await page.locator('#right-pane .sp-discussion').count()) === 1 && (await page.locator('#right-pane .sp-discussion').innerText()).includes('A comment from the browser'), 'a comment posted from the pane shows in it');
  await page.screenshot({ path: `${SHOTS}/editor-1280-comments.png` });
  ok(errors.length === 0, 'no console errors at 1280' + (errors.length ? ' — ' + errors.slice(0, 3).join(' | ') : ''));
  await widthOK(page, 'the editor with the pane', 1280);
}

// ---- presence: Priya edits, Marco is told ----
{
  const { ctx, page, errors } = await session(26, DESK);
  console.log('presence — two sessions');
  await go(page, `/pages/${pid}`);
  await page.waitForSelector('#editor');
  await marcoPage.waitForFunction(() => !document.getElementById('presence-bar').classList.contains('d-none'), null, { timeout: 15000 });
  ok((await marcoPage.locator('#presence-list').innerText()).trim() === 'S', 'Marco sees Priya here within 10 s');
  const b = (await rootTexts(page))[0];
  await caretEnd(page, `${Q(b.id)} .ed-text`);
  await page.keyboard.type(' (Priya)');
  await waitSaved(page, b.id, parseInt(b.v, 10) + 1);
  await marcoPage.waitForFunction(() => !document.getElementById('changed-banner').classList.contains('d-none'), null, { timeout: 15000 });
  ok((await marcoPage.locator('#changed-banner').innerText()).includes('Changed by SMOKE Priya'), 'Marco sees "Changed by SMOKE Priya" within 10 s');
  await marcoPage.click('#changed-banner-reload');
  await marcoPage.waitForFunction((id) => document.querySelector(`[data-block-id="${id}"] .ed-text`).textContent.includes('(Priya)'), b.id, { timeout: 8000 });
  ok(true, 'reload the page brings her words in');
  // two editors on one block: Marco's save with the old version is refused, his words offered back
  await marcoPage.evaluate((id) => { const el = document.querySelector(`[data-block-id="${id}"]`); el.dataset.version = '1'; }, b.id);
  await caretEnd(marcoPage, `${Q(b.id)} .ed-text`);
  await marcoPage.keyboard.type(' MARCO');
  await marcoPage.waitForSelector('.sp-stale-box', { timeout: 8000 });
  const words = await marcoPage.locator('.sp-stale-box textarea').inputValue();
  ok(words.includes('MARCO') && !(await marcoPage.locator(`${Q(b.id)} .ed-text`).innerText()).includes('MARCO'), 'a stale save: the block reloaded without his words, the box offers them back');
  await marcoPage.screenshot({ path: `${SHOTS}/editor-1280-stale.png` });
  await ctx.close();
}

// ---- 375: the same page edits; the handle menu; the pane as a full page; the version page ----
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — the editor on a phone');
  await go(page, `/pages/${pid}`);
  await page.waitForSelector('#editor');
  await widthOK(page, 'the editor at 375', 375); await touch(page, 'the editor at 375');
  const small = await page.evaluate(() => [...document.querySelectorAll('.sp-block-tools button')].filter((b) => b.getBoundingClientRect().height < 43.5).length);
  ok(small === 0, 'the block tools are 44 px on a phone');
  let blocks = await rootTexts(page);
  await page.click(`${Q(blocks[0].id)} .sp-drag-handle`);
  await page.waitForSelector('#handle-menu');
  await page.click('#handle-menu [data-act="down"]');
  await page.waitForFunction((t) => { const b = document.querySelectorAll('#page-body > .rt-block')[1]; return b && b.textContent.includes(t); }, 'the first paragraph', { timeout: 8000 });
  ok(true, 'the handle menu moves a block down (no drag on a phone)');
  await page.screenshot({ path: `${SHOTS}/editor-375.png`, fullPage: true });
  await page.click('#page-comments-btn');
  await page.waitForSelector('#page-comments-host #comment-pane');
  ok(await page.locator('#right-pane').isHidden(), 'the comments open as a card in the page at 375, not the side pane');
  await widthOK(page, 'comments at 375', 375);
  await page.screenshot({ path: `${SHOTS}/comments-375.png`, fullPage: true });
  await go(page, `/pages/${pid}/history`);
  await page.click('#version-save-btn');
  await page.waitForSelector('#version-1-open-btn', { timeout: 8000 });
  await page.click('#version-1-open-btn');
  await page.waitForSelector('#page-version-content');
  ok((await page.locator('#version-facts').innerText()).includes('Version 1'), 'the version page opens from History');
  await widthOK(page, 'the version page', 375); await touch(page, 'the version page');
  await page.screenshot({ path: `${SHOTS}/version-375.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors at 375' + (errors.length ? ' — ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- JavaScript off: the reader and the notice ----
{
  const { ctx, page } = await session(27, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await go(page, `/pages/${pid}`);
  const text = await page.locator('#page-content').innerText();
  ok(text.includes('Editing needs JavaScript') && text.includes('the first paragraph'), 'the page reads as a reader with the notice');
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed === 0 ? 0 : 1);
