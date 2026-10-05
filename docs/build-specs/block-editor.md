# Build spec: the block editor — THE FIRST EXEMPLAR (slice 3)

Built by the planning-class model. Every later slice replicates its files, ids, gates, flow and proof style; slice 4 is its sibling exemplar
for a live conversation. This is the one piece of serious front-end JavaScript in the whole OS: a page being edited by a person, block by
block, each block saved on its own with an optimistic version, a stale save refused and reloaded, the slash menu, Markdown shortcuts,
Enter and Backspace, Tab and Shift-Tab, drag handles, mentions and page links, images and files, synced blocks, columns, versions with a
diff and a restore, comments in the margin, presence and the "changed by someone else" banner. Markdown is the agents' language: the one
converter turns Markdown into blocks on the way in; `sp_page_markdown()` is the way out.
Schema: `blocks`, `sp_blocks_guard()`, `sp_blocks_after()`, `sp_block_insert()`, `sp_block_move()`, `sp_page_set_locked()`, `sp_refresh_page_links()`
(db/008); `pages`, `page_versions`, `sp_page_level()`, `sp_can_edit_page()`, `sp_page_visited()` (db/007); `comments`, `comment_mentions`
(db/011); `attachments`, `sp_can_see_attachment()`, `sp_notify()` (db/012); `sp_page_tree()`, `sp_page_snapshot()`, `sp_page_markdown()`,
`sp_rich_text_markdown()`, `sp_block_markdown()` (db/014); `sp_version_save()`, `sp_version_restore()`, `sp_version_markdown()` (db/016);
`mcp_blocks`, `mcp_pages`, `mcp_page_versions`, `mcp_comments`, `mcp_attachments`, `mcp_members` (db/017); `sp_block_types()`,
`sp_block_types_with_children()`, `sp_text_colors()`, `sp_rich_text_plain()` (db/005). Never modify them.
**The database is the referee**: a block belongs to one page and one parent, the structural pairs (a row under a table, a column under a
column list), which types hold children, the version (a save carrying an old one is `stale`), a locked page, a trashed page, a synced copy's
original — all in db/008's triggers. The editor sends what it did and shows what the database said; it never checks a rule twice. **A stale
save is never merged**: the block is reloaded and the person's words are offered back in a box they can copy (D7).

## Screens (designed at 1280 px; works at 375 px)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `page-view` | `/pages/{id}` | **the editor** for anyone with `edit` (or `edit_content` on a row), **the reader** for everyone else (slice 2 built the reader; this slice replaces its body region with the editor when the level allows): breadcrumb, icon and cover, the title (an editable `h1`), the properties panel when a row (slice 5), the block tree with a drag handle and a `+` on hover per block, the slash menu, the margin's comment markers, "who is here" avatars, the stale banner, the "changed by Priya — reload" banner, the page menu (slice 2) with **History** and **Lock** |
| `page-version` | `/pages/{id}/versions/{version}?against=` | one version rendered read-only, a unified diff against the present (or `against=` another version) in a two-column view at 1280 and stacked at 375; **Restore this version** (confirm) |
| `page-history` | `/pages/{id}/history` | slice 2's list made real: versions with reason, who, when, size; open; compare two (the diff page) |

## The block model in the browser
- The page's tree arrives as JSON (`sp_page_tree()`) inside the page (`<script type="application/json" id="page-tree">`) and as rendered HTML
  (`app/richtext/render.php`, the same renderer the reader uses, with `data-block-id`, `data-type`, `data-version`, `data-parent`,
  `data-position`); `html/assets/js/editor.js` (vanilla, ~1,200 lines, no framework; SortableJS for drag) takes over the HTML. Without
  JavaScript the page is the reader plus a form per block? **No** — without JavaScript the page is the reader with a notice *"Editing needs
  JavaScript"*; an agent edits through the actions.
- **One block = one `contenteditable` element** for text types (paragraph, headings, list items, to-do, toggle, quote, callout, code, table cells);
  non-text types (image, file, pdf, video, audio, bookmark, embed, equation, divider, link_to_page, child_page, child_database, toc, breadcrumb,
  synced copy, column list) are widgets with their own small controls (caption, replace, align). The rich-text runs are kept in the DOM as
  `<span data-run>` with the annotations as classes; the serializer turns the DOM back into the runs array (`text`, `mention`, `equation`), never
  loses a mention, and strips anything it does not know (pasted HTML becomes plain runs; pasted Markdown is converted, below).
- **Saving**: a block saves 600 ms after its last keystroke, or on blur, or on Enter/Backspace/Tab — `block_update` with its `version`; the reply
  carries the new version and the rendered HTML; the DOM takes the version. A `stale` reply (422 with the word) → the block is re-fetched
  (`GET /blocks/get.php?block=`), re-rendered, and a `warning` box under it shows *"Someone changed this block while you typed — your words:"*
  with the person's text to copy; nothing is merged. A locked or trashed page → every save answers the sentence and the editor goes read-only.
- **Enter** splits at the caret (the right half becomes a new block after, same type for list items and to-dos, a paragraph after a heading or
  a quote; an empty list item + Enter leaves the list): `block_update` (the left half) + `block_insert` (`after`) in one request
  (`POST /blocks/split.php` — one transaction, two verbs). **Backspace at the start** merges into the previous text block (`POST /blocks/merge.php`:
  the previous block takes the words, this one is deleted; children re-parent to the previous). **Tab** nests under the previous sibling
  (`block_move` with `parent`), **Shift-Tab** un-nests (to after the parent). **Drag** (the handle) → `block_move` with the new parent and
  `after`; the server computes the position (`sp_block_move`); dropping into a column, a toggle or a list item is allowed where
  `sp_block_types_with_children()` says so, else refused in words.
- **The slash menu** (`/` at the start or after a space): every adopted type with its name and a one-line hint, filtered as you type, Enter picks;
  turning the current block into that type (`block_update` with `type`) or inserting a widget after it (`block_insert`). **Markdown shortcuts**
  at the start of a block: `# ` `## ` `### `, `- ` `* `, `1. `, `[] ` `[x] `, `> `, `> [!NOTE] `, ```` ``` ````, `---`, `$$`; `**bold**`, `*italic*`,
  `` `code` ``, `~~struck~~` inside a run. **`@`** opens the mention picker (members, agents, departments from `find_members`/`find_departments`
  through `html/pages/mentions.php` — a JSON endpoint of the screen, not an action; the picked one becomes a `mention` run); **`[[`** opens the page
  picker (`find_pages`) and inserts a `page` mention run; **`:`** opens the emoji picker (`mcp_emoji`).
- **Images and files**: drop, paste or the `+` menu → `file_upload` (multipart, the block's id; `store_attachment()` from Help Desk's pattern: `finfo`,
  the allow-list of D14, 25 MB, HEIC kept, a GD thumbnail for images under `storage/attachments/<kind>/<id>/thumb.jpg`) → the block's `content`
  gets `attachment_id`, `url` = `/files/{id}`, `width`/`height`; a caption is rich text. `html/files.php` serves `/files/{id}` through
  `sp_can_see_attachment()` with `nosniff`, inline for images and PDFs, download otherwise; `/files/{id}/thumb` the thumbnail.
- **Synced blocks**: "Turn into synced block" wraps a block; "Copy synced block" puts a copy's reference on the clipboard (`synced:<uuid>`); pasting
  it inserts a copy (`block_insert` type `synced_block` with `synced_from`); a copy renders its original's children read-only with a link to the
  original; a reader who may not see the original sees *"a synced block you cannot see"* (the tree omits the children).
- **Columns**: "Add column" on a column list; a `column_list` renders its columns side by side at 1280 and stacked at 375; blocks drag into columns.
- **Tables**: a `table` renders its `table_row` children; Tab moves across cells; "+ row" / "+ column" (a column adds a cell to every row:
  `POST /blocks/table-column.php` — one transaction); the header row from `has_column_header`.
- **Presence**: `POST /pages/presence.php` every 10 s while the tab is visible (`{page, content_rev}`) answers who else is here (members whose
  presence row on this page is younger than 30 s — a per-request table in PHP's session store? **No**: `page_presence (page_id, member_id, seen_at)`
  is not in the schema — presence is kept in a tiny file-backed cache `storage/presence/<page>.json` written by the endpoint, never the database)
  and whether `content_rev` moved; when it moved the banner *"Changed by Priya — reload the page"* shows (the editor does not merge).
- **Versions**: **Save version** (`version_save`) in the page menu; the worker snapshots quiet pages (slice 8); History lists them; a version page
  renders `sp_version_markdown()` through the Markdown renderer (`app/richtext/markdown.php` → blocks → HTML) and a line diff (`app/richtext/diff.php`,
  a plain LCS over lines) against the present (`sp_page_markdown()`); **Restore** (`version_restore`, confirm, `deletion` for agents) → the present is
  snapshotted first by the SQL function; the page reloads.
- **Comments**: the margin shows a marker per block with open comments (`mcp_comments`); clicking opens the right pane (`#right-pane`, full page at
  375) with the discussion: the comment, its replies, Resolve, Reply (`comment_add` with `parent`), Edit (own), Delete (own, or `full`); "Comment"
  in a block's hover menu starts an inline discussion on it; the page's own comments (no block) sit at the top of the pane. A mention in a comment
  notifies (the trigger). The pane polls every 20 s for new comments on the page.
- **Lock**: `page_lock` (slice 2's action) — the editor goes read-only, the menu says Unlock.

## The converter (`app/richtext/`)
- `markdown.php`: `markdown_to_blocks(string $md): array` — the tree in the JSON shape `sp_page_tree()` gives (type, content, children), covering
  every adopted type the Markdown of `sp_page_markdown()` produces: headings, lists with nesting by indentation, to-dos, quotes, callouts
  (`> [!NOTE]`), code fences with a language, dividers, tables, images `![]()`, files by link, equations `$$`, toggles `<details>`, `[[Page title]]`
  (resolved to a `page` mention through `find_pages` by exact title, else kept as text), `@Name` (resolved through `find_members`, else text),
  bookmarks and embeds (a bare URL on its own line: an embed when the host is in `allowed_embed_hosts`, else a bookmark), bold/italic/code/strike/
  links as runs. **The round trip is the proof**: `markdown_to_blocks(sp_page_markdown(p))` re-rendered must equal `sp_page_markdown(p)` for
  the proof page of every type (columns flatten, by design).
- `render.php`: `render_blocks(array $tree, array $opts): string` — blocks → HTML for the reader, the editor (with the data attributes), the
  public page (no edit attributes, images through `/p/{token}/files/`), the version page and the HTML export. `render_rich_text(array $runs): string`.
- `blocks_to_markdown` is **not** written in PHP: the SQL `sp_page_markdown()` is the one way out (an export, a tool, the diff all call it).

## Files (exactly these)
- `html/pages/view.php` (`page-view`, slice 2's file — this slice adds the editor branch) · `html/pages/history.php` (real) · `html/pages/versions/view.php`
  (`page-version`) · `html/pages/versions/save.php` · `versions/restore.php` · `html/pages/mentions.php` (JSON: the pickers) · `html/pages/presence.php`
- `html/blocks/append.php` · `insert.php` · `update.php` · `move.php` · `delete.php` · `split.php` · `merge.php` · `table-column.php` · `get.php`
- `html/pages/comments/add.php` · `edit.php` · `resolve.php` · `delete.php` · `html/pages/comments.php` (the pane, GET)
- `html/files/upload.php` · `delete.php` · `html/files.php` (`/files/{id}`, `/files/{id}/thumb`)
- `app/richtext/{markdown,render,diff}.php` · `app/features/blocks/{queries,write,handler}.php` · `app/features/comments/{queries,write}.php` ·
  `app/features/files/{store,queries}.php` (`store_attachment()`, `attachment_path()`, `serve_attachment()`)
- `app/views/pages/{editor,partials/block,partials/slash-menu,partials/mention-picker,partials/stale-box,partials/presence,partials/comment-pane,partials/comment,version,diff}.php`
- `html/assets/js/editor.js` · `html/assets/css/editor.css`

## Query functions (signatures fixed)
- `page_tree(PDO, string $pageId): array` (`sp_page_tree()`) · `find_block(PDO, string $blockId): ?array` (`mcp_blocks`) · `block_html(array $block, array $opts): string`
- `append_markdown(PDO, string $pageId, string $markdown, ?string $parent, ?string $after): array` (the converter + `sp_block_insert()` per block, children recursively; returns the ids)
- `insert_block(PDO, string $pageId, string $type, array $content, ?string $parent, ?string $after, bool $atStart): string` · `update_block(PDO, string $blockId, int $version, ?array $content, ?string $type): array` (UPDATE … SET version = :v; `stale` surfaces as the exception's word) · `move_block(PDO, string $blockId, ?string $parent, ?string $after): void` · `delete_block(PDO, string $blockId): int`
- `split_block(PDO, string $blockId, int $version, array $leftContent, array $rightContent, string $rightType): array` · `merge_block(PDO, string $blockId, int $version, string $intoBlockId, int $intoVersion): array` · `table_add_column(PDO, string $tableId, int $atIndex): array`
- `page_versions(PDO, string $pageId): array` · `version_markdown(PDO, int $versionId): ?string` · `line_diff(string $a, string $b): array`
- `page_comments(PDO, string $pageId, bool $openOnly): array` · `add_comment(PDO, string $pageId, ?string $blockId, ?string $parent, array $body): string` · `resolve_comment(PDO, string $id, bool $resolved): void`
- `store_attachment(PDO, array $file, string $recordType, int|string $recordId, int $by): array` · `attachment_for(PDO, int $id): ?array` · `serve_attachment(array $a, bool $thumb): never`
- `presence_touch(string $pageId, int $memberId): array` (the file cache; returns the others) · `mention_candidates(PDO, string $q, string $kind): array`

## Handlers (every one: `sp_handler_begin()`; the gate `require_page_level($page, 'edit')` — `edit_content` for a row's blocks; `sp_guard()`; `log_activity` with `entity_uuid`, `space_id`; `sp_done()`; `HX-Trigger: blockChanged`)
- `blocks/append.php` (`block_append`): `markdown` → the converter → inserts; `block.append` (`count`, `parent_block_id`); location `/pages/{page}`.
- `blocks/insert.php` (`block_insert`), `blocks/update.php` (`block_update` — `version` required; the reply `{version, html}`; a `stale` refusal is 409 with
  `{error: {code: stale, version, html}}` so the editor reloads the block), `blocks/move.php` (`block_move`), `blocks/delete.php` (`block_delete` — `deletion`
  for agents; `block.delete` with `type`, `had_children`), `blocks/split.php`, `blocks/merge.php`, `blocks/table-column.php` (screen helpers: logged
  `block.update`/`block.insert`, not manifest actions — the agents' way is `block_append`/`block_update`).
- `pages/versions/save.php` (`version_save`): `page.version_save` (`version_no`, `reason manual`). `pages/versions/restore.php` (`version_restore`): `full`;
  `deletion` for agents; `page.version_restore` (`from_version`, `version_no`).
- `pages/comments/add.php` (`comment_add`): `require_page_level($page, 'comment')`; `comment.add` (`comment_id`, `block_id`, `parent_comment_id`, `length` —
  never the words). `edit.php`: own; `comment.edit`. `resolve.php`: `comment`; `comment.resolve` (`resolved`). `delete.php`: own or `full`; `deletion`;
  `comment.delete`.
- `files/upload.php` (`file_upload`): `edit` on the page (or `channel` on a message — slice 4; `edit_content` on a row); `attachment.add` (`attachment_id`,
  `record_type`, `filename`, `mime_type`, `byte_size`); a refused mime → 422 in words (*"HTML, SVG and programs are not accepted"*). `files/delete.php`
  (`attachment_delete`): `deletion`; `attachment.delete`.
- `pages/presence.php`, `pages/mentions.php`, `blocks/get.php`: screen helpers (session only; no log beyond `screen.view` on the page).

## Action manifest entries
Screens: `page-view` (the editor branch), `page-version`, `page-history` (real). Actions (13): `block_append`, `block_insert`, `block_update`, `block_move`,
`block_delete`, `version_save`, `version_restore`, `comment_add`, `comment_edit`, `comment_resolve`, `comment_delete`, `file_upload`, `attachment_delete`.
Agent approvals: `block_delete`, `version_restore`, `comment_delete`, `attachment_delete` (`deletion`). `PARTIAL_UPDATE_TARGETS` gains nothing (a
block update is whole by `version`).

## Activity log events (every row: `entity_uuid` = the block or comment, `space_id`; the page in `after.page_id`)
`block.append|insert|update|move|delete`, `page.version_save|version_restore`, `comment.add|edit|resolve|delete`, `attachment.add|delete`, `screen.view`.
**No row carries a block's text, a comment's words or a file's bytes.**

## Notifications this slice queues
| Step | Who is told | Kind |
|---|---|---|
| a comment mentions someone, or lands on a page they own, or replies in their discussion | them (the trigger `comments_notify`) | `comment` |

## Status vocabulary
Ids: `editor`, `block-{uuid}`, `block-{uuid}-handle`, `block-{uuid}-add`, `slash-menu`, `mention-picker`, `emoji-picker`, `stale-box-{uuid}`,
`presence-bar`, `changed-banner`, `comment-marker-{uuid}`, `comment-pane`, `comment-{uuid}`, `version-list`, `version-row-{id}`, `diff-view`.
Chips: a verified page `success`, expired `warning`, locked `dark` (`feather-lock`); an agent's edit shows the agent chip in History.

## Out of scope for this slice
The reader and the page menu (2 — this slice only swaps the body for the editor), properties and databases (5), the public page (2), search (6),
the worker's snapshots and the index catch-up (8), imports (8), real-time co-editing (Extended).

## Proof (`tests/phase3/slice3/run.sh`: the scratch database `sp_dev3`, members through dev hand-offs, curl with signed action and run tokens,
headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`; `sync_approvals --check`)
The world (`tests/phase3/slice3/lib.php` `editor_world()`): Product (closed) with Marco its owner, Priya and Dana members, Seamus the agent; a
page "Runbook" with every block type (the Phase 0 proof's list); a private page of Bea's; the guest Ann with `view` on Runbook.
- [ ] **The converter round trip**: for the every-type page, `markdown_to_blocks(sp_page_markdown)` re-rendered equals `sp_page_markdown` (columns flattened);
  `[[Runbook]]` becomes a page mention, `@Priya` a member mention, an unknown `[[X]]` stays text; a YouTube URL an embed, another host a bookmark; a
  pasted Markdown table becomes a table with rows.
- [ ] **Saving and the version**: `block_update` with the read version → the new version and HTML; the same version again → 409 `stale` with the current HTML;
  two editors (two sessions) on one block: the second's save refused, its words offered back, nothing merged; a locked page → the sentence and
  read-only; a trashed page → the sentence; a reader (`view`) → 403 on every write; `edit_content` on a row edits its blocks but not the database's schema.
- [ ] **Structure**: Enter splits (the right half a new block after; a list item stays a list item; a heading's tail a paragraph); Backspace merges and
  re-parents children; Tab nests under the previous sibling, Shift-Tab un-nests; a table row only under a table and a column only under a column list
  (the database's words); dropping into a divider refused; drag reorders with positions from `sp_block_move`; `block_append` with Markdown of three blocks
  makes three in order after the named block.
- [ ] **Widgets**: an image uploaded (a PNG → thumbnail, width/height), served through `/files/{id}` with `nosniff` and refused to Ann when she loses `view`;
  an SVG and an .exe refused in words; a 30 MB file refused by the setting; a synced copy renders the original's children and says "cannot see" for Ann
  when the original is on a page she cannot see; a column list stacks at 375; a table gains a column on every row.
- [ ] **Versions**: a manual save = v1; edits; History lists it; the version page renders as it was; the diff marks the changed lines; Restore snapshots the
  present (v2) and writes v3 equal to v1; an agent's restore pauses (`deletion`) on the MCP path (Phase 4) — here the category in the registry.
- [ ] **Comments**: an inline comment with a mention notifies Marco; a reply joins the discussion; a reply to a reply refused; resolve, then a reply refused;
  the pane at 1280 and the full page at 375; a `comment`-level guest comments but cannot edit; delete own only, `full` deletes any.
- [ ] **Presence**: two sessions on one page see each other within 10 s; a block saved by one shows the banner on the other within 10 s; the heartbeat sets
  `last_seen_at`.
- [ ] **JSON mode**: every action under a signed action token answers `{ok, did, record_id, location, refresh}`; the expert (a run token + relay) appends
  Markdown to a page it may edit, is refused on one it may not (403 in words), and its `block_delete` is marked `deletion` in the registry.
- [ ] **375 × 740 and 1280 × 800**: the editor at 1280 with the slash menu, the mention picker, drag; at 375 the same page edits with the on-screen keyboard
  (the handle menu replaces drag), the pane as a full page; every control ≥ 44 px, `scrollWidth` = viewport, no console errors; JavaScript off → the reader
  and the notice.

## Built and proven (2026-10-05)
Built exactly as the Files list says: `app/richtext/markdown.php` (`markdown_to_blocks()`, `markdown_inline_runs()` — every adopted type in, `[[Page]]` and `@Name`
resolved through the caller's views, a bare URL an embed on an allowed host else a bookmark, `<details>` a toggle, `> [!NOTE]` a callout, a pipe table a table with
rows, nesting by indentation), `app/richtext/diff.php` (`line_diff()`, LCS), the renderer's editor mode (`render_blocks(['editor' => true])`: every block carries
`data-type/version/parent/position/synced-from`, every text region is a `contenteditable` span of `<span data-run>` runs with mentions and equations atomic, a code
block's `<code>` and a table's cells editable, **list items as blocks of their own** — no `<ul>` in the editor, the bullet and the number by CSS counters — so a swap
never changes the tree), `app/features/blocks/{handler,queries,write}.php` (`StaleBlock` → 409 `{error: {code: stale, version, html}}`; `block_html()` renders a
row as its table), `app/features/comments/{queries,write}.php`, `app/features/files/{store,queries}.php` (finfo decides, the allow-list, the workspace limit, sha256,
a GD thumbnail, nosniff), the 21 controllers (`html/blocks/*`, `html/pages/versions/*`, `html/pages/comments/*` + the pane, `mentions.php`, `presence.php`,
`html/files/*` + the door), the views (the editor branch of `page-view`, the slash menu, the mention picker, the stale box, presence, the comment pane and one
discussion, `version.php` with `diff.php`), `html/assets/js/editor.js` (635 lines, vanilla + SortableJS with `forceFallback` so a phone and a proof drag alike),
`editor.css`; the vhost and the dev router learned `/pages/{id}/versions/{n}`; the registry builder learned that URL. **Decisions taken in the build:** one request at
a time per block in the editor (a per-block promise chain — a debounced save never overtakes the type change that followed it); Backspace into a block that holds no
children (a plain heading) lands the children right after it at its level, else under it; Enter on an empty list item leaves the list; `?reader=1` shows an editor the
reader ("Read as a reader" in the page menu — printing, proofs); a `table_row`'s reply is its table's HTML (the editor finds the `<tr>`); a comment notifies with kind
`comment` (the mention row is what makes it reach the mentioned); `presence.php` also sets `last_seen_at`; `block_insert` takes `synced_from` for a copy. **Proof**
`tests/phase3/slice3/run.sh` — **232 checks green** (convert 37, save 25, structure 24, widgets 26, versions 19, comments 29, presence 12, json 27, browser 33) plus
the registry and approvals checks: every box above — the every-type Runbook round-trips byte for byte, mentions and embeds and tables from Markdown, the version
with every save and 409 `stale` with the current HTML, locked and trashed in words, a reader 403, `edit_content` on a row but not its database, split/merge/
nest/un-nest/the database's sentences/drag positions/append after a block/delete with the count, an image with its thumbnail through `/files/{id}` with nosniff and
refused to Ann once she loses view, SVG/.exe/HTML/mis-named refused in words, the workspace's limit, a cover, a synced copy on another page and "cannot see" for Bea,
a table gaining a column on every row, v1 → History → the version page and its diff → restore (v2 `before_restore`, v3 = v1), the deletion category in the registry,
an inline comment with a mention telling Marco, one level, resolve and reopen, the author's edit, own and `full` deletes, the pane and its JSON, two sessions seeing
each other and a save as "changed by", the stale box in the browser, every action under an action token answering the contract, the expert appending and refused in
words, the editor at 1280 (typing saves with the version, the slash menu, a Markdown shortcut, the @ picker, Enter, Backspace, Tab, drag, the comment pane) and at 375
(the handle menu moving a block, comments as a card, the version page), JavaScript off the reader and the notice. The registry reads 25 screens and 58 actions built.
Screenshots `/tmp/sp-shots-s3/`. Slice 2's read proof asks for `?reader=1`.

## Open questions
