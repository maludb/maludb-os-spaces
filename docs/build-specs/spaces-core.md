# Build spec: spaces, membership and sections — THE CRUD EXEMPLAR FOR WORKERS (slice 1)

What exists at the end: a member sees the spaces as cards, joins an open one, asks to join a closed one and is let in by its owner,
makes a space of their own and owns it, adds people, a department's people and agents to it, names other owners, orders its root
pages into sections, archives it when it is done; a Spaces admin opens a private space and is logged doing so. `General` and the
standing departments' spaces are seeded (db/006) — never made by a person. Every screen is cards for named things and a full-page
form; every save is a handler on the kit's shape; every row is logged. This slice fixes the shape every later CRUD screen copies.
Schema: `spaces`, `space_members`, `space_join_requests`, `space_sections`, `sp_member_space_ids()`, `sp_visible_space_ids()`,
`sp_space_level()`, `sp_is_space_owner()`, `sp_space_member_ids()`, `sp_slugify()`, `sp_unique_space_slug()`, `sp_spaces_guard()`,
`sp_space_members_guard()`, `sp_department_space_seed()`, `sp_member_admitted()` (db/006); `sp_position_between()`, `pages.section_id`
(db/007); `sp_notify()` (db/012); `sp_sidebar()`, `sp_wiki_status()` (db/015); the views `mcp_spaces`, `mcp_space_members`,
`mcp_space_join_requests`, `mcp_space_sections`, `mcp_pages`, `mcp_channels`, `mcp_members`, `mcp_departments`, `mcp_activity_log`
(db/017). Never modify them. **The database is the referee**: only an open space reaches everyone (the CHECK), the default space stays
the default and open and nobody leaves it, a space keeps an owner, a guest never owns one, an archived space changes nothing, one
pending request per member and space, the slug is unique — the handlers call the verb inside `sp_guard()` and translate a `P0001`
or a `23505` into its sentence as a 422; PHP decides nothing twice.

## Screens (375 px first; cards for spaces; tables for members; a full-page form; no modals)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `space-list` | `/spaces/?kind=&q=` | cards in three groups: **Mine** (the spaces of `sp_member_space_ids()`), **Open to join** (`kind = open`, not mine; a **Join** button), **Closed** (not mine; **Request to join**, or "requested" when pending); a private space only under Mine; each card: icon, name, kind chip, description's first line, member count, page count, channel count, owner chip when I own it; the admin sees every space with the private ones marked `dark` |
| `space-add` / `space-edit` | `/spaces/new`, `/spaces/{id}/edit` | the form: name, icon (an emoji picker from `mcp_emoji` — a popover, not a modal), description, kind (open · closed · private, with one line on each), member level (view · comment · edit_content · edit · full; edit by default), everyone level (shown for open only; none · view · comment · edit_content · edit; view by default), is a wiki (and its default verification months); `kind`, `is_wiki` and the levels on an existing space are changed here too (`space_update`); the kind change confirms |
| `space-view` | `/spaces/{id}` | the space's home: icon, name, kind chip, description; **Join** / **Request to join** / "pending" / **Leave** by my standing; the **sections** with their root pages as cards (`sp_sidebar()` for a member; `mcp_pages` root pages at `view` for a non-member of an open space), pages with no section last; the **channels** I may see as a list (`mcp_channels`); the **members** (`mcp_space_members`: name, role chip, agent chip, derived chip for a department's); the **wiki status** when `is_wiki` (counts of verified · expired · never from `sp_wiki_status()`, linking to `wiki-view`, slice 2); the owner's buttons: Edit, Members, Sections, Requests (with the pending count), Templates, Archive; the admin opening a **private** space they are not in logs `space.admin_view` once per request |
| `space-members` | `/spaces/{id}/members` | a table: name (agent chip, derived chip), role, joined; **Add**: a member picker (`members_for_pick()`), or a department (`find_live_departments()` — adds every live member of it, one row each), role; **Make owner** / **Remove owner**; **Remove**; the derived members of a department space show no Remove (they leave with the department) |
| `space-sections` | `/spaces/{id}/sections` | the sections in order with their root pages; add a section; rename; drag to reorder (SortableJS — a POST per drop); move a root page to a section or to none (a select per page); delete a section (its pages go to the root) |
| `space-requests` | `/spaces/{id}/requests` | the pending requests (name, message, when): **Approve** / **Decline** each; the decided ones below, greyed |
| `space-templates` | `/spaces/{id}/templates` | the templates of this space and the workspace's as cards (slice 2 builds apply and publish; here the list and links) |

## Making and changing a space
- **Create** (`space_create`): `member`; the slug is `sp_unique_space_slug(name)`; the creator becomes **owner** — the handler inserts
  the `space_members` row with `role = 'owner'` in the same transaction (the space's `#general` is made by the trigger, db/010).
  A guest cannot (`spaces.join` is not theirs — the right check refuses in words). `kind` defaults to the settings' `default_space_kind`.
- **Update** (`space_update`): the owner; name, icon, description, levels, wiki setting; a kind change goes through `space_kind_set`
  (confirmed, `other` for agents) — the form posts it as its own action when the kind changed. `everyone_level` on a non-open space
  is forced to `none` by the form (the CHECK refuses otherwise — shown in words).
- **Archive** (`space_archive`): the owner; `archived_at` set; everything stays readable; writes in it are refused by the page and
  channel guards in their words ("Space "Product" is archived: nothing changes in it"); the card shows `dark` "archived"; General
  cannot be archived (the guard's sentence). **Restore** clears it. **Delete** (`space_delete`): the admin; only archived and with
  no live page (`mcp_pages` count 0) — else "Space "Product" still holds 12 pages; trash them first" (the handler's sentence).
- **The wiki** (`space_wiki_set`): the owner; `is_wiki` and `wiki_default_verify_months`; turning it on gives every existing page
  the creator as `wiki_owner_member_id` where NULL (one UPDATE in the handler); turning it off keeps the owners and states.

## Joining, requesting, leaving
- **Join** (`space_join`): an open space; a `space_members` row `member`; a guest refused in words. **Leave** (`space_leave`): a member
  row deleted; General refused by the guard ("Nobody leaves the default space"); a department space's derived member has no row to
  delete — "You are in Design through the department; leave the department in HR" (the handler's sentence). The last owner cannot
  leave (the guard: "Space "Product" needs an owner: name another owner first").
- **Request** (`space_join_request`): a closed space; one pending per member (the unique index → "You already asked to join Product");
  the owners are told (`sp_notify` kind `join_request`, to each owner). **Withdraw** marks it `withdrawn`.
- **Decide** (`space_join_decide`): an owner; approve → the member row is inserted and the requester told (`join_decided`, "You are in
  Product now"); decline → told the same kind with the owner's word; a decided request cannot be decided again (422).

## Members and owners
- **Add** (`space_member_add`): the owner; a member (a person or an agent — `members_for_pick()`), or a department (every live member of
  it, one `space_members` row each, `ON CONFLICT DO NOTHING`; the count in `did`); `role` member or owner; a guest with role owner is
  refused by the guard. Adding an agent is how an agent joins a space other than General (D10 — no auto-join).
- **Owner set** (`space_owner_set`): the owner; `role` owner ↔ member; the last owner cannot step down (the guard's sentence).
- **Remove** (`space_member_remove`): the owner; the last owner refused; a derived member refused in words (above); `other` for agents.

## Sections and root pages
- **Section save** (`section_save`): the owner; a new section's position is `sp_position_between(last, NULL)`; `after` places it after
  a sibling (`sp_position_between(after, next)`); `section` renames or moves an existing one; a duplicate name → "Product already has
  a section named Docs" (the UNIQUE).
- **Section delete**: its pages' `section_id` → NULL in the same transaction (the FK does it; the handler says "3 pages moved to the
  root" in `did`).
- **Assign** (`page_section_set`): a root page of the space (`parent_page_id IS NULL`, `space_id` = the space — else 422 in words);
  `section` empty → none; `after` orders among the section's pages by `sp_position_between`.

## Files (exactly these)
- `html/spaces/index.php` (`space-list`) · `form.php` (`space-add`, `space-edit`) · `view.php` (`space-view`) · `save.php` · `kind.php` · `archive.php` · `restore.php` · `delete.php` · `join.php` · `leave.php` · `request.php` · `request-withdraw.php` · `request-decide.php` · `wiki.php` · `members.php` (`space-members`) · `sections.php` (`space-sections`) · `requests.php` (`space-requests`) · `templates.php` (`space-templates`)
- `html/spaces/members/add.php` · `remove.php` · `owner.php` · `html/spaces/sections/save.php` · `delete.php` · `assign.php`
- `app/features/spaces/{queries,present,write,handler}.php` (`handler.php`: load the space, the owner gate `require_space_owner()`, `space_log()` — `space_id` on every row)
- `app/views/spaces/{index,form,view,members,sections,requests,templates,partials/space-card,partials/member-row,partials/section-list,partials/page-card}.php`

## Query functions (signatures fixed)
- `find_spaces(PDO, array $filters, int $limit = 100): array` (`mcp_spaces`; `kind`, `q`, `mine`, `include_archived`) · `find_space(PDO, int $id): ?array` (`mcp_spaces` + the owner ids) · `find_space_by_slug(PDO, string $slug): ?array`
- `space_home(PDO, int $spaceId): array` (`sections` with `pages` from `sp_sidebar()` or `mcp_pages`, `channels`, `members`, `wiki` counts) · `space_members(PDO, int $spaceId): array` (`mcp_space_members`) · `space_sections(PDO, int $spaceId): array` (`mcp_space_sections` + root pages) · `space_requests(PDO, int $spaceId, bool $pendingOnly): array` · `my_request(PDO, int $spaceId, int $memberId): ?array`
- `save_space(PDO, ?int $id, array $fields, int $by): int` (INSERT with `sp_unique_space_slug()` + the owner row, or UPDATE) · `set_space_kind(PDO, int $id, string $kind, int $by): void` · `archive_space(PDO, int $id, bool $archive, int $by): void` · `delete_space(PDO, int $id, int $by): void` · `set_space_wiki(PDO, int $id, bool $wiki, ?int $months, int $by): void`
- `join_space(PDO, int $spaceId, int $memberId): void` · `leave_space(PDO, int $spaceId, int $memberId): void` · `request_join(PDO, int $spaceId, int $memberId, ?string $message): int` · `withdraw_request(PDO, int $spaceId, int $memberId): void` · `decide_request(PDO, int $requestId, bool $approve, int $by): array`
- `add_space_member(PDO, int $spaceId, ?int $memberId, ?int $departmentId, string $role, int $by): int` (rows added) · `remove_space_member(PDO, int $spaceId, int $memberId, int $by): void` · `set_space_owner(PDO, int $spaceId, int $memberId, bool $owner, int $by): void`
- `save_section(PDO, ?int $id, int $spaceId, string $name, ?int $after, int $by): int` · `delete_section(PDO, int $id, int $by): int` (pages moved) · `assign_page_section(PDO, string $pageUuid, ?int $sectionId, ?string $afterUuid, int $by): void`
- `space_timeline(PDO, int $spaceId, int $limit = 50): array` (`mcp_activity_log` by `space_id`, in words)

## Handlers (every one: `sp_handler_begin()`; the gate; `sp_guard()`; `log_activity` with `space_id`; `sp_done()`; `HX-Trigger: spaceChanged`)
- `save.php` (`space_create` without `space`, `space_update` with): `require_right('spaces.join')` to create (a guest fails here), `require_space_owner()` to update; `space.create` (`after`: name, kind, member_level, everyone_level, is_wiki) / `space.update` (`sp_diff()`); location `/spaces/{id}`.
- `kind.php` (`space_kind_set`): owner; confirm; `space.kind_set` (`before.kind`, `after.kind`); **other**. `archive.php` / `restore.php`: owner; `space.archive` / `space.restore`; **other** on archive. `delete.php`: `require_admin()`; `space.delete` (name, pages 0); **other**.
- `wiki.php` (`space_wiki_set`): owner; `space.wiki_set` (`after`: is_wiki, verify_months, owners_set); **other**.
- `join.php` / `leave.php`: `require_right('spaces.join')` / own; `space.member_add` / `space.member_remove` (`after.member_id` = me).
- `request.php` / `request-withdraw.php`: `spaces.join`; `space.join_request` (message length, never the words) / `space.join_withdraw`. `request-decide.php`: owner; `space.join_decide` (`after`: request_id, member_id, decision).
- `members/add.php` (`space_member_add`): owner; `space.member_add` (`after`: member_id or department_id, role, rows). `members/remove.php`: owner; `space.member_remove`; **other**. `members/owner.php`: owner; `space.owner_set` (`after`: member_id, owner).
- `sections/save.php` / `delete.php` / `assign.php`: owner; `section.save` (name, section_id) / `section.delete` (pages moved) / `page.section_set` (`entity_uuid` = the page, `after`: section_id).

## Action manifest entries
Screens: `space-list`, `space-add`, `space-view`, `space-edit`, `space-members`, `space-sections`, `space-requests`, `space-templates`.
Actions (17): `space_create`, `space_update`, `space_kind_set`, `space_archive`, `space_restore`, `space_delete`, `space_join`, `space_leave`, `space_join_request`, `space_join_withdraw`, `space_join_decide`, `space_member_add`, `space_member_remove`, `space_owner_set`, `section_save`, `section_delete`, `page_section_set`; plus `space_wiki_set` (listed under slice 2's table, built here). Agent approvals: `space_kind_set`, `space_archive`, `space_delete`, `space_member_remove`, `space_wiki_set` (`other`). `PARTIAL_UPDATE_TARGETS` gains `/spaces/save.php => ['spaces', 'space', 'mcp_spaces', 'space_id']`.

## Activity log events (every row: `space_id`)
`space.create|update|kind_set|archive|restore|delete|wiki_set|member_add|member_remove|owner_set|join_request|join_withdraw|join_decide|admin_view`, `section.save|delete`, `page.section_set` (`entity_uuid`), `screen.view` (`space-view` with `after.space_id`). **No row carries a request's message text or a description** (lengths and ids only).

## Notifications this slice queues (`sp_notify()`; the sender is slice 8)
| Step | Who is told | Kind |
|---|---|---|
| a member asks to join a closed space | every owner of the space | `join_request` |
| the owner decides | the requester, with the decision in the title | `join_decided` |
| a member is added to a space by its owner | the member ("Marco added you to Product") | `share` |

## Status vocabulary
Kind chips: open `success`, closed `secondary`, private `dark`; archived `dark` "archived"; owner chip `primary`; agent chip `info`; derived chip `light` "via department"; request status: pending `warning`, approved `success`, declined `secondary`, withdrawn `light`. Ids: `space-list-mine`, `space-list-open`, `space-list-closed`, `space-card-{id}`, `space-form`, `space-form-field-{name}`, `space-home-sections`, `space-home-channels`, `space-home-members`, `member-row-{id}`, `section-list`, `section-row-{id}`, `request-row-{id}`.

## Out of scope for this slice
Pages and their permissions beyond listing root pages (2), channels beyond listing (4), the templates' apply and publish (2), the wiki's reports (6), sending the notices (8), the admin's spaces list (9).

## Proof (`tests/phase3/slice1/run.sh`: the scratch database `sp_dev1`, members through dev hand-offs, curl with signed action and run tokens, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
The world (`tests/phase3/slice1/lib.php` `spaces_world()`): the fixture's nine members; General and the two department spaces seeded; Marco makes "Product" (closed) and "Design leads" (private); Priya is a member of Product.
- [ ] **Seeds and cards**: General open and default, IT and Accounting closed with their managers as owners; Priya's list shows Mine (General, Design — derived), Open (none), Closed (Product, IT, Accounting with Request); Ann (guest) sees no card; the admin sees every space with the private one marked.
- [ ] **Create**: Marco creates Product (closed) and becomes its owner with a `#general`; the slug `product`, a second "Product" → `product-2`; a guest's create → 403 in words; `everyone_level` on a closed space forced to none; `space.create` logged with `space_id`; the JSON reply carries `record_id` and `location`.
- [ ] **Update, kind, archive**: the owner renames and sets the levels; a member's update → 403; kind open → closed → private through `space_kind_set` (confirmed; an agent's call pauses `other` on the MCP path — Phase 4); General's kind change → the guard's sentence; archive keeps the pages readable and refuses a page save in the archived words; restore; delete refused while pages exist and allowed when empty and archived; the admin alone deletes.
- [ ] **Join, request, leave**: Dana joins General? (already in: idempotent) and an open space; requests Product (one pending — a second → the sentence), withdraws, requests again; Marco told (`join_request`); Marco approves → Dana a member, told; declines Lee → told; Dana leaves Product, cannot leave General (the guard's words), cannot leave Design (derived — the handler's words); Marco cannot leave Product as its last owner.
- [ ] **Members**: add Bea, add Engineering (two rows), add Seamus (agent chip); make Priya owner, step Marco down, then Priya cannot step down as the last; remove Lee; a guest as owner → the guard; `space.member_add` with `rows`.
- [ ] **Sections**: Docs and Decisions made in order, Decisions moved first, a root page assigned and moved between them and to none, a duplicate name → the sentence, Docs deleted → its pages at the root; the space home shows the sections in order with their pages.
- [ ] **Who sees**: Priya's `space-view` of Product with Join absent and Leave present; Dana's with Request; Ann's → 404; the admin's view of Design leads logs `space.admin_view` once; `mcp_spaces` answers each exactly the cards shown.
- [ ] **JSON mode**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}`; `_partial=1` on `space_update` keeps the untouched fields; 422 `{error: {code: invalid, fields}}`; the expert (run token + relay) creates a closed space and adds a member to it, `source agent`.
- [ ] **375 × 740 and 1280 × 800**: the three card groups stack; the form's emoji picker is a popover; the members table scrolls within the page, not the viewport; every control ≥ 44 px, `scrollWidth` = viewport, no console errors; JavaScript off joins, requests and saves; the registry reads 8 screens and 18 actions built.

## Open questions
