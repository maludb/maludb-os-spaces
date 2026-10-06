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

## Built and proven (2026-10-05)
Built as the Files list says. **The worker**: `bin/worker.php` + `app/features/worker/{steps,outbox,digest,housekeeping}.php` (`worker_pass()`, `WORKER_STEPS`, `WORKER_SCHEDULE`, `outbox_send_batch()`, `digest_pass()`, `housekeeping_pass()`; `scheduled_pass()`, `reminders_pass()`,
`snapshots_pass()`, `prune_pass()`, `retention_pass()`, `trash_pass()`, `wiki_pass()`, `exports_pass()` (the expiry; `export_pass()` makes the queued ones, `exports_step()` is the step), `search_pass()` call the database's own `sp_pass_*()` and add what PHP owes — the log rows and the files); slice 7's `dispatches_pass()` is one step of the same
script, not a second worker. **Imports**: `app/features/imports/{readers,run,queries,present,handler}.php` (the plan, the run, the reads), `html/import.php`, `html/import/start.php`, `app/views/import/{page,partials/row,partials/preview}.php`. **Exports**:
`app/features/exports/{writers,run,queries,present,handler}.php`, `html/exports/{index,page,database,space,channel,all,delete,download}.php`, `app/views/exports/{index,partials/row}.php`. **Retention**: `app/features/channels/retention.php` (`set_retention()` with `$by`,
`remove_attachment_files()`, `drop_attachments()`; the old two-argument `set_retention()` left `write.php`), `html/channels/retention.php` tightened, the channel header's sentence. `app/views/mail/{notice,digest}.php` are the email bodies. Beside the Files list, `app/features/{imports,exports}/handler.php` are the controllers' preludes (the pattern of every feature: the requires, the notices, the preview store, the outcome words). 2 screens made real
(`import`, `export-list`; the `admin-exports` placeholder call removed), 7 actions (the eighth, `retention_set`, was slice 4's), the Import and Exports items in the Me menu. `db/023`. No rewrite was needed (every URL is canonical).
**`db/023_export_params.sql` — two schema defects the build found.** (1) `exports` had no place for what an export was asked for: `export_database` takes a `view` and `export_channel` a period `from`/`to`, and a space or channel export runs later in the worker; `params jsonb` is
appended and `mcp_exports` gains it last. (2) `sp_pass_exports_expire()` was written `UPDATE … SET storage_path = NULL … RETURNING storage_path`, which returns the NEW (null) value: it never told the worker which files to remove. It now answers the paths the rows held.
**Decisions taken in the build:**
- **The schedule is judged from `worker_passes`.** Each step that runs writes its counts and `ran_at` (the pass's own clock, `SP_WORKER_NOW` or the real one) into the row's `steps`; a step is due when its moment has come and it has not run since: `scheduled reminders outbox exports imports dispatches snapshots`
  every pass (`exports` also takes the 7-day expiry — a cheap indexed query, so a file goes within a minute of its day, not at 03:20); `search` every 5 minutes; `retention` hourly; `digest` every 15 minutes (and judges each member's hour inside); `prune` 03:00, `trash` 03:10, `wiki` 06:00, `housekeeping` 03:30 daily, in the workspace's time zone (`sp_settings.timezone`). `--only=` names steps and runs
  exactly those whatever the clock says. A due step that erred is recorded as `{failed: 1, ran_at}`, so it waits for its next moment instead of erring every minute. **An `imports` step was added** to the spec's list: a large import "runs in the worker's next pass" and needed a step of its own (`--only=imports`).
- **One lock, two forms.** `pg_try_advisory_lock(hashtext('sp_worker'))` for every pass; a pass that cannot take it prints `{"skipped": "another pass holds the lock"}` and exits 0. Slice 7's `php bin/worker.php dispatches [--limit=N]` still works and still prints its own counts (`{"step": "dispatches", called, answered, …}`), through the same lock and the same step.
  A pass prints one JSON line `{"worker": "pass", "steps": {…}, "errors": [[step, sentence]]}`; exit 1 when a step erred. An error's sentence is the database's own `P0001` text or a fixed phrase — never SQL, a table name, a body or an address.
- **The outbox.** **Email is at-most-once**: the row is claimed (`status = sent`, `attempts + 1`) BEFORE `malumail_send()` and marked `failed` after a transport failure, a 5xx or a missing key — never queued again, so a person is never mailed twice; MaluMail's 400 "suppressed" or a rejected address is `skipped` with the code `suppressed`/`rejected`. **A text is retried**: a K6 5xx or no answer leaves it
  `queued` with `attempts + 1` and `send_after + 2^attempts` minutes (2, 4, 8, 16), the fifth failure is final; 202 → `sent` with the kernel's notification id; each K6 refusal (`no_sender not_held no_verified_phone opted_out rate_limited invalid`, or any other 4xx) → `skipped` with the code, the email row standing. The link in a mail is found by joining the notice that was queued with it (same member, kind and
  instant) and `notification_record_url()`; no notice → the bell. The trail: `notification.send|skip|fail` (cron) with the channel, the kind, the member and a code — never a body, a subject or an address.
- **The digest.** For a member with `digest` on, in the hour `digest_hour` falls in THEIR time zone (`members.timezone`, else the workspace's), once a day (`notifications.dedupe_key = digest:<member>:<their date>` through `sp_notify()` of kind `digest`, which is also the bell marker): the unread notices since their last digest (title, who, when, link; 25 at most, "N more in your bell"), one outbox
  row of kind `digest`; nothing unread, nothing sent. A member without a digest is mailed per event as before.
- **Retention and the trash take their files with them.** `retention_pass()` logs one `message.delete` per channel (via `retention`, a count) and then removes the attachment rows and files of messages that no longer exist; `trash_pass()` collects, before the purge, the attachments of the purged subtree's blocks, covers, icons, row files and comments and removes those whose record is gone, logging `page.delete`
  (via `trash`, `subtree_count`). `retention_set` is now `retention.manage` anywhere or `channel.manage` as the owner of the channel's space (it was `channel.manage` anywhere), a DM is a 422 "A direct message has no retention", and the channel header says "Messages older than N days are deleted".
- **Imports.** The upload is kept as a `record_type = 'import'` attachment (`store_attachment()`, so `/files/{id}` serves it to its maker and the admin), not under `storage/imports/<id>/`. A zip is read by ZipArchive entry, never extracted by name; a path with `..`, more than 5,000 files, 500 MB unpacked or one file over 20 MB refuses the whole zip. `import_kind_from_file()`: `.md`,
  `.csv`, a zip with a `Name <32 hex>.md|csv` entry is `notion_zip`, else `markdown_zip`. **The plan** (`plan_import()`) is what `preview_zip()` counts and `run_import()` makes: a `.md` is a page (the folder of the same name its subtree), a folder with Markdown or CSV and no `.md` of its own an empty page, a folder of images alone nothing, a `.csv` a database
  (under the page of the same stem, else the folder's page), junk (`__MACOSX`, dotfiles) skipped; `pages_made` counts databases too (a database is a page). The first "# …" line is the title (a single `.md`: the page's title; a zip's file: dropped when it repeats the file name). Types are inferred (`infer_property_type()`): number, checkbox (yes/no/true/false/checked/unchecked),
  date (ISO, "October 5, 2026", d/m/Y), url, email, multi_select (a comma in a value, ≤ 20 distinct parts), select (≤ 20 distinct values, each ≤ 100 characters), else rich_text; a column named like another database of the zip whose every value is a title of that database's rows becomes a one-way relation (made after every database exists; a Notion " (https://…)" suffix is dropped). Notion: a Notion name's hex is dropped and
  becomes the page's id when free (else a fresh one), the "Name" column is the title, a page of the row database's folder named like a row is that row's body, `####` and deeper headings and the tags `<meeting-notes>`, `<transcription>`, `<tab>` become `unsupported` blocks (a marker the converter already reads) with the text kept after them. Images and files a page links by relative path are stored as block attachments
  (`image` or `file`); an absolute URL stays a URL; one the type list refuses is a problem line in the log and the block stays without its file. One transaction per page or database with `app.sp_bulk` on; `sp_version_save(…, 'import')` for a page with content; `sp_search_catch_up()` after. **A failure ends the import `failed` with its sentence and what was made stays**; a refused value is a problem line
  (the first 200) and the row stands. A file under 2 MB runs in the request (`IMPORT_INLINE_BYTES`), anything larger waits for the worker's `imports` step and the card polls itself every 3 seconds. **The preview** is `import_start` with `preview=yes` (a zip is stored under `storage/imports/preview/<member>-<token>/`, one day) and the screen `/import?preview=<token>`, whose "Import this" posts `import_start` with `preview_token`
  instead of a file (used once, the maker's alone). Both are additive parameters of the same action; the manifest row is unchanged.
- **Exports.** `start_export()` files the request, `run_export()` writes it AS the maker (`app.member_id` set for the pass), so `sp_page_markdown()`, `sp_page_tree()`, `sp_database_rows()`, `sp_channel_history()`, `sp_thread()` and the views answer for them and nothing they may not see is written. `exports.format` is the CONTENT format (md, html, csv, json; `zip` for everything); the file is a zip when it holds several things
  (a page with subpages or images — images are copied into `files/` and the links made relative —, a space as md or html, everything). A page and a database run in the request, a space, a channel and everything are queued. A database's CSV shows each property as a cell shows it (`display_value()`: a relation by its rows' titles, a rollup as displayed, people by name, a checkbox Yes/No), the rows and visible properties of its `view` when given.
  A space export is the tree of pages the caller sees (a page whose parent they cannot see is hoisted to the root), every database a CSV plus its rows as pages in a folder of the same name, `index.md` (the sidebar as links); as `json` its pages with their Markdown and its databases. A channel (period `from`/`to` inclusive dates, both optional): json (threads nested, authors by name, reactions with counts, attachment names), md (a heading a day, replies quoted),
  csv (a row per message and reply). Everything: a folder per space the caller sees with its pages, `index.md` and its channels as Markdown; never a DM. The file also has a `record_type = 'export'` attachment, so `/files/{id}` serves it to its maker and the admin; `download.php` is the spec's door (404 for anyone else and for an expired one). `export_delete` takes the file and the attachment and leaves the row, which the list then calls
  "expired" (a `done` row with no path). An export of a space or channel needs `export.space` and the space's owner (the admin too); a space owner of the kernel's `space_owner` role holds it. `export_page` and `export_database` do not pause for an agent; `export_space`, `export_channel`, `export_all` carry `external_send` in the registry (the hook's pause is Phase 4's).
- **Deploy.** The vhost raises PHP's upload ceiling to 64 MB (`php_admin_value` under mod_php): PHP's own 2 MB default would have refused every attachment and import above it although the workspace limit is 25 MB. `spaces-worker.service` gains `TimeoutStartSec=600`; `ROOT_STEPS.sh` step 4 says what root checks. `tests/phase2/servers.sh` starts `php -S` with the same ceiling and its `stop` pattern matches the new command line.
- Phase 2's `gates.php` now expects `/exports/` open to an owner and a member (200, `export.own`; a guest has none) and no longer names it as an unbuilt placeholder; `vhost.php` resolves `/exports/` and `/import` as 200. The Me menu gains Import (`pages.write|databases.write`) and Exports (`export.own`); the admin's own "Exports" item stays.
**Decided by the planning model 2026-10-06:** (1) Spaces' own tags (`<tab>`, `<meeting-notes>`, `<transcription>`; `heading_4` as `####`) until an export sample shows Notion's form; the owner may supply one. (2) An import's uploaded file is kept as long as an export (7 days, `exports.expires_at`'s own default — `sp_settings` has no export-retention column) from the import's finish or failure; the `exports` step then removes the `import` attachment (`import_files_expire()`, counted as `import_files`, logged `import.file_expire`), the import row stays and its log says the file is gone, and the card shows it.
**Proven by `tests/phase3/slice8/run.sh` — OUTBOX 31, DIGEST 18, PASSES 35, RETENTION 22, IMPORTS 66, EXPORTS 68, WORKER 31, JSON 28, BROWSER 43 (342 checks green); Phase 2, slice 6 and slice 7 re-run green.** Every box of the checklist has at least one `ok()` line. The worker is run as a process (`php bin/worker.php`) against the scratch database, the fake kernel (K6's states, the chat endpoint) and the fake MaluMail; the fixtures (a Markdown file, a Markdown zip, a Notion zip, a CSV, a damaged zip, a path-escaping zip)
are built once into `tests/phase3/slice8/fixtures/` (the 3 MB zip is generated, never committed). A proof ages what it must with `as_postgres()` (a message's `created_at` and a version's are guarded and immutable: the proof bypasses the guard as the cluster's superuser, the application never does).

## Open questions
