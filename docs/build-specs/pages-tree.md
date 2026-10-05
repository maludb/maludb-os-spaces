# Build spec: pages — the tree, sharing, the wiki, the trash, templates, the public page (slice 2)

What exists at the end: a member makes a page in a space, under a page, or privately; reads any page they may see rendered from its
blocks (read-only — **the editor that changes blocks in place is slice 3**); moves, duplicates, locks, favorites, trashes and restores
it; shares it with a member, a department or a guest; restricts it; a Space owner verifies wiki pages; the admin publishes a page to
the web and the public reads it at `/p/{token}`; templates are applied and published. The permission tree is the database's; this
slice shows it and never re-implements it.
Schema: `pages`, `page_permissions`, `page_links`, `page_favorites`, `page_recents`, `page_versions`, `page_publications`,
`sp_page_level()`, `sp_can_see_page()`, `sp_can_edit_page()`, `sp_has_full_page()`, `sp_visible_page_ids()`, `sp_page_restrict()`,
`sp_page_unrestrict()`, `sp_page_restore()`, `sp_public_page_lookup()`, `sp_public_page_ids()`, `sp_page_ancestors()`,
`sp_page_visited()`, `sp_compute_permission_root()` (db/007); `sp_page_create()`, `sp_page_move()`, `sp_page_trash()`,
`sp_page_purge()`, `sp_page_set_locked()`, `sp_page_duplicate()` (db/008); `sp_page_tree()`, `sp_page_markdown()`,
`sp_rich_text_markdown()` (db/014); `sp_sidebar()`, `sp_page_children()`, `sp_backlinks()`, `sp_wiki_status()`,
`sp_pages_changed_since()`, `sp_page_permissions_explained()` (db/015); `sp_version_save()` (db/016); `sp_notify()` (db/012); the views
`mcp_pages`, `mcp_page_permissions`, `mcp_page_links`, `mcp_page_versions`, `mcp_page_favorites`, `mcp_page_recents`,
`mcp_page_publications`, `mcp_trash`, `mcp_members`, `mcp_departments`, `mcp_spaces`, `mcp_comments`, `mcp_activity_log` (db/017).
Never modify them. **The database is the referee**: a page lives in its parent's space, never under its own subpage; a private page has
an owner and its subpages the same; a guest never owns a space page; a locked or trashed page refuses changes; the first explicit share
carries the space's default along and `sp_page_restrict()` removes it; the trash cascades to the subtree; one live publication per page;
a published page in the trash is revoked — the handlers call the verb inside `sp_guard()` and show the `P0001` sentence as a 422.

## Screens (a page is read at 375 px first; cards for pages in a list; a full-page form; no modals)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `page-list` | `/pages/?space=&q=&kind=&changed=` | cards: icon, title, space (or "Private", or "Shared with me"), breadcrumb, kind chip (page · database · row), last edited by and when, verification badge in a wiki; `q` searches titles (`mcp_pages` `plain_title ILIKE`); `changed` = 1 · 7 · 30 days; **New page** |
| `page-add` | `/pages/new?space=&parent=&template=&private=` | the form: title, icon, where (a space's root · under a page — a page picker over `find_pages` · my private pages), a template (`find_templates`), the body as Markdown (optional; `block_append` after create — slice 3 owns the converter; here the field posts to `page_create`'s `markdown`) |
| `page-view` | `/pages/{id}` | **the reader**: breadcrumb (`sp_page_ancestors()`), icon, cover, title, a row's properties (slice 5's panel; a plain list here), the block tree rendered to HTML by `app/richtext/render.php` (every adopted type; a synced copy shows its original's children or "a synced block you cannot see"; `link_to_page` and `child_page` as links; a `child_database` as a link to slice 5's view), comments count (slice 3 renders them), the **page menu** (Share · Publish · Lock/Unlock · Verify · Favorite · Move · Duplicate · Export (slice 8) · History · Trash — each by the caller's level and rights); for someone with `edit` the body becomes the editor in slice 3 — until then the reader with an "Edit title" link; visiting records `sp_page_visited()` |
| `page-edit` | `/pages/{id}/edit` | the form: title, icon, cover (an image attachment id — slice 3's `file_upload`; here the field) |
| `page-share` | `/pages/{id}/share` | `sp_page_permissions_explained()` as a table: source (space · space owners · inherited from "X" · this page), principal, level; add a member or a department at a level; add a guest (`share.guest`); remove a row; **Restrict access** / **Unrestrict**; the public link's state with a link to `page-publish` |
| `page-move` | `/pages/{id}/move` | a destination picker: a space's root, a page (`find_pages` within spaces I edit), my private pages; after which sibling |
| `page-history` | `/pages/{id}/history` | the versions (`mcp_page_versions`): number, when, why, by whom; **Save a version now**; open one (`page-version`, slice 3); restore one (slice 3's `version_restore`) |
| `page-publish` | `/pages/{id}/publish` | the publication's state; publish with subpages, noindex, a database's layout, public properties; the link (`SP_PUBLIC_BASE_URL/p/<token>`, shown once at publish — the hash is stored); rotate; unpublish; views and last opened |
| `page-trash` | `/pages/trash` | `mcp_trash` as cards: title, space, who trashed it, when, purged at; **Restore**, **Delete for good**; **Empty** (`trash_purge`) for `trash.purge` |
| `template-list` | `/templates/` | the templates as cards (`is_template`, the workspace's General and my spaces'): icon, title, kind chip, space; **Use** (→ `page-add?template=`); a space owner's **Stop being a template** |
| `wiki-view` | `/spaces/{id}/wiki` | `sp_wiki_status()`: a table of the wiki's pages — title, owner, state badge, verified when, until, days since edit — expired first; **Verify** on mine; the reports (stale, orphans, broken, duplicates) are slice 6's and link there |
| `public-page` | `/p/{token}`, `/p/{token}/{page}`, `/p/{token}/files/{id}` | the public door (`html/p.php`): no session; `sp_public_page_lookup(sha256(token))` as the writer with no acting member; the page rendered by the same reader, no comments, only `public_properties`; subpages listed when `include_subpages` (`sp_public_page_ids()`); images through `/p/{token}/files/{id}` (only attachments of those pages); `noindex` meta by the row; rate-limited (60/min/IP); `views` and `last_viewed_at` bumped; logged `page.public_view` with `source = 'portal'`, `publication_id` — never the token |

## Making, moving, duplicating, trashing
- **Create** (`page_create`): `space` → `sp_page_create(space, NULL, …)` needs `member` of the space with `pages.write` (a non-member of an
  open space at `everyone_level = edit` may too — `sp_space_level()` ≥ edit); `parent` → `require_page_level(parent, 'edit')`; neither →
  a private page (`pages.private`; a guest refused in words). A `template` → `sp_page_duplicate(template, space, parent, title)` instead.
  `markdown` → one `block_append` call after create (slice 3's converter; until built, the field is stored as one `paragraph` block of
  plain text). Location `/pages/{uuid}`.
- **Update** (`page_update`): `edit`; title (rich text from the plain field — `sp_rich_text()`), icon, cover; a locked page → the guard's words.
- **Move** (`page_move`): `full` on the page and `edit` at the destination; `sp_page_move(page, parent | NULL, space)`; `private` → `sp_page_move(page, NULL, NULL)`
  (the owner's); a row → "A database row stays in its database"; under its own subpage → the guard's words; `after` reorders among siblings by `position`.
- **Duplicate** (`page_duplicate`): `view` on the page and `edit` at the destination; `sp_page_duplicate()`; the copy is titled "<title> (copy)" unless given.
- **Trash** (`page_trash`): `full`; `sp_page_trash()`; the subtree goes with it, its edge block removed, a publication revoked; **deletion** for agents.
  **Restore** (`page_restore`): `sp_page_restore()` — to the root when the parent is still trashed. **Delete for good** (`page_delete`): `full` or
  `trash.purge`; `sp_page_purge()`; **deletion**. **Empty the trash** (`trash_purge`): every visible trashed page (or a space's); the count in `did`.
- **Lock** (`page_lock`): `full`; `sp_version_save(page, 'lock')` first, then `sp_page_set_locked()`; **other** for agents.
- **Favorite** (`page_favorite`): `view`; a `page_favorites` row at `sp_position_between(last, NULL)` or removed.

## Sharing, restricting, guests
- **Share** (`page_share_member`): `full`; `INSERT INTO page_permissions` (member · agent — `principal_kind` from `member_kind` — · department) at a level;
  the trigger carries the space's default along on the first explicit row (so nobody loses the page); the recipient told (`share`, by the trigger);
  a level on a private page's owner is refused ("the owner already has full"). **Unshare**: `DELETE` the row; the last explicit row leaves the
  everyone rows standing (the page is simply back to the space's default once those alone remain — the handler deletes them too when nothing else is left).
- **Share to a guest** (`share_guest`): `full` and `share.guest`; the member must be a guest (`sp_member_is_guest()` — else "Priya is a member: use Share");
  `principal_kind = 'guest'`; **external_send** for agents.
- **Restrict** (`page_restrict`): `full`; `sp_page_restrict()`; the page says "Restricted: only the people named here"; **Unrestrict**: `sp_page_unrestrict()`.
- **The share screen** reads `sp_page_permissions_explained()` only; it never computes a level in PHP.

## Publishing (D13)
- **Publish** (`page_publish`): `publish.web`; token = `bin2hex(random_bytes(24))`; the row stores `sha256(token)`; the link is shown once and
  put in `did` (never logged); subpages, noindex, layout, public properties; a second publish → "already published: rotate or unpublish" (the unique index).
- **Rotate** (`page_publish_rotate`): the old row revoked, a new row and token; **Unpublish**: revoked. All three **external_send** for agents.
- **The public door** renders `sp_page_markdown()` through the reader (`render.php` over `sp_page_tree()`), never through a session; a revoked or
  trashed page → 404 with the business's name and nothing else; the admin's `published_pages` lists them.

## The wiki (D12)
- **Verify** (`page_verify`): the wiki owner or the space owner; `verification_state = 'verified'`, `verify_until` by `months` (the guard stamps
  who and when); the badge shows the date; **expired is a badge, nothing is hidden**. **Owner set** (`page_owner_set`): `full`; the new owner told (`share` kind, "You now own …").
- `wiki-view` lists `sp_wiki_status(space)`; the Librarian's reports are slice 6.

## Templates
- **Apply** (`template_apply`): `edit` at the destination; `sp_page_duplicate(template, space, parent, title, false)`; location the new page.
- **Publish as a template** (`template_publish`): the owner of its space; `is_template` on/off; a template leaves the sidebar and the search (db/013 skips it);
  **other** for agents. The ten seeded templates belong to General.

## The reader (`app/richtext/render.php` — shared with slice 3's editor, read-only here)
- `render_blocks(array $tree, array $opts): string` renders `sp_page_tree()`'s JSON to HTML: every adopted type, nesting, numbered lists counted
  per sibling group, `to_do` as a disabled checkbox, `toggle` as `<details>`, `callout` with its icon and colour class, `code` with the language class,
  `table` as a `<table>` (header row by `has_column_header`), `column_list` as a row of columns (stacked at 375 px), `image`/`file`/`pdf`/`video`/`audio` by
  `/files/{id}` or the URL, `bookmark`/`embed`/`link_preview` as a card with the URL (an embed's iframe only for `allowed_embed_hosts`), `equation` as text in
  `<code>` (KaTeX is Extended), `synced_block` by its original's children, `link_to_page`/`child_page`/`child_database` as links by title, `unsupported` as a muted
  note, rich text runs with bold/italic/code/strike/underline/colour and links; mentions as chips linking to the member, page or channel. `render_rich_text(array $runs): string`.
- Output through `e()`; no inline scripts; `data-block-id` on every block (slice 3 hangs the editor on it).

## Files (exactly these)
- `html/pages/index.php` (`page-list`) · `form.php` (`page-add`, `page-edit`) · `view.php` (`page-view`) · `share.php` (GET `page-share`, POST `page_share_member`) · `move.php` (GET `page-move`, POST `page_move`) · `history.php` (`page-history`) · `publish.php` (GET `page-publish`, POST `page_publish`) · `trash.php` (GET `page-trash`, POST `page_trash`) · `save.php` · `duplicate.php` · `restore.php` · `purge.php` · `trash-purge.php` · `lock.php` · `share-guest.php` · `unshare.php` · `restrict.php` · `unrestrict.php` · `favorite.php` · `publish-rotate.php` · `unpublish.php` · `template-apply.php` · `template-publish.php` · `verify.php` · `owner.php`
- `html/templates/index.php` (`template-list`) · `html/spaces/wiki.php` (`wiki-view`) · `html/p.php` (`public-page`) · `html/files.php` (the gated attachment door, `sp_can_see_attachment()` — slice 3 fills upload; the door is here)
- `app/features/pages/{queries,present,write,handler}.php` (`handler.php`: load the page, `require_page_level()`, `page_log()` — `entity_uuid`, `space_id` on every row) · `app/features/public/{queries,render}.php` · `app/richtext/render.php`
- `app/views/pages/{index,form,view,share,move,history,publish,trash,partials/page-card,partials/breadcrumb,partials/page-menu,partials/permission-table,partials/version-row}.php` · `app/views/templates/index.php` · `app/views/spaces/wiki.php` · `app/views/public/{page,layout,notfound}.php`

## Query functions (signatures fixed)
- `find_pages(PDO, array $filters, int $limit = 100): array` (`mcp_pages`; `space`, `q`, `kind`, `changed`, `parent`) · `find_page(PDO, string $uuid): ?array` (`mcp_pages` + `my_level`, breadcrumb) · `page_tree_json(PDO, string $uuid): array` (`sp_page_tree()`) · `page_children(PDO, string $uuid): array`
- `create_page(PDO, ?int $spaceId, ?string $parentUuid, string $title, ?string $icon, ?string $templateUuid, int $by): string` · `update_page(PDO, string $uuid, array $fields, int $by): void` · `move_page(PDO, string $uuid, ?string $parentUuid, ?int $spaceId, bool $private, ?string $afterUuid, int $by): void` · `duplicate_page(PDO, string $uuid, ?string $title, ?string $parentUuid, int $by): string` · `trash_page(...)` · `restore_page(...)` · `purge_page(PDO, string $uuid, int $by): int` · `purge_trash(PDO, ?int $spaceId, int $by): int` · `set_page_lock(PDO, string $uuid, bool $locked, int $by): void` · `set_favorite(PDO, string $uuid, int $memberId, bool $on): void`
- `page_permissions(PDO, string $uuid): array` (`sp_page_permissions_explained()`) · `share_page(PDO, string $uuid, string $kind, ?int $principalId, string $level, int $by): void` · `unshare_page(...)` · `restrict_page(PDO, string $uuid, bool $restrict, int $by): void`
- `publication(PDO, string $uuid): ?array` · `publish_page(PDO, string $uuid, array $opts, int $by): string` (the token) · `rotate_publication(...)`: string · `unpublish_page(...)` · `public_lookup(PDO, string $token): ?array` · `public_page_ids(PDO, int $publicationId): array` · `bump_public_views(PDO, int $publicationId): void`
- `verify_page(PDO, string $uuid, ?int $months, int $by): void` · `set_page_owner(PDO, string $uuid, int $memberId, int $by): void` · `wiki_status(PDO, int $spaceId): array`
- `find_templates(PDO, ?int $spaceId): array` · `apply_template(PDO, string $templateUuid, ?int $spaceId, ?string $parentUuid, ?string $title, int $by): string` · `set_template(PDO, string $uuid, bool $on, int $by): void`
- `trash_list(PDO, ?int $spaceId): array` (`mcp_trash`) · `page_versions_list(PDO, string $uuid): array` · `page_timeline(PDO, string $uuid, int $limit = 50): array`

## Handlers (every one: `sp_handler_begin()`; `require_page_level()` or the right; `sp_guard()`; `log_activity` with `entity_uuid` and `space_id`; `sp_done()`; `HX-Trigger: pageChanged`)
- `sp_done()` is widened by this slice to `int|string|null $recordId` (a UUID record id in `record_id` and `location`) — the kit's one change, in `app/handler.php`.
- `save.php` (`page_create` without `page`, `page_update` with): the gate as above; `page.create` (`after`: title, space_id, parent_page_id, kind, template) / `page.update` (`sp_diff()` of title, icon, cover_attachment_id).
- `move.php`: `full` + `edit` at the destination; `page.move` (`before.parent_page_id`, `before.space_id`, `after.…`). `duplicate.php`: `page.duplicate` (`after.source_page_id`).
- `trash.php` / `restore.php` / `purge.php` / `trash-purge.php`: `page.trash` (subtree_count) **deletion** / `page.restore` / `page.delete` **deletion** / `trash.purge` (count) **deletion**.
- `lock.php`: `page.lock` (locked, version_no) **other**. `favorite.php`: `page.favorite` (favorite).
- `share.php` / `share-guest.php` / `unshare.php` / `restrict.php` / `unrestrict.php`: `page.share` (principal_kind, principal_id, level) / `page.share_guest` **external_send** / `page.unshare` / `page.restrict` / `page.unrestrict`.
- `publish.php` / `publish-rotate.php` / `unpublish.php`: `publish.web`; `page.publish` (include_subpages, noindex, layout) / `page.publish_rotate` / `page.unpublish` — all **external_send**; never the token.
- `template-apply.php`: `page.template_apply` (template_page_id). `template-publish.php`: `page.template_publish` (template) **other**. `verify.php`: `page.verify` (months, verify_until). `owner.php`: `page.owner_set` (member_id). `html/spaces/wiki.php`'s POST is slice 1's `space_wiki_set`.
- `html/p.php` is not a handler: GET only, no session, `page.public_view` with `source = 'portal'`.

## Action manifest entries
Screens: `page-list`, `page-add`, `page-view`, `page-edit`, `page-share`, `page-move`, `page-history`, `page-publish`, `page-trash`, `template-list`, `wiki-view`, `public-page`.
Actions (24): `page_create`, `page_update`, `page_move`, `page_duplicate`, `page_trash`, `page_restore`, `page_delete`, `trash_purge`, `page_lock`, `page_share_member`, `share_guest`, `page_unshare`, `page_restrict`, `page_unrestrict`, `page_favorite`, `page_publish`, `page_publish_rotate`, `page_unpublish`, `template_apply`, `template_publish`, `page_verify`, `page_owner_set` (and `space_wiki_set`, slice 1's). Agent approvals: `page_trash`, `page_delete`, `trash_purge` (`deletion`); `share_guest`, `page_publish`, `page_publish_rotate`, `page_unpublish` (`external_send`); `page_lock`, `template_publish` (`other`). `PARTIAL_UPDATE_TARGETS` gains `/pages/save.php => ['pages', 'page', 'mcp_pages', 'page_id']` (a UUID id).

## Activity log events (every row: `entity_uuid` = the page, `space_id`)
`page.create|update|move|duplicate|trash|restore|delete|lock|favorite|share|share_guest|unshare|restrict|unrestrict|publish|publish_rotate|unpublish|template_apply|template_publish|verify|owner_set|view|public_view`, `trash.purge`, `screen.view`. **No row carries a page's text, a Markdown body or a public token** (titles, ids, levels, counts).

## Notifications this slice queues (`sp_notify()`; the sender is slice 8)
| Step | Who is told | Kind |
|---|---|---|
| a page shared to a member or a department (the db/012 trigger) | the member, the department's members | `share` |
| a page shared to a guest | the guest | `share` |
| a wiki page's owner changed | the new owner | `share` |

## Status vocabulary
Kind chips: page none, database `info`, row `light`; verification badge: verified `success` with the date, expired `warning`, none `secondary` "unverified"; locked `dark` lock icon; published `primary` globe; restricted `warning` "restricted"; trashed `dark` with "purged on …". Ids: `page-list`, `page-card-{uuid}`, `page-form`, `page-form-field-{name}`, `page-breadcrumb`, `page-title`, `page-body`, `page-menu`, `permission-table`, `permission-row-{n}`, `version-row-{id}`, `trash-card-{uuid}`, `template-card-{uuid}`, `wiki-row-{uuid}`.

## Out of scope for this slice
Editing blocks in place, comments, versions' diff and restore, file upload (3); channels (4); databases' views and the properties panel (5); the wiki's reports and search (6); exports and imports (8); the admin's lists (9).

## Proof (`tests/phase3/slice2/run.sh`: the scratch database `sp_dev2`, members through dev hand-offs, curl with signed action and run tokens, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
The world (`tests/phase3/slice2/lib.php` `pages_world()`): slice 1's world; Product (closed; Marco owner, Priya member) holds "Product handbook" › "Pricing" › "Pricing 2027"; Bea keeps a private page "Bea's notes"; General is a wiki.
- [ ] **Make**: Marco creates a root page in Product, Priya a subpage under Pricing, Bea a private page; Dana (not in Product) → 403 in words; Ann (guest) → 403; a page from the Meeting notes template has its four headings; `page.create` logged with `entity_uuid` and `space_id`; the JSON reply's `record_id` is the UUID and `location` ends in it.
- [ ] **Read**: the reader renders every block type of a seeded "Every block" page (headings, lists numbered, to-dos, callout with icon, code with class, table with header, columns stacked at 375, image by `/files/`, synced copy showing the original, child pages as links, unsupported muted); `data-block-id` on each; the breadcrumb; a visit lands in recents; Dana's `page-view` of Product → 404 "Page not found."
- [ ] **Move, duplicate, lock, favorite**: Pricing moved to General's root and back under the handbook (the subtree follows, a new edge block); under its own subpage → the guard's words; a row → the guard's words; the duplicate copies blocks and the subpage; lock refuses a title change in words and saves a `lock` version; unlock; favorite on and off, in the sidebar.
- [ ] **Trash**: trash Pricing → Pricing 2027 goes with it, the edge gone, `mcp_trash` lists Pricing only with its purge date; restore brings both back; trash the handbook, restore Pricing alone → at the root; purge for good; Empty the trash by `trash.purge` only; an agent's `page_trash` pauses `deletion` on the MCP path (Phase 4).
- [ ] **Share**: Pricing shared to Engineering at comment — Dana sees Pricing and Pricing 2027 at comment, not the handbook; the `everyone_in_space` row appeared; Priya keeps edit; shared to Ann at view — her sidebar shows it under Shared, her `mcp_members` names Marco and herself; unshare Ann → gone; restrict Pricing 2027 → Priya loses it, Marco keeps full, Engineering keeps comment; unrestrict → Priya back; the share screen explains each row's source; a private page shared to Priya at edit shows under her Shared.
- [ ] **Publish**: the admin publishes the handbook with subpages — the link shown once, the hash stored; `/p/<token>` renders without a session, lists the subpages, serves an image through its door, sets `noindex`; a second publish → the sentence; rotate → the old link 404s; a trashed page's link 404s; 61 hits in a minute → 429; `page.public_view` logged as `portal` without the token; Marco (no `publish.web`) → 403.
- [ ] **Wiki and templates**: Priya verifies her page (3 months), the badge and `verify_until`; Marco verifies anyone's in Product; Dana → 403; owner set tells the new owner; expired shown, nothing hidden; `wiki-view` orders expired first; Marco publishes a page as a template — gone from the sidebar and the search, present in the gallery; apply it in General.
- [ ] **JSON mode**: every handler under a signed action token answers the contract; `_partial=1` on `page_update` keeps icon and cover; the expert (run token + relay) creates a page in General and shares it to Priya; its `share_guest` answers `pending_approval` on the MCP path (Phase 4).
- [ ] **375 × 740 and 1280 × 800**: the reader at both; the page menu a popover; the share table scrolls within the page; the public page at 375; every control ≥ 44 px, `scrollWidth` = viewport, no console errors; JavaScript off creates, shares and publishes; the registry reads 12 screens and 24 actions built.

## Open questions

## Built and proven (2026-10-05)
Built exactly as the Files list says: `app/richtext/render.php` (`render_blocks()`, `render_rich_text()` — every adopted type, consecutive list items grouped into
one list, numbered per sibling group, a toggleable heading and a toggle as `<details>`, columns as a grid stacked at 375 px, a table with its header row, an embed's
iframe only for `allowed_embed_hosts`, mentions as chips, `data-block-id` on every block), `app/features/pages/{handler,queries,present,write}.php`,
`app/features/public/{queries,render}.php`, the 26 controllers under `html/pages/` (`edit.php` is the vhost's name for the UUID record's form), `html/templates/index.php`,
`html/spaces/wiki.php` (GET the wiki's status, POST slice 1's `space_wiki_set`), `html/p.php`, the views with their five partials and the public layouts,
`PARTIAL_UPDATE_TARGETS['/pages/save.php']` (a UUID id), the reader's styles. **Decisions taken in the build:** a page with neither `space` nor `parent` is private
(`pages.private`); `markdown` on create seeds one paragraph block (slice 3's converter replaces that); a `space` the caller cannot see answers 404 (the view's truth — a
guest gets 404 where the spec said 403); a page moved into a space where the mover holds only edit is no longer theirs to move again (the database's rule — the proof
has the admin move it back); the trash's cascade stamps `archived_via` with the page that was trashed, so restoring a subpage alone brings back only what went with
*it*; `page_delete` on a live page is refused in words; the public door's rate limit counts the trail's `page.public_view` rows per address (60 a minute → 429); the
public door sets `app.member_id` to nothing and reads the base tables through `sp_public_page_lookup()`; a `navigate`-free reply partial. **The reader's title
helper** fetches every page a tree refers to in one query (`page_titles_in_tree()`), so a link to a page the reader cannot see says so. **Proof** `tests/phase3/slice2/run.sh`
— **271 checks green** (make 20, read 30, move 28, trash 19, share 35, publish 28, wiki 29, json 44, browser 38) plus the registry and approvals checks: every box above
— the world (the handbook › Pricing › Pricing 2027, Bea's private notes, General a wiki), create in a space, under a page and privately with the refusals in words, a page
from the Meeting notes template with its four headings, every block type rendered (a synced copy of an original the reader cannot see says so, never the words), the
breadcrumb, recents, `page.view`, move / reorder / private / duplicate with the subtree / lock with its version / favorite in the sidebar, the trash's cascade and `mcp_trash`,
restore under a trashed parent, purge and Empty by `trash.purge`, share to a department (the everyone row carried along), a guest's share and her sidebar and
`mcp_members`, restrict and unrestrict, the share screen's sources, a private page shared, publish (the link once, the hash stored, `page.publish` without the token),
the public door with subpages, an image through its own door, noindex, rotate, a trashed page's link, unpublish, the 429, `page.public_view` as `portal`, verify (3 months),
the owner's and the space owner's rights, an expired badge, `wiki-view` ordered, a template made, hidden from the sidebar and the list, applied in General, every handler
under an action token answering `{ok, did, record_id, location, refresh}` with a UUID `record_id`, `_partial=1` keeping the icon, the expert making a page in General and
sharing a private page of its own (a General page it holds at edit, not full, exactly as a person), the screens at 375 and 1280 (the page menu a popover, the share table
within the page, the public page bare at 375, JavaScript off creating, sharing and publishing). The registry reads 12 screens and 22 actions of this slice built (24 screens,
45 actions in all). Screenshots `/tmp/sp-shots-s2/`. Phase 2's `gates` and `vhost` proofs were updated for the screens slices 1 and 2 made real.
