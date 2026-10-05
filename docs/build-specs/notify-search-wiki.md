# Build spec: notifications, search and the wiki's reports (slice 6)

What exists at the end: the bell is real (every notice links to its channel, message or page; the person chooses what reaches them by
email, by text, as a digest); the Activity feed and Saved pages are real; one search across pages, rows, messages and comments with Slack's
modifiers; a wiki space's reports (expired, unverified, stale, orphans, broken links, duplicates) with a nudge to each owner. **This slice
queues; slice 8's worker sends.** Nothing here calls a model, MaluMail or the kernel.
Schema: `notifications`, `notification_prefs`, `notification_outbox`, `sp_notify()`, the triggers `messages_notify`, `comments_notify`,
`page_permissions_notify` (db/012); `search_index`, `sp_search()`, `sp_index_page()`, `sp_index_message()`, `sp_index_comment()`
(db/013); `sp_activity_feed()`, `sp_wiki_status()`, `sp_stale_pages()`, `sp_orphan_pages()`, `sp_broken_links()`, `sp_duplicate_titles()`,
`sp_unanswered_questions()`, `sp_pages_changed_since()` (db/015); `sp_settings.stale_page_days`, `unanswered_hours`, `away_minutes`,
`wiki_default_verify_months` (db/005); the views `mcp_notifications`, `mcp_notification_prefs`, `mcp_saved_messages`, `mcp_reminders`,
`mcp_messages`, `mcp_pages`, `mcp_comments` (db/017). Never modify them. **The database is the referee**: who is notified of what is
`sp_notify()` and the three triggers (a channel set to `none` is quiet, a muted channel is quiet, an away member gets the email, a member
who chose texts gets the text row, a dedupe key makes a second queue a no-op); what a search answers is `sp_search()` over the visible
sets; a report is its function — PHP parses, presents and links, and decides nothing twice.

## Screens (375 px is the design — the bell and the feed are read on a phone; search usable at 375 px, designed at 1280 px)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `notifications` | `/notifications` | Phase 2's shape made real: the notices newest first (`notification-row-{id}`: kind icon, title, body's first line, who, when, a chip **unread**), each a link to its record (`notification_record_url()`: a message → `/channels/{id}?message=` or `/dm/{id}?message=`, a page → `/pages/{uuid}`, a reminder → `/reminders/`, a join request → `/spaces/{id}/requests`, a proposal → `/proposals/`); **Mark all read**; the bell in the header shows the unread count (`#bell-count`, polled with the channel poll) |
| `activity` | `/activity?since=&kind=` | **Activity** (Slack's): mentions of me, replies to my messages and threads, reactions to my messages, comments on my pages — `sp_activity_feed()`; a row (`activity-row-{n}`): who (an agent chipped), what, the excerpt, when, → the thread or the page; filters by kind and since (today, 7 days, 30 days) |
| `saved` | `/saved` | my saved messages (`mcp_saved_messages` ⨝ `mcp_messages`, Markdown rendered, with where each is and Unsave) and my reminders due and coming (`mcp_reminders`, with Done); the two as tabs on a phone |
| `settings` | `/settings/` | Phase 2's prefs form made whole: email on/off, text on/off (with the note that the kernel keeps the opt-out and the phone), the kinds as checkboxes (one per `notifications.kind`), the text kinds, **digest** (one morning email at the workspace's hour instead of one per event), away minutes (empty = the workspace's), my status line and emoji, my time zone (the mirror's; a note that HR owns it) |
| `search` | `/search?q=&in=&from=&has=&before=&after=&is=&space=` | the one search: a query box that understands `in:#ops from:@priya has:link before:2026-09-01 after:2026-08-01 is:page` (parsed in PHP into `sp_search()`'s filters; the modifiers shown as removable chips; a person may also pick them from selects); results grouped **Pages · Rows · Messages · Comments** with the match marked (`<mark>` from the excerpt, escaped around it), the breadcrumb or the channel, who and when; 25 a page, Next; the Enter key searches, typing does not (no live search in v1) |
| `wiki-view` | `/spaces/{id}/wiki` | slice 2's status page kept; a link to the reports |
| `wiki-report` | `/spaces/{id}/wiki/report` | the six lists as sections with counts in their headings: **Expired** and **Never verified** (`sp_wiki_status()` by state, by owner), **Stale** (`sp_stale_pages()` for the space), **Orphans** (`sp_orphan_pages()`), **Broken links** (`sp_broken_links()`: the page, the block, where it points, why), **Duplicates** (`sp_duplicate_titles()`), and **Unanswered questions** in the space's public channels (`sp_unanswered_questions()`); each page a link; **Nudge** beside an owner (`wiki_nudge_send`); the settings' numbers (stale days, unanswered hours, the verify window) shown in the header |

## The bell and the prefs
- **What queues what** (by trigger, already): a mention (member, department, `@channel`, `@here`, `@everyone`) → `mention`; a reply in a thread I
  started or replied in → `reply`; a DM or group DM → `dm`; a comment on my page or mentioning me → `comment`; a page shared with me or my department
  → `share`; `verification` (the worker, slice 8), `reminder` (the worker), `join_request`/`join_decided` (slice 1's handlers call `sp_notify()`),
  `proposal`/`agent_replied` (slice 7), `digest` (the worker). This slice adds no kind.
- **The outbox rows** are queued by `sp_notify()` from the person's prefs: an email when `email_enabled`, the kind in `kinds`, not `digest`, and the
  person is **away** (`members.last_seen_at` older than their `away_minutes` or the workspace's); a text when `text_enabled` and the kind in
  `text_kinds` and away — the body `"Spaces: <title>: <first 120 chars>"` cut to 480. The shell sets `last_seen_at` on every signed-in request
  (`session_touch()` — once a minute at most). **Slice 8 sends.**
- **`prefs_save`**: `email_enabled`, `text_enabled`, `digest`, `kinds[]` (an unknown kind → 422 naming it), `text_kinds[]` (⊆ kinds), `away_minutes`
  (1–1440 or empty). The form posts what it shows; without JavaScript it still works. A guest has prefs like anyone.
- **`status_set`**: `members.status_text` (≤ 100), `status_emoji`, `status_until` (a time; the shell shows the status beside the name in the people
  picker and on DMs; past `until` it is shown no more — nothing clears the row). Log `status.set` (the fields, not the text? — the text is the
  person's own words and short: it goes in `after.text`).
- **`notification_read`**: one notice (`notification`) or all (empty); `read_at = now()`; the bell count follows; log `notification.read` (count).

## Search
- `parse_search_query(string $q): array` → `['q' => the words, 'in' => '#ops' | id, 'from' => '@priya' | id, 'has' => 'link' | 'file', 'before',
  'after' (dates; a bad date dropped with a notice), 'is' => kind, 'space']` — a modifier appears once; `from:@me` is the caller; `in:` resolves
  `#name` through `mcp_channels` (the function also accepts the name). The chips re-render from the parsed object; removing one re-runs.
- The results are `sp_search()`'s rows: `entity_kind`, ids, `title`, `excerpt` (with `<mark>`), `occurred_at`, `space_id`, `channel_id`,
  `author_member_id`; the presenter adds the breadcrumb (`sp_page_ancestors()`) for a page or a row, the `#channel` for a message, the page for a
  comment, the author's name; each a link (a message → `/channels/{id}?message=`, a comment → `/pages/{uuid}#comment-{id}`).
- Empty `q` with a modifier lists by recency (the function allows it); empty everything → the page with the syntax help and recent searches? —
  no history kept: the help only.
- The index is maintained by triggers; `sp_search_catch_up()` is the worker's (slice 8). A result the caller may not see never appears (the function).

## The wiki's reports and the nudge
- The lists are the functions' rows for the space; a page the caller may not see is not in them (the functions).
- **`wiki_nudge_send`** (`space.manage` on the space): `sp_notify(owner, 'verification', 'Please look at "<title>"', 'It <expired on …|was never verified|has not been edited in N days>', NULL, NULL, page, NULL, NULL, 'wiki_nudge:<page>:<ISO week>')` — the dedupe key makes a second nudge the same week a no-op (the handler says so); `member` may name another person than the owner; log `page.nudge` (`entity_uuid`, `member_id`, `reason`). An agent's nudge is the Librarian's own (no pause).
- The reports page never changes a page: verify, owner and trash are slice 2's actions, linked from each row.

## Files (exactly these)
- `html/notifications.php` (real) · `html/settings/notifications/read.php` · `html/settings/prefs.php` (whole) · `html/settings/status.php` · `html/activity.php` (the feed — Phase 2's trail moves to `html/trail.php`, slice 9) · `html/saved.php` · `html/search.php` · `html/spaces/wiki.php` (`wiki-view`, slice 2's) · `html/spaces/wiki/report.php` · `html/spaces/wiki/nudge.php`
- `app/features/notify/{queries,present}.php` (`notification_record_url()`, kinds and their words) · `app/features/search/{parse,queries,present}.php` · `app/features/wiki/{queries,present}.php` · `app/features/settings/{queries,present}.php` (prefs, status)
- `app/views/notifications/{page,partials/row,partials/bell}.php` · `app/views/activity/{feed,partials/row}.php` · `app/views/saved/{page,partials/message,partials/reminder}.php` · `app/views/search/{page,partials/results,partials/chips}.php` · `app/views/wiki/{report,partials/list}.php` · `app/views/settings/{index,partials/prefs,partials/status}.php`

## Query functions (signatures fixed)
- `find_my_notifications(PDO, int $memberId, bool $unreadOnly, int $page): array` (`mcp_notifications`; 50 a page) · `unread_notification_count(PDO, int $memberId): int` · `mark_notifications_read(PDO, int $memberId, ?int $id): int` · `notification_record_url(array $n): ?string`
- `find_my_prefs(PDO, int $memberId): array` (a default row when none) · `save_prefs(PDO, int $memberId, array $fields): void` · `set_status(PDO, int $memberId, ?string $text, ?string $emoji, ?string $until): void`
- `activity_feed(PDO, string $since, ?string $kind, int $limit): array` (`sp_activity_feed()`, the names joined) · `find_saved(PDO, int $memberId): array` · `find_my_reminders(PDO, int $memberId, bool $includeDone): array`
- `parse_search_query(string $q): array` · `search(PDO, array $f, int $page): array` (`sp_search()`; `['rows' => by kind, 'total' => …]`) · `present_search_row(PDO, array $r): array`
- `wiki_report(PDO, int $spaceId): array` (`['expired', 'unverified', 'stale', 'orphans', 'broken', 'duplicates', 'unanswered']`, each the function's rows) · `nudge_owner(PDO, string $pageUuid, ?int $memberId, int $by): array` (`['queued' => bool]`)

## Handlers (every one: `sp_handler_begin()`; the gate; `sp_guard()`; `log_activity`; `sp_done()`)
- `settings/prefs.php` (`prefs_save`): own; `prefs.save` (`after`: the fields changed — `sp_diff()`); `HX-Trigger: prefsChanged`.
- `settings/status.php` (`status_set`): own; `status.set`. `settings/notifications/read.php` (`notification_read`): own; `notification.read` (`count`); `HX-Trigger: notificationChanged`.
- `spaces/wiki/nudge.php` (`wiki_nudge_send`): `require_page_level(page, 'view')` and `sp_is_space_owner(space)`; `page.nudge`; `HX-Trigger: wikiChanged`.
- Every page: `require_login()`, `log_screen_view()`; `search.php` logs `screen.view` with `after.q_length` and the modifiers used — never the words of a query? — the query is not personal data and helps the expert: `after.q` (≤ 200) is kept.

## Action manifest entries
Screens: `notifications`, `activity`, `saved`, `settings`, `search`, `wiki-view`, `wiki-report`. Actions (4): `prefs_save`, `status_set`, `notification_read`, `wiki_nudge_send`. Agent approvals: none. `PARTIAL_UPDATE_TARGETS` gains nothing (prefs are the person's own form).

## Activity log events
`prefs.save`, `status.set`, `notification.read`, `page.nudge` (`entity_uuid`, `space_id`, `member_id`), `screen.view` (`search` with `after.q`, `after.filters`; `wiki-report` with `space_id`). No row carries a notice's body or a message's words.

## Notifications this slice queues (the sender is slice 8)
| Step | Who is told | Kind |
|---|---|---|
| a nudge from the wiki report | the page's owner (or the member named) | `verification` (dedupe `wiki_nudge:<page>:<week>`) |
Everything else is queued by the triggers already (mention, reply, dm, comment, share).

## Status vocabulary
Notification kinds and icons: mention `feather-at-sign`, reply `feather-corner-down-right`, reaction `feather-smile`, comment `feather-message-circle`, share `feather-share-2`, page_changed `feather-edit-3`, verification `feather-check-circle` (`warning`), reminder `feather-bell`, dm `feather-mail`, channel `feather-hash`, join_request `feather-user-plus`, join_decided `feather-user-check`, proposal `feather-book`, agent_replied `feather-cpu`, digest `feather-sunrise`. Unread chip `primary`. Wiki states: verified `success`, expired `warning`, none `secondary`; stale `dark`; a broken link `danger`. Ids: `bell-count`, `notification-list`, `notification-row-{id}`, `activity-list`, `search-form`, `search-chips`, `search-results`, `search-group-{kind}`, `wiki-report`, `wiki-list-{name}`, `nudge-{page}`.

## Out of scope for this slice
Sending (email, K6 texts, the digest) — slice 8; the Librarian's Monday note and proposals — slice 7; `trail` (the own trail, renamed from Phase 2's `/activity`) — slice 9; live search as you type; search history.

## Proof (`tests/phase3/slice6/run.sh`: the scratch database `sp_dev6`, members through dev hand-offs, curl with signed action and run tokens, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`; `bin/sync_approvals.php --check`)
The world (`tests/phase3/slice6/lib.php` `wiki_world()`): Product (closed, a wiki, Marco owner, Priya member), General; pages with every state (verified, expired by date, never, stale by `last_edited_at`, an orphan, a broken link, two "How we deploy"); `#launch` with a question 30 h old and an answered one; Priya mentioned, replied to and reacted to; a comment on her page; a page shared with her.
- [ ] **The bell**: Priya's notices in order with their links (a mention → the message's channel URL with `?message=`, a comment → the page, a share → the page); the unread count; one read, all read; another member's notice → absent (the view); JSON lists the same.
- [ ] **Prefs**: email off → a new mention queues no email; text on for `mention` → a text row ≤ 480 chars starting "Spaces: "; digest on → no per-event email; away minutes 1 with `last_seen_at` now → no email, 10 minutes later → an email (fixture time); an unknown kind → 422 naming it; `text_kinds` not in `kinds` → 422; a guest saves prefs.
- [ ] **Status**: set, shown in the picker, cleared by an empty text; `until` past → not shown.
- [ ] **Activity**: the four kinds for Priya, newest first; `since` and `kind` filters; Marco's feed empty of her events.
- [ ] **Saved**: her saved message rendered with its channel, Unsave; her reminders with Done.
- [ ] **Search**: `rate limit` finds the page and the message with `<mark>`; `is:message`, `in:#launch`, `from:@marco`, `has:link`, `before:`/`after:` each narrow; a bad date dropped with a notice; chips removable; paging at 25; Dana finds only what she may see; a guest only what is shared; an empty query with `is:page` lists recent pages.
- [ ] **The report**: the seven lists with the right pages; a page Priya may not see absent from hers; Nudge queues one `verification` notice with the page in `record_uuid` and logs `page.nudge`; a second nudge this week queues nothing and says so; a non-owner → 403; the settings' numbers shown.
- [ ] **375 × 740 and 1280 × 800**: the bell list, the feed and the search results stack on the phone (the groups as sections, the chips wrap); the report's lists as cards; every control ≥ 44 px, `scrollWidth` = viewport, no console errors; JavaScript off searches and nudges.

## Open questions
