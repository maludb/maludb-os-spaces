# Build spec: the worker, imports, exports and retention (slice 8)

What exists at the end: one timer worker does everything automated — sends the outbox (email through MaluMail, texts through the kernel's
K6, the morning digest), releases scheduled messages, fires reminders, snapshots pages, prunes versions, applies retention, purges the
trash, expires verifications and exports, catches the search index up, and runs the agents' dispatch loop (slice 7's step); a person imports
Markdown, a Notion export or a CSV, and exports a page, a database, a space, a channel or everything. Nothing here posts, publishes or shares
on its own.
Schema: `notification_outbox`, `notifications`, `notification_prefs` (db/012); `imports`, `exports`, `worker_passes`, `sp_version_save()`,
`sp_pass_scheduled()`, `sp_pass_reminders()`, `sp_pass_snapshots()`, `sp_pass_version_prune()`, `sp_pass_retention()`, `sp_pass_trash_purge()`,
`sp_pass_wiki_expire()`, `sp_pass_exports_expire()`, `sp_dispatches_due()`, `sp_dispatch_record()` (db/016); `sp_search_catch_up()`, `app.sp_bulk`
(db/013); `sp_page_create()`, `sp_block_insert()`, `sp_page_duplicate()` (db/008); `sp_database_create()`, `sp_row_create()`,
`sp_database_property_save()` (db/009); `sp_page_markdown()`, `sp_database_rows()` (db/014); `sp_channel_history()` (db/015); `channels.retention_days`
(db/010); `sp_settings.digest_hour`, `version_snapshot_minutes`, `version_retention_days`, `trash_retention_days` (db/005); the views `mcp_imports`,
`mcp_exports`, `mcp_channels`, `mcp_pages`, `mcp_databases` (db/017). Never modify them. **The database is the referee**: what a pass sends, releases,
snapshots, prunes, deletes or expires is the `sp_pass_*` function's answer — the worker calls each, counts, and logs; a retention day, a snapshot
interval and a purge date are the settings' and the channel's, never a constant in PHP.

## The worker (`bin/worker.php`, every minute from `spaces-worker.timer`; advisory-locked `hashtext('sp_worker')`; each step its own try; one JSON line and one `worker_passes` row + `worker.pass` log row)
`php bin/worker.php [--only=scheduled,reminders,outbox,digest,snapshots,prune,retention,trash,wiki,exports,search,dispatches,housekeeping] [--limit=200]`.
`SP_WORKER_NOW` (ISO 8601) fakes the clock outside production (the proofs). The pass runs with no acting member (`app.member_id` unset — the
`sp_pass_*` functions are `SECURITY DEFINER`; the dispatch step and the digest read through the views as the member concerned, one
`set_config('app.member_id', …, true)` per member inside a transaction).
| Step | Every | What |
|---|---|---|
| `scheduled` | minute | `sp_pass_scheduled()`: messages whose `scheduled_for` passed are sent (the triggers count, notify and dispatch) |
| `reminders` | minute | `sp_pass_reminders()`: reminders fallen due become a `reminder` notice (and the outbox rows by prefs) |
| `outbox` | minute | `outbox_send_batch()`: queued rows by `send_after`; **email** through MaluMail (`malumail_send(['to' => to_email ?? the mirror's email, 'subject', 'text' => body, 'html' => body_html ?? rendered, 'from' => MAIL_FROM, 'from_name' => MAIL_FROM_NAME])`, the provider's id in `provider_ref`); **text** through K6 (`kernel_call('POST', '/api/v1/notify/sms.php', ['member_id', 'text' => body (≤ 480), 'reference' => kind:record])`): 202 → `sent` with the kernel's notification id; every refusal (`no_sender`, `not_held`, `no_verified_phone`, `opted_out`, `rate_limited`, `invalid`) → `skipped` with the code, the email row stands; a transport failure → `attempts + 1`, `send_after + 2^attempts min`, five then `failed` |
| `digest` | at the workspace's `digest_hour` (the member's time zone), once a day | for each member with `digest = true` and unread notices since the last digest: one email listing them (title, who, when, the link), queued as an outbox row of kind `digest` with dedupe `digest:<member>:<date>`; a `digest` notice row marks it |
| `snapshots` | minute | `sp_pass_snapshots(200)`: a quiet, changed page gets a version |
| `prune` | daily 03:00 | `sp_pass_version_prune()` |
| `retention` | hourly | `sp_pass_retention()`: a channel with `retention_days` loses its older messages (hard delete; attachments' files of those messages removed from `storage/`) |
| `trash` | daily 03:10 | `sp_pass_trash_purge()`: pages past the trash retention purged for good; their attachments' files removed |
| `wiki` | daily 06:00 | `sp_pass_wiki_expire()`: verified pages past their date become expired, the owner told once |
| `exports` | daily 03:20 | `sp_pass_exports_expire()` → the paths it returns are unlinked |
| `search` | every 5 minutes | `sp_search_catch_up(500)` |
| `dispatches` | minute | slice 7's `dispatches_pass()` + `poll_running_dispatches()` |
| `housekeeping` | daily | `sso_nonces` expired, `member_sessions` ended over 90 days ago, outbox rows sent over 180 days ago (bodies blanked, rows kept), `worker_passes` over 90 days, `page_recents` beyond 50 per member |
A step's error is caught, counted in `errors[]` with its sentence (never a body) and the pass goes on; the exit code is 1 when any step erred.
Presence is not the worker's: the shell writes `members.last_seen_at` on each signed-in request (Phase 2).

## Imports (`/import`; `import_start`)
- **Kinds** (from the file when `kind` is empty): `markdown` (one `.md` → one page, the converter `markdown_to_blocks()` — slice 3's `app/richtext/`);
  `markdown_zip` (a folder tree → a page tree: a folder with an `.md` of the same name is that page's subtree; files sorted by name; a `.csv` beside an
  `.md` becomes a **database** under it with the header as properties and types inferred — number, checkbox, date, select (≤ 20 distinct values),
  multi_select (comma-separated), url, email, else rich_text; a column named like a page title in the zip becomes a relation when every value matches
  a title); `notion_zip` (the same with Notion's naming `Title <32 hex>.md` → the page keeps that id when free, the `<32 hex>` dropped from the title;
  Notion's CSV `Name` column is the title; `heading_4`, `tab`, `meeting_notes`, `transcription` → `unsupported` blocks keeping `original_type` — D6);
  `csv` (into an existing `database`: the header → properties added when missing, rows → `sp_row_create()`; the title column is the schema's or the first).
- **Where**: `space` (the root) or `parent` (a page) — `require_page_level(parent, 'edit')` or member of the space; a CSV needs `database` with `edit`.
- **How**: the upload lands in `storage/imports/<id>/`; an `imports` row `queued` → `running`; the import runs **in the request** for a single file
  under 2 MB, else the worker's next pass runs it (`import_pass()`; the screen polls the row); inside one transaction per page with `app.sp_bulk = 1`,
  then `sp_search_catch_up()` for what it made; `pages_made`, `rows_made`, `blocks_made`, `unsupported` and a `log` (one line per file; the first 200
  problems); a failure → `failed` with the sentence, what was made stays (a person trashes it). Attachments referenced by relative path in the zip
  are stored (`store_attachment()`, slice 3's) and linked as `image`/`file` blocks; absolute URLs stay URLs.
- The screen: the form (file, kind, where), a **preview** for a zip (the tree it will make, counts) before the import, the past imports with their logs.

## Exports (`/exports/`; `export_page`, `export_database`, `export_space`, `export_channel`, `export_all`, `export_delete`, the download)
| Export | Format | Content |
|---|---|---|
| a page (`export_page`) | `md` (one file, or a zip when `include_subpages`: the page and a folder of subpages, images as files), `html` (the design system's print styles, inline) | `sp_page_markdown()` per page; attachments copied |
| a database (`export_database`) | `csv` (the view's visible properties and rows — `sp_database_rows()`, every row; relations as titles, rollups as display, people as names), `json` (the schema and the resolved rows) | |
| a space (`export_space`) | `zip` of md or html: every page the caller may see in the space as a folder tree, every database as CSV + md rows, `index.md` with the sidebar | **external_send** for an agent |
| a channel (`export_channel`) | `json` (messages with threads, authors as names, reactions, attachment names), `md` (one file, day headings), `csv` | a period `from`/`to`; **external_send** |
| everything (`export_all`) | `zip` of every space export + every DM of the caller? — no: every space and every channel the caller may read, never a DM (`export.all`) | **external_send** |
- An export is an `exports` row `queued` → `running` → `done` with `storage_path` under `storage/exports/<id>/` and `byte_size`, or `failed`; a page or a
  database export runs in the request; a space, a channel or everything runs in the worker's `exports` step (`export_pass()`; the list polls); 7 days
  (`expires_at`), then the file goes (`sp_pass_exports_expire()`); **download** through `html/exports/download.php?id=` (own, or `export.all` for the admin;
  `X-Content-Type-Options: nosniff`, `Content-Disposition: attachment`), logged `export.download` (`export_id`, `kind`, `byte_size`); `export_delete`
  unlinks and marks the row. The exports page lists mine (the admin: everyone's) with their state and a Download that is gone after expiry.
- Nothing a person may not see is in an export: every reader is `sp_page_markdown()`, `sp_database_rows()`, `sp_channel_history()` as the caller.

## Retention (`retention_set`)
`channels.retention_days` (1–3650, empty = forever — D9: off by default); `retention.manage` anywhere, or `channel.manage` as the channel's space owner; a
DM's retention is its members' to set? — no: a DM has no retention in v1 (the manifest's Who excludes it: `channel` must be in a space); the pass deletes
hourly; the channel page says "Messages older than N days are deleted" in its header. The admin's overview is slice 9's `admin-retention`.

## Files (exactly these)
- `bin/worker.php` · `app/features/worker/{steps,outbox,digest,housekeeping}.php` (`worker_pass()`, `WORKER_STEPS`, `outbox_send_batch()`, `digest_pass()`)
- `app/features/imports/{queries,present,run,readers}.php` (`run_import()`, the four readers, type inference) · `html/import.php` · `html/import/start.php`
- `app/features/exports/{queries,present,run,writers}.php` (`run_export()`, the writers per kind and format) · `html/exports/index.php` · `page.php` · `database.php` · `space.php` · `channel.php` · `all.php` · `delete.php` · `download.php`
- `html/channels/retention.php` (`retention_set`) · `app/features/channels/retention.php`
- `app/views/import/{page,partials/preview,partials/log}.php` · `app/views/exports/{index,partials/row}.php` · `app/views/mail/{notice,digest}.php` (the email bodies: title, excerpt, the link, the business's name — never another person's details)
- `tests/fake_malumail.php` (Phase 0's), `tests/fake_kernel.php`'s `/api/v1/notify/sms.php` states (202, each refusal, 500)

## Query functions (signatures fixed)
- `worker_pass(PDO, array $only, int $limit, DateTimeImmutable $now): array` · `outbox_send_batch(PDO, int $limit): array` (`['sent', 'skipped', 'failed', 'retried']`) · `digest_pass(PDO, DateTimeImmutable $now): int` · `housekeeping_pass(PDO, DateTimeImmutable $now): array` · `retention_pass(PDO): array` (`sp_pass_retention()` + the files) · `trash_pass(PDO): array` · `exports_pass(PDO): array` · `import_pass(PDO, int $limit): array` · `export_pass(PDO, int $limit): array`
- `start_import(PDO, array $fields, array $file, int $by): int` · `run_import(PDO, int $importId): array` · `preview_zip(string $path): array` · `infer_property_type(array $values): string`
- `start_export(PDO, string $kind, string $format, array $target, int $by): int` · `run_export(PDO, int $exportId): array` · `find_my_exports(PDO, int $memberId, bool $all): array` · `export_file(PDO, int $exportId, int $memberId): ?array`
- `set_retention(PDO, int $channelId, ?int $days, int $by): void` · `remove_attachment_files(array $paths): int`

## Handlers (every one: `sp_handler_begin()`; the gate; `sp_guard()`; `log_activity`; `sp_done()`)
- `import/start.php` (`import_start`): multipart; the destination's gate; `page.import` (`import_id`, `kind`, `pages_made`, `rows_made`, `unsupported`); location `/import?id=`.
- `exports/page.php` (`export_page`): `require_page_level(page, 'view')` + `export.own`; `page.export`. `database.php`: the same; `page.export`. `space.php` (`export_space`): owner + `export.space`; `space.export`; **external_send**. `channel.php` (`export_channel`): owner + `export.space`; `channel.export`; **external_send**. `all.php` (`export_all`): `export.all`; `export.all`; **external_send**. `delete.php` (`export_delete`): own; `export.delete`. `download.php` (GET): own or `export.all`; `export.download`.
- `channels/retention.php` (`retention_set`): `retention.manage` or `channel.manage` + owner of the channel's space; `channel.retention_set` (`before.days`, `after.days`); **other**; `HX-Trigger: channelChanged`.

## Action manifest entries
Screens: `import`, `export-list`. Actions (8): `import_start`, `export_page`, `export_database`, `export_space`, `export_channel`, `export_all`, `export_delete`, `retention_set`. Agent approvals: `export_space`, `export_channel`, `export_all` (`external_send`); `retention_set` (`other`). `PARTIAL_UPDATE_TARGETS` gains nothing.

## Activity log events
`worker.pass` (cron; the counts), `notification.send|skip|fail` (cron; `channel`, `kind`, `code` — never a body or an address), `message.post` (cron, `after.via = scheduled`), `reminder.done`? — no: a fired reminder logs `reminder.fire` (cron), `page.version_save` (cron, `reason = interval`), `message.delete` (cron, `via = retention`, counts per channel), `page.delete` (cron, `via = trash`, `subtree_count`), `page.verify` (cron, `after.state = expired`), `export.delete` (cron, `via = expiry`), `page.import`, `page.export`, `space.export`, `channel.export`, `export.all`, `export.download`, `channel.retention_set`.

## Notifications this slice sends
Every queued outbox row (email, text), the daily digest; nothing new is queued but `digest` and the import's and export's "done" notices to their requester (kind `page_changed`? — no: kind `digest` is wrong too; an import or export done is told in-app only, kind `reminder`? — **the manifest adds no kind; the list polls** — nothing queued).

## Status vocabulary
Outbox: queued `secondary`, sent `success`, skipped `warning` (the code shown), failed `danger`. Import/export status: queued `secondary`, running `info`, done `success`, failed `danger`; an expired export "expired" `dark` with no Download. Ids: `import-form`, `import-preview`, `import-row-{id}`, `export-list`, `export-row-{id}`, `retention-form`.

## Out of scope for this slice
The dispatch step's content (slice 7); the MCP servers (Phase 4); PDF export, offline, feed subscriptions, autofill (Extended); a DM's retention; Slack or Notion live sync.

## Proof (`tests/phase3/slice8/run.sh`: the scratch database `sp_dev8`; `SP_WORKER_NOW` drives the clock; the fake kernel's K6 states and chat endpoint; the fake MaluMail; fixtures with zips under `tests/phase3/slice8/fixtures/`; headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`; `bin/sync_approvals.php --check`)
- [ ] **The outbox**: an email sent (the fake logs to, subject and the link; the mirror's address), a text through K6 (202 → sent with the kernel's id), each refusal code → skipped with the email still sent, a 500 → retried with backoff then `failed` after five; `notification.send|skip|fail` logged without bodies.
- [ ] **The digest**: a member with digest on gets one email at the hour listing three unread notices, none per event, not twice the same day; a member without gets per-event mail.
- [ ] **Scheduled and reminders**: a message due is sent on the pass (counted, notified, dispatched when it mentions an agent); a reminder due becomes a notice once.
- [ ] **Snapshots and prune**: a quiet changed page gets a version; nothing twice; versions past the retention pruned, the newest kept.
- [ ] **Retention and the trash**: a 7-day channel loses a day-8 message and its attachment's file; the trash purge removes a page aged past the setting with its subtree and files, not a younger one; the channel header says the retention.
- [ ] **Wiki expiry and exports' expiry**: an expired page marked, the owner told once; an export past 7 days loses its file and says expired.
- [ ] **Search catch-up**: a page indexed with `app.sp_bulk` on is caught up on the pass.
- [ ] **Imports**: one `.md` → a page with every block type the converter supports; a Markdown zip → the tree with the right parents, a CSV beside it → a database with inferred types (number, checkbox, date, select, multi-select, url, rich_text) and a relation on a title column; a Notion zip keeps the ids and drops the hex from titles, `heading_4` → `unsupported` with `original_type`; a CSV into an existing database adds the missing properties and the rows; a bad zip → `failed` with the sentence and nothing half-made beyond what the log says; the preview's counts match; a 3 MB zip runs in the worker and the screen polls to done; `page.import` logged.
- [ ] **Exports**: a page as md and as html (the subpages folder, the images); a database as csv (relations as titles) and json; a space zip with `index.md` holding only what Dana may see when Dana exports; a channel as json/md/csv for a period, threads nested; everything as a zip with no DM; download logged, a stranger's → 404; delete unlinks; an agent's `export_space` pauses on the hook path (Phase 4) — here the category is in the registry.
- [ ] **Retention**: set by the admin and by the space owner, refused to a member in words; a DM → 422.
- [ ] **The worker**: `--only` runs one step; the lock refuses a second pass; a step's error is in `errors[]` and the others ran; `worker_passes` has the counts; exit code 1 on an error.
- [ ] **375 × 740 and 1280 × 800**: the import form and preview, the exports list as cards; every control ≥ 44 px, `scrollWidth` = viewport, no console errors.

## Open questions
