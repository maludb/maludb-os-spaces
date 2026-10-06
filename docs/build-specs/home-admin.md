# Build spec: home, the admin pages and the trail (slice 9)

What exists at the end: the Home every person lands on is real — nine regions filled from the slices' own query functions, each shown only to
who has it; the admin pages — settings, every space (private ones logged when opened), the published pages, retention, everyone's trash; the
emoji list; `settings`, `tokens` and the own trail made whole. Nothing new in the schema.
Schema: `sp_settings`, `emoji_shortcodes` (db/005); `sp_unread()`, `sp_activity_feed()`, `sp_pages_changed_since()`, `sp_wiki_status()`,
`sp_unanswered_questions()`, `sp_sidebar()` (db/015); the views `mcp_spaces`, `mcp_space_join_requests`, `mcp_page_favorites`, `mcp_pages`,
`mcp_page_publications`, `mcp_trash`, `mcp_channels`, `mcp_agent_dispatches`, `mcp_notifications`, `mcp_messages`, `mcp_emoji`, `mcp_settings`,
`mcp_access_tokens_mine`, `mcp_activity_log` (db/017); `sp_page_purge()`, `sp_page_restore()` (db/007, db/008). Never modify them. **The database is
the referee**: a region shows what its function answers the caller; the settings' bounds are the table's CHECKs (a bad value is the database's
sentence as a 422); a private space the admin opens is the admin's to see (D2) — and the log says so.

## Screens (375 px is the design for Home; the admin pages usable at 375 px, designed at 1280 px)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `home` | `/` | **everyone**: **Unread** (channels with unread, the first unread line, the count; DMs first — `sp_unread()` ⨝ `mcp_channels`, the line from `mcp_messages`), **Waiting for me** (mentions and replies — `sp_activity_feed()` since the last visit, newest five), **Recently edited** (in my spaces — `sp_pages_changed_since()` 7 days, ten), **Favorites** (`mcp_page_favorites` ⨝ `mcp_pages`), **Needs my verification** (wiki pages I own expired or never verified — `sp_wiki_status()` filtered to me), **The Librarian's note** (the last message by the Librarian in the admin channel — `mcp_messages` by `author_member_id`, the channel from the settings; shown to its channel's members). **A space owner** (`sp_sidebar().spaces[].is_owner`): **Pending joins** (`mcp_space_join_requests` pending on my spaces), **Unanswered in my spaces** (`sp_unanswered_questions()` for my spaces' channels). **The admin**: **Dispatches pending or failed** (`mcp_agent_dispatches`), **Published pages** (count, last opened), **Trash** (count, the next purge). Each region a card with a link to its page; an empty region says what will appear there |
| `trail` | `/trail?space=&channel=&message=&page=` | Phase 2's own-trail page (`html/trail.php`, moved from `/activity` when slice 6 took that URL for the feed): my rows, or one record's history (`mcp_activity_log` decides what I may see); filters by action prefix and period; sentences (`activity_sentence()`) |
| `settings` / `tokens` | `/settings/`, `/settings/tokens/` | slice 6's prefs and status made whole (the time zone shown with HR's note); tokens: label, scope, last used, revoke; mint shows the value once |
| `admin-settings` | `/admin/settings` | one form over `sp_settings` (every column but `id`/`updated_at`): business name; default space kind and member/everyone levels; snapshot minutes; version, trash retention days; stale days; unanswered hours; verification default months; attachment limit (MB in the form, bytes in the row); embed hosts (one per line); public pages noindex; public base URL; digest hour; week start; time zone; group DM size; away minutes; the admin channel name; agents join General; the **emoji** list beneath (add, remove) |
| `admin-spaces` | `/admin/spaces?kind=&archived=` | every space (`mcp_spaces` — the admin sees all), the private ones marked `feather-lock`; counts; archive, restore, delete (slice 1's actions); **opening a private space's page from here logs `space.admin_view`** (`space-view` does it when the caller is not a member and is the admin) |
| `admin-published` | `/admin/published` | every published page (`mcp_page_publications` ⨝ `mcp_pages`): title, space, published by and when, subpages, noindex, views, last opened; **Rotate** and **Unpublish** (slice 2's actions) |
| `admin-retention` | `/admin/retention` | every channel with a retention (`mcp_channels` where `retention_days`), and those without, by space; set one (slice 8's `retention_set`); the version and trash retentions from the settings with a link to change them |
| `admin-trash` | `/admin/trash?space=` | everyone's trash (`mcp_trash` as the admin): title, space, who trashed, when purged; Restore, Purge, **Purge all** (slice 2's `page_restore`, `page_delete`, `trash_purge`) |

## Home
- `home_summary(PDO, int $memberId): array` — Phase 2's null-keyed shape, every key filled by its function, each honouring its right and the caller's
  world; one query per region; a region whose slice is not built stays null (the view names the slice). Keys: `unread` (slice 4's `my_unread()`),
  `mentions` (slice 6's `activity_feed()` since the member's last home visit — `page_recents`? no: `$_SESSION['home_seen_at']`, the previous visit),
  `recent_pages` (slice 2's `pages_changed_since()`), `favorites` (slice 2's `find_favorites()`), `verification_due` (slice 6's `wiki_report()` filtered to the
  caller's pages across wiki spaces), `librarian_note` (slice 7's `last_librarian_note()`), `pending_joins` (slice 1's `pending_join_requests()` for the
  spaces I own), `unanswered` (slice 6's for my spaces), `admin` (`admin_summary()`: dispatch counts, published count and last opened, trash count and
  next purge), `sidebar` (Phase 0's), `may` (Phase 2's).
- The order on a phone: Unread, Waiting for me, Needs my verification, Pending joins, Recently edited, Favorites, the note, Unanswered, the admin cards.
- Home logs `screen.view` and sets `home_seen_at`; it never writes anything else.

## The admin pages
- **`settings_save`** (`settings.manage`): `any field of the settings`; `sp_int()` for each number within the CHECK's bounds (the sentence names the field
  when the database refuses); `allowed_embed_hosts[]` from lines, lower-cased, no scheme; `max_attachment_bytes` from MB; log `settings.save`
  (`before`/`after` of the changed fields — `sp_diff()`); **other** for an agent; `HX-Trigger: settingsChanged`.
- **`emoji_save`** / **`emoji_delete`** (`settings.manage`): a shortcode `^[a-z0-9_+-]{1,40}$`, one emoji (1–16 chars), keywords; a seeded one may be
  replaced; log `emoji.save` / `emoji.delete`.
- **`admin-spaces`**: the list links to `space-view`; `space-view` (slice 1) logs `space.admin_view` (`space_id`) when `sp_is_admin()` and the admin is
  not a member of a private space — once per space per session.
- **`admin-trash`**: Purge all = `trash_purge` with no space; the page shows what Purge all would remove before the confirm.

## The trail
`html/trail.php` (Phase 2's `activity.php` renamed; slice 6 took `/activity` for the feed): my own rows newest first, or a record's history when
`space`, `channel`, `message` or `page` is given (`mcp_activity_log` by the audit keys — `entity_uuid` for a page); filters `action` (a prefix) and
`period` (1, 7, 30, 90 days); 50 a page; `activity_sentence()` for every event of the manifest (an agent's row chipped, a cron row "the worker");
a record link per row (`activity_record_link()`); JSON lists the same rows.

## Files (exactly these)
- `html/index.php` (real) · `app/features/home/{queries,present}.php` · `app/views/home/{dashboard,partials/unread,partials/waiting,partials/recent,partials/favorites,partials/verification,partials/note,partials/joins,partials/unanswered,partials/admin}.php`
- `html/trail.php` (from Phase 2's `activity.php`) · `app/features/activity/{queries,present}.php` (whole: every sentence) · `app/views/activity/{trail,partials/rows}.php`
- `html/admin/settings.php` (`admin-settings`, `settings_save`) · `html/admin/emoji.php` · `html/admin/emoji-delete.php` · `html/admin/spaces.php` · `html/admin/published.php` · `html/admin/retention.php` · `html/admin/trash.php`
- `app/features/admin/{queries,present,write}.php` · `app/views/admin/{settings,spaces,published,retention,trash,partials/emoji-list,partials/space-row,partials/trash-row}.php`
- `html/settings/index.php`, `html/settings/tokens/*.php` (whole — Phase 2's)

## Query functions (signatures fixed)
- `home_summary(PDO, int $memberId): array` · `admin_summary(PDO): array` · `last_librarian_note(PDO): ?array` · `pending_join_requests(PDO, int $ownerId): array`
- `find_settings(PDO): array` · `save_settings(PDO, array $fields, int $by): array` (`['changed' => [...]]`) · `find_emoji(PDO): array` · `save_emoji(PDO, string $shortcode, string $emoji, array $keywords): void` · `delete_emoji(PDO, string $shortcode): void`
- `admin_spaces(PDO, array $f): array` · `published_pages(PDO): array` · `retention_overview(PDO): array` · `admin_trash(PDO, ?int $spaceId): array`
- `find_my_activity(PDO, int $memberId, int $pageNo, array $filters): array` · `find_record_activity(PDO, array $keys, int $limit): array` · `activity_sentence(array $r): string` · `activity_record_link(array $r): ?array`

## Handlers (every one: `sp_handler_begin()`; the gate; `sp_guard()`; `log_activity`; `sp_done()`)
- `admin/settings.php` (`settings_save`): `require_right('settings.manage')`; `settings.save`; **other**. `admin/emoji.php` (`emoji_save`), `admin/emoji-delete.php` (`emoji_delete`): `settings.manage`; `emoji.save|delete`; `HX-Trigger: emojiChanged`.
- Every admin page: `require_right('settings.manage')` (`admin-retention` also opens to `retention.manage`; `admin-trash` to `trash.purge`); `log_screen_view()`.

## Action manifest entries
Screens: `home` (real), `trail`, `settings` and `tokens` (whole), `admin-settings`, `admin-spaces`, `admin-published`, `admin-retention`, `admin-trash`. Actions (3): `settings_save`, `emoji_save`, `emoji_delete`. Agent approvals: `settings_save` (`other`). `PARTIAL_UPDATE_TARGETS` gains `/admin/settings.php => ['sp_settings', 'id', 'mcp_settings', 'id']`? — the settings row has no id param in the form: the partial fill reads the one row (`id = 1`); the entry is `['sp_settings', '_settings', 'mcp_settings', 'id']` with the handler treating a missing `_settings` as 1.

## Activity log events
`settings.save` (the fields changed), `emoji.save|delete` (`shortcode`), `space.admin_view` (`space_id`), `screen.view` (`home`, `trail` with the record keys, the admin pages). Nothing else: the admin pages' actions are the slices'.

## Notifications this slice queues
None.

## Status vocabulary
Home region cards: `home-unread`, `home-waiting`, `home-recent`, `home-favorites`, `home-verification`, `home-note`, `home-joins`, `home-unanswered`, `home-admin`; an empty region in muted text naming what will appear. Admin: a private space `feather-lock` `dark`; a published page's `noindex` chip `secondary`; a trash row's purge date `warning` within 3 days. Ids: `settings-form`, `emoji-list`, `emoji-row-{shortcode}`, `admin-space-row-{id}`, `published-row-{id}`, `retention-row-{id}`, `trash-row-{uuid}`, `trail-results`.

## Out of scope for this slice
The slices' own actions linked from the admin pages (1, 2, 8); the agents and dispatches pages (slice 7); hiring and grants (the kernel's); approving connections (the kernel's).

## Proof (`tests/phase3/slice9/run.sh`: the scratch database `sp_dev9`; the worlds of slices 1–8 composed; headless Chromium at 375 × 740 and 1280 × 800; the registry `--check` reads every screen and action of the manifest as built — 58 / 118)
- [ ] **Home**: Priya's unread with the first lines (DMs first), her mentions since her last visit, recently edited in her spaces only, her favorites, the wiki page she owns that expired, the Librarian's note (she is in the admin channel) — no owner or admin region; Marco's pending joins and unanswered questions on Product; the admin's three cards with the counts; a guest's home: Shared and DMs only; JSON answers the same; a second visit's "waiting" starts from the first visit.
- [ ] **Settings**: every field saved and read back; a value outside a CHECK → 422 naming the field (snapshot minutes 0, trash days 400, a digest hour 24); MB → bytes; embed hosts one per line, lower-cased; `settings.save` logged with the changed fields; the agent's `settings_save` pauses on the hook path (Phase 4) — the category in the registry; emoji added, replaced, removed, listed in the picker.
- [ ] **Admin spaces**: every space including the private one marked; opening it logs `space.admin_view` once per session; a Member → 403.
- [ ] **Published, retention, trash**: the published list with views and last opened, Rotate and Unpublish work from it; the retention overview with the channels by space and the two retentions; everyone's trash with purge dates, Restore, Purge, Purge all (confirm) — the purged pages and files gone.
- [ ] **The trail**: my rows; a page's history by `page=` (uuid), a channel's by `channel=`; the filters; every manifest event has a sentence (a fixture row per event; none says "unknown").
- [ ] **375 × 740 and 1280 × 800**: Home's regions stack in the phone order; the admin tables as cards; every control ≥ 44 px, `scrollWidth` = viewport, no console errors; the registry reads 58 screens and 118 actions built.

## Built and proven (2026-10-06)
Built as the Files list says, with the differences recorded below. **Home**: `html/index.php`, `app/features/home/{queries,present}.php` (`home_summary()`, `home_unread()`, `home_recent_pages()`, `find_favorites()`, `home_verification_due()`, `last_librarian_note()`, `pending_join_requests()`,
`home_unanswered()`, `admin_summary()`, `present_home()`), `app/views/home/dashboard.php` and the nine `partials/*.php` (`unread waiting recent favorites verification note joins unanswered admin`). **Admin**: `html/admin/{settings,emoji,emoji-delete,spaces,published,retention,trash}.php`,
`app/features/admin/{queries,present,write}.php` (`find_settings()`, `save_settings()`, `settings_from_request()`, `find_emoji()`, `save_emoji()`, `delete_emoji()`, `admin_spaces()`, `published_pages()`, `retention_overview()`, `admin_trash()`), `app/views/admin/{settings,spaces,published,retention,trash,partials/space-row,partials/trash-row}.php`
(no `emoji-list` partial: the list is part of `settings.php`, the page it lives on). **The trail**: `html/trail.php` was already Phase 2's page under its slice-6 name; `app/features/activity/present.php` is new and now holds every sentence (`ACTIVITY_WORDS`, `activity_event_words()`, `activity_sentence()`, `activity_record_link()`, `present_trail_row()`); `queries.php` keeps the reads.
`settings` and `tokens` were already whole (slice 6) and are proven here. **The five placeholders are gone**: `render_nav_stub()` and `NAV_SLICES` were deleted from `app/features/shell/nav.php`, and `grep -rl "render_nav_stub(" html/ app/` lists nothing; the registry reads **58 of 58 screens and 118 of 118 actions built**.
No schema change: no `db/024` was needed.
**Decisions taken in the build:**
- **Home's regions are the earlier slices' own functions or the SQL ones they call.** The Unread row is `sp_unread()` joined to `mcp_channels` (a DM's name is the other person's), the first unread line comes from `mcp_messages`; Waiting is slice 6's `activity_feed()` (newest five); Recently edited is `sp_pages_changed_since()` over seven days narrowed to the member's spaces, the pages shared with them and their own private ones, never a database's row; Favorites joins `mcp_page_favorites` to `mcp_pages`; Needs my verification is `sp_wiki_status()` across every wiki space
  (the per-space `wiki_report()` cannot span them) narrowed to `wiki_owner_member_id = me` and the states `expired` or `none`; the note is the last top-level message by an agent whose name or job title says Librarian in the channel named by `admin_channel_name`, **shown only to a member who has joined that channel** (`mcp_channels.i_follow`; a public channel is visible to everyone in its space, so visibility alone would have shown it to all);
  Pending joins are the `pending` rows of `mcp_space_join_requests` for the spaces the member owns, never their own; Unanswered is `sp_unanswered_questions()` narrowed to the spaces they own. A region the caller does not have is `null` in JSON and absent on the screen; the note and the owner's and admin's regions are absent, the person's own empty regions say what will appear.
- **"Since the last visit" is the session's.** `$_SESSION['home_seen_at']` (microsecond precision, so a message in the same second as a visit is not lost) is set at the end of each visit to the home; the first visit of a session looks back seven days. An action-token caller has no session and so always gets the seven days. `home_summary()` takes the timestamp as an optional third argument (additive to the fixed signature).
- **A guest's home** is the same function: their sidebar has no spaces, so Recently edited is only what is shared with them, the "spaces" card becomes "Shared with you" (`sp_sidebar().shared`), and Unread lists only the DMs and channels they belong to.
- **The settings row is read from `sp_settings`**, not `mcp_settings`: the view leaves out `allowed_embed_hosts` and `public_base_url`, which the form needs; every page that does so has passed `settings.manage`. The form's attachment limit is `max_attachment_mb`; an agent may send `max_attachment_bytes` (the manifest's own parameter name) instead, bounded by the table's CHECK (1 MB to 1 GB). Every number is bounded in PHP by the CHECK's own bounds so the sentence names the field; a CHECK that is violated anyway is a 422 naming the constraint. A field left out stays as it was, so **`PARTIAL_UPDATE_TARGETS` was not given an entry** (the spec's `'/admin/settings.php' => [...]`): the handler already keeps what it is not sent, and the generic prefill would have copied the table's bytes and array literal over the form's MB and lines.
  Nothing is written, logged or answered `Saved` when nothing differs (`changed: []`); `settings.save` carries `before` and `after` of the changed columns (the embed hosts as a list). `emoji_save` upserts (a seeded shortcode may be replaced; the colons are dropped; at most ten keywords of 30 characters), `emoji_delete` answers 404 for one that is not there.
- **`space.admin_view` once per space per session**: `space-view` remembers the spaces it has logged in `$_SESSION['admin_viewed']`; a new session logs again; an action-token call has no session and logs each time.
- **The trail** keeps its own views (`app/views/trail/{page,partials/rows}.php`) rather than the spec's `app/views/activity/{trail,partials/rows}.php` — `activity/` already holds slice 6's feed, which `/activity` serves. `present_activity_row()` of the trail was renamed `present_trail_row()` (slice 6's feed has a function of that name; Home loads both). With no record key the trail is the caller's own rows; with `space`, `channel`, `message` or `page` it is that record's history as `mcp_activity_log` lets the caller see it (a row about a page they may not see is not there; the admin sees everything). A page's history is the rows whose `entity_uuid` is the page — a block's or a comment's rows are found through the block or comment itself.
  Every event of the manifest and of the code has its own sentence (`ACTIVITY_WORDS`, 141 events); a cron row reads "The worker …", an agent's "(agent)", and the source column chips the agent (with its run) and the worker.
- **Admin pages**: `admin-retention` opens to `retention.manage` or `settings.manage`, `admin-trash` to `trash.purge` or `settings.manage` (the admin holds all); every admin screen answers 405 to a POST. Rotate, Unpublish, Archive, Restore, Delete, Restore (a page), Purge, Purge all and Set retention are the slices' own actions, posted from the cards with `return_to` back to the admin page. Purge all shows its count before the confirm (`admin-trash-count`, the button's `hx-confirm`).
- **A defect of slice 2 found and fixed here: a manual purge left the files behind.** `purge_page()` (page_delete, trash_purge) called `sp_page_purge()`, which cascades the pages and blocks but leaves the polymorphic `attachments` rows and their files; only the worker's trash pass removed them. `purge_page()` now collects the subtree's attachments first (`subtree_attachment_ids()`) and drops those whose record is gone after (`drop_gone_attachments()`), both in `app/features/channels/retention.php` beside `drop_attachments()`. The worker's own pass keeps its copy of the same query.
- Phase 2's `gates.php` and `browser.mjs` expected `/admin/trash` to be a placeholder (501 to a POST, `#admin-trash-coming`); they now expect the real screen (405 to a POST, `#admin-trash-content`) and no `render_nav_stub(` anywhere.
**Proven by `tests/phase3/slice9/run.sh` — HOME 40, SETTINGS 39, ADMIN SPACES 24, ADMIN (published, retention, trash) 33, TRAIL 37, JSON 35, BROWSER 70 (278 checks green); Phase 2, slice 1 and slice 2 re-run green.** Every box of the checklist has at least one `ok()` line. The world is slices 1–8's composed (`home_world()`: the agents, the wiki's pages of every state, `#smoke-launch` with its 30-hour-old question, plus the admin channel `spaces-admin` with the Librarian's note, a private space, a published page, a trashed page);
the browser proof drives Priya's, Marco's and the admin's homes at 375 and 1280, the settings form (a refusal in words, a save, an emoji added and removed), every admin page as cards, a retention set with its confirm, Restore, Purge all with its confirm dismissed and then accepted, and the trail's filter. The settings a proof changes are put back.

## Open questions
