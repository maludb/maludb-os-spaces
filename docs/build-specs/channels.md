# Build spec: channels and messages — THE SECOND EXEMPLAR (slice 4)

Built by the planning-class model. The second novel surface: a conversation being read live on a phone — channels of four kinds, the
composer, polling without a socket, threads with "also send to channel", reactions, pins and bookmarks, edit and delete, the unread line,
mute and star, saved, scheduled, reminders, mentions, and the agent mention → dispatch → reply loop with its thinking state. Every later
slice that shows a list that moves (notifications, the activity feed, dispatches) copies its polling pattern.
Schema: `channels`, `channel_members`, `dm_pairs`, `messages`, `message_mentions`, `message_reactions`, `saved_messages`, `message_links`,
`channel_pins`, `channel_bookmarks`, `reminders`, `sp_in_channel()`, `sp_visible_channel_ids()`, `sp_can_post()`, `sp_dm_open()`,
`sp_group_dm_open()`, `sp_channel_mark_read()`, `sp_channels_guard()`, `sp_channel_members_guard()`, `sp_messages_guard()`, `sp_messages_after()`
(db/010); `agent_dispatches`, `sp_dispatch_from_message()`, `sp_message_notify()`, `sp_notify()`, `attachments` (db/012); `sp_message_row()`,
`sp_channel_history()`, `sp_thread()`, `sp_thread_context()`, `sp_unread()`, `sp_activity_feed()`, `sp_my_dms()` (db/015); `sp_pass_scheduled()`,
`sp_dispatches_due()`, `sp_dispatch_record()` (db/016); `mcp_channels`, `mcp_channel_members`, `mcp_messages`, `mcp_message_reactions`,
`mcp_saved_messages`, `mcp_channel_pins`, `mcp_channel_bookmarks`, `mcp_reminders`, `mcp_members`, `mcp_emoji` (db/017); the converter and
renderer of slice 3 (`app/richtext/`). Never modify them.
**The database is the referee**: a channel's name and kind, who is in it (a DM's two, a group's 3–9, a guest only by being added), a thread's one
level, the author alone editing, the tombstone, a scheduled message unsent until its time, the counters, the mentions extracted, the dispatch
written when an agent is mentioned or DM'd — all in db/010 and db/012's triggers. The handlers call the verbs and show the sentences.

## Screens (375 px is the design — a channel is read and answered on a phone; the three panes at 1280)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `channel-browse` | `/channels/?space=&q=&archived=` | the channels of my spaces as cards by space: the ones I follow (unread badge), the ones I can join (**Join**/**Follow**), private ones I am in; archived apart; **New channel** per space with `channels.create` |
| `channel-add` | `/channels/new?space=` | the form: space, name (lowercase; the slug shown as you type), kind (public, private), topic, purpose |
| `channel-view` | `/channels/{id}?message=` | **the conversation**: the header (name, topic, member count, pins count, star, notify, the member list link, the search-in-channel link); messages oldest to newest, newest at the bottom (the view opens scrolled to the first unread or the end); day dividers; the **unread line**; each message: avatar, name (an agent chipped), time, the body rendered, reactions with counts (tap to toggle), a thread summary ("3 replies · Marco, Priya · 2h"), attachments as chips or thumbnails, the hover/long-press menu (react, reply in thread, save, pin, copy link, edit, delete); a tombstone *"This message was deleted"*; a scheduled message of mine shown in a `secondary` box with its time; the **composer** at the bottom; **Load earlier** at the top; polling while visible |
| `channel-edit` | `/channels/{id}/edit` | name, topic, purpose, kind (public ↔ private), retention (`retention.manage` or `channel.manage` — `retention_set`) |
| `channel-members` | `/channels/{id}/members` | who is in (private) or follows (public): a table; add a member or an agent (the picker), add a guest (`share.guest`), remove |
| `channel-pins` | `/channels/{id}/pins` | pinned messages and pages, bookmarks (title, URL or page, emoji); add a bookmark; unpin |
| `thread-view` | `/channels/{id}/threads/{message}` | the thread: the first message and every reply; the reply composer with **Also send to #channel**; at 1280 it opens in the right pane beside the channel, at 375 it is this page |
| `dm-list` | `/dm/` | my DMs and group DMs as cards: the people (agents chipped), the last line, unread, when |
| `dm-new` | `/dm/new` | the people picker (members, agents, the guests I may see): one person → `dm_open`, several → `group_dm_open` |
| `dm-view` | `/dm/{id}` | `channel-view` for a DM: the same screen, the header shows the people; a DM to an agent shows the agent chip and, while a dispatch runs, *"Seamus is thinking…"* |
| `scheduled-list` | `/channels/scheduled` | my scheduled messages with their channel and time; change the time; cancel |
| `reminder-list` | `/reminders/` | my reminders: due, coming, done; mark done |
| `saved` | `/saved` | Phase 2's placeholder made real: the messages I saved, newest first, each linking to its channel and thread; my reminders summary |

## The composer (`composer` partial — one for the channel, the thread, the DM)
- A `contenteditable` box (the editor's run serializer from slice 3, one block, type paragraph) with the Markdown shortcuts of a message (`**`, `*`,
  `` ` ``, ```` ``` ```` for a code block, `>`), **`@`** the mention picker (members and agents of the channel's space, departments, `@channel` and
  `@here` for a person — never offered to an agent's session), **`:`** the emoji picker (`mcp_emoji`), **Attach** (`file_upload` first, the
  attachment ids sent with the message; a thumbnail strip), **Send later** (a time picker → `schedule_for`), Enter sends, Shift-Enter a newline;
  on a phone the Send button sends. Without JavaScript: a plain `<textarea>` whose text is converted by the Markdown converter — still a message.
- `message_post` → the message appears at the bottom at once (the handler returns its rendered row); the channel's `last_read` moves to it.
- An **@channel / @here / @everyone** in a person's message is a plain mention run; the trigger notifies everyone. An **agent** never posts one: the
  handler strips the three from an agent's body (`source = agent`) and the manifest's `channel_announce` is the explicit, paused way.

## Live-ness without a socket (design §8, D9)
- `channel-view` carries `hx-get="/channels/{id}/since?after=<last id>" hx-trigger="every 3s [document.visibilityState=='visible'], every 20s"
  hx-target="#messages" hx-swap="beforeend"`; the endpoint answers **204** with nothing new (one indexed query on `(channel_id, id)`), else the
  new rows rendered (`sp_channel_history(channel, NULL, since)`), plus `HX-Trigger: {"readMoved": <id>}` so the client scrolls when at the bottom.
  Edits, deletes, reactions and thread counts of messages already shown arrive as **out-of-band swaps** of their row (`hx-swap-oob`) — the endpoint
  includes rows whose `edited_at`, `deleted_at`, `reply_count` or reactions changed since the client's `rev` (the client sends `rev=<max updated>`;
  the server keeps a per-channel `messages.updated_at`? **No** — not in the schema: the endpoint re-renders the last 50 rows' reaction/thread/edit state
  as a compact JSON `{id: hash}` beside the new rows, and the client re-fetches a row whose hash changed — one request, no extra column).
- The thread pane polls the same way (`/channels/{id}/threads/{message}/since`). The DM list and the sidebar's unread badges refresh with the
  heartbeat (every 60 s; `sp_unread()`).
- **Load earlier**: `?before=<first id>` prepends 50 rows and keeps the scroll position.
- The unread line: on open, `sp_unread()` gives the first unread id; the line renders above it; `channel_mark_read` is called when the view reaches
  the bottom (and on open when it starts at the bottom), never before.

## Mentions, notifications and the agent loop
- The trigger extracts mentions and notifies (db/012): the mentioned member or department's members, the thread's participants on a reply, everyone
  in a DM. The person's prefs decide email and text (the outbox; slice 8 sends). A muted channel is silent.
- A message that mentions an agent in a channel the agent is in, or any message in a DM/group DM with an agent, writes an `agent_dispatches` row
  (`sp_dispatch_from_message`). **This slice shows the loop and proves its halves**: the row written; `sp_dispatch_record(d, 'running', run)`
  inserts the `agent_pending` placeholder that renders *"Seamus is thinking…"* in the thread; `'answered'` with a reply turns it into the agent's
  message in the thread and notifies the asker (`agent_replied`). **The worker step that calls the kernel's chat endpoint is slice 7's**
  (`agents-in-spaces.md`); here the proof calls the SQL functions as the worker will.

## Files (exactly these)
- `html/channels/index.php` (`channel-browse`) · `form.php` (`channel-add`, `channel-edit`) · `view.php` (`channel-view`, and `dm-view` via `/dm/{id}` →
  `html/dm/view.php` which includes it) · `since.php` · `members.php` · `pins.php` · `scheduled.php` · `html/channels/threads/view.php` (`thread-view`) ·
  `threads/since.php`
- `html/channels/save.php` · `archive.php` · `unarchive.php` · `delete.php` · `join.php` · `leave.php` · `notify.php` · `retention.php` · `read.php` ·
  `members/add.php` · `members/add-guest.php` · `members/remove.php` · `pins/add.php` · `pins/remove.php` · `bookmarks/save.php` · `bookmarks/delete.php`
- `html/channels/messages/post.php` · `reply.php` · `announce.php` · `edit.php` · `delete-own.php` · `delete.php` · `schedule.php` · `unschedule.php` · `react.php` ·
  `unreact.php` · `save.php` · `get.php` (one row re-rendered)
- `html/dm/index.php` (`dm-list`) · `new.php` (`dm-new`) · `view.php` · `open.php` · `open-group.php`
- `html/reminders/index.php` (`reminder-list`) · `save.php` · `done.php` · `html/saved.php` (`saved`)
- `app/features/channels/{queries,present,write,handler}.php` · `app/features/messages/{queries,present,write,handler}.php` · `app/features/reminders/{queries,write}.php`
- `app/views/channels/{index,form,view,members,pins,scheduled,thread,partials/channel-card,partials/message-row,partials/day-divider,partials/unread-line,partials/composer,partials/thread-summary,partials/reaction-bar,partials/pending-row}.php` ·
  `app/views/dm/{index,new,partials/dm-card}.php` · `app/views/reminders/index.php` · `app/views/saved.php`
- `html/assets/js/channel.js` (the composer, the pickers, the scroll and read logic, the hash re-fetch) — shares the run serializer with `editor.js` (`html/assets/js/richtext.js`)

## Query functions (signatures fixed)
- `find_channels(PDO, array $filters, int $limit = 100): array` (`mcp_channels`) · `find_channel(PDO, int $id): ?array` · `channel_history(PDO, int $channelId, ?int $before, ?int $since, int $limit = 50): array` (`sp_channel_history()`, decoded rows) · `thread(PDO, int $rootId): array` · `message_row(PDO, int $id): ?array`
- `save_channel(PDO, ?int $id, array $fields, int $by): int` · `archive_channel(PDO, int $id, bool $archive, int $by): void` · `delete_channel(PDO, int $id): void` · `join_channel(PDO, int $id, int $memberId): void` · `leave_channel(PDO, int $id, int $memberId): void` · `add_channel_member(PDO, int $id, int $memberId, int $by): void` · `remove_channel_member(...)` · `set_channel_notify(PDO, int $id, int $memberId, array $fields): void` · `set_retention(PDO, int $id, ?int $days): void`
- `post_message(PDO, int $channelId, array $body, ?int $threadRoot, bool $alsoToChannel, ?string $scheduleFor, array $attachmentIds, int $by): int` · `edit_message(PDO, int $id, array $body): void` · `delete_message(PDO, int $id, int $by): void` · `schedule_message(PDO, int $id, ?string $when): void` · `unschedule_message(PDO, int $id): void`
- `toggle_reaction(PDO, int $messageId, int $memberId, string $emoji, bool $on): void` · `save_message(PDO, int $messageId, int $memberId, bool $on): void` · `pin(PDO, int $channelId, ?int $messageId, ?string $pageId, int $by): void` · `unpin(...)` · `save_bookmark(PDO, ?int $id, int $channelId, array $fields, int $by): int` · `delete_bookmark(PDO, int $id): void`
- `mark_read(PDO, int $channelId, int $messageId): void` (`sp_channel_mark_read()`) · `unread(PDO): array` (`sp_unread()`) · `my_dms(PDO): array` · `open_dm(PDO, int $other): int` · `open_group_dm(PDO, array $members): int`
- `my_scheduled(PDO, int $memberId): array` · `my_saved(PDO, int $memberId, int $limit): array` · `my_reminders(PDO, int $memberId, bool $includeDone): array` · `set_reminder(PDO, int $memberId, array $fields): int` · `reminder_done(PDO, int $id, int $memberId): void`
- `row_hashes(array $rows): array` (id → md5 of edited_at|deleted_at|reply_count|reactions) · `strip_shouts(array $body): array` (an agent's @channel/@here/@everyone runs → text)
- `mention_candidates(PDO, int $channelId, string $q): array` · `emoji_lookup(PDO, string $shortcode): ?string`

## Handlers (every one: `sp_handler_begin()`; the gate; `sp_guard()`; `log_activity` with `channel_id`, `message_id`, `space_id`; `sp_done()`; `HX-Trigger: messageChanged` or `channelChanged`)
- `channels/save.php` (`channel_create` / `channel_update`): create → member of the space with `channels.create`; update → topic and purpose for `channel`,
  the rest `owner` or `channel.manage`; `channel.create|update` (`after.name`, `kind`, `topic` ≤ 200). `archive.php` (`deletion`), `unarchive.php`, `delete.php` (admin,
  an archived one; `deletion`), `join.php`, `leave.php` (never the space's default — the guard's words), `notify.php` (own), `retention.php` (`retention_set`; `other`), `read.php`.
- `members/add.php` (`channel`), `members/add-guest.php` (`owner` and `share.guest`; `external_send`; `channel.guest_add`), `members/remove.php` (`owner` or `channel.manage`).
- `pins/add.php`, `pins/remove.php`, `bookmarks/save.php`, `bookmarks/delete.php`: `channel`; `channel.pin|unpin|bookmark_save|bookmark_delete`.
- `messages/post.php` (`message_post`): `require_can_post()`; the body from `markdown` (the converter) or the composer's runs; an agent's shouts stripped;
  `message.post` (`message_id`, `length`, `mentions`, `has_attachments`, `scheduled_for` — never the body); the rendered row returned. `reply.php` (`thread_reply`):
  `thread.reply` (+ `also_to_channel`). `announce.php` (`channel_announce`): `channel`; `other`; `message.announce` (`reach`). `edit.php` (own; `message.edit`),
  `delete-own.php` (own; `message.delete_own`), `delete.php` (`owner` or `channel.manage`; `deletion`; `message.delete`), `schedule.php`, `unschedule.php` (own),
  `react.php`, `unreact.php` (`channel`; `message.react|unreact` with `emoji`), `save.php` (`message.save`).
- `dm/open.php` (`dm_open`), `dm/open-group.php` (`group_dm_open`): `dm.write`; `channel.create` (`kind dm|group_dm`, `member_ids`); location `/dm/{id}`.
- `reminders/save.php` (`reminder_set`), `reminders/done.php` (`reminder_done`): own; `reminder.set|done`.

## Action manifest entries
Screens: `channel-browse`, `channel-add`, `channel-view`, `channel-edit`, `channel-members`, `channel-pins`, `thread-view`, `dm-list`, `dm-new`, `dm-view`,
`scheduled-list`, `reminder-list`, `saved` (real). Actions (32): `channel_create`, `channel_update`, `channel_archive`, `channel_unarchive`, `channel_delete`,
`channel_join`, `channel_leave`, `channel_member_add`, `channel_guest_add`, `channel_member_remove`, `channel_notify_set`, `retention_set`, `channel_pin`,
`channel_unpin`, `bookmark_save`, `bookmark_delete`, `message_post`, `thread_reply`, `channel_announce`, `message_edit`, `message_delete_own`, `message_delete`,
`message_schedule`, `message_unschedule`, `reaction_add`, `reaction_remove`, `message_save`, `channel_mark_read`, `dm_open`, `group_dm_open`, `reminder_set`,
`reminder_done`. Agent approvals: `channel_archive`, `channel_delete`, `message_delete` (`deletion`); `channel_guest_add` (`external_send`); `channel_announce`,
`retention_set` (`other`). `PARTIAL_UPDATE_TARGETS` gains `/channels/save.php => ['channels', 'channel', 'mcp_channels', 'channel_id']`.

## Activity log events (every row: `channel_id`, `space_id`; a message's `message_id`)
`channel.create|update|archive|unarchive|delete|join|leave|member_add|guest_add|member_remove|notify_set|retention_set|pin|unpin|bookmark_save|bookmark_delete|read`,
`message.post|edit|delete|delete_own|announce|schedule|unschedule|react|unreact|save`, `thread.reply`, `reminder.set|done`, `agent.dispatch` (the trigger's row is
logged by the worker — slice 7), `screen.view`. **No row carries a message's body.**

## Notifications this slice queues (the triggers; the outbox sender is slice 8)
| Step | Who is told | Kind |
|---|---|---|
| a message mentions a member, a department, @channel/@here/@everyone | them, by their channel setting and prefs | `mention` |
| a reply lands in a thread | the thread's author and earlier repliers | `reply` |
| a message in a DM or group DM | the others in it | `dm` |
| an agent's reply is posted (`sp_dispatch_record` answered) | the asker | `agent_replied` |
| a reminder falls due (the worker, slice 8) | the person | `reminder` |

## Status vocabulary
Channel kind chips: public `feather-hash`, private `feather-lock`, DM `feather-user`, group `feather-users`; archived `dark`. An agent's name carries the
`agent` chip; a pending reply row is `secondary` with a pulsing dot. Ids: `channel-list`, `channel-card-{id}`, `messages`, `message-row-{id}`, `message-row-{id}-menu`,
`unread-line`, `day-{date}`, `composer`, `composer-send`, `composer-attach`, `thread-pane`, `thread-row-{id}`, `dm-card-{id}`, `reminder-row-{id}`, `saved-row-{id}`.

## Out of scope for this slice
The worker (scheduled sends, reminders due, the outbox, dispatch execution — 7 and 8), search (6), the Activity feed screen (6), exports of a channel (8),
channel retention's pass (8), the admin's retention page (9).

## Proof (`tests/phase3/slice4/run.sh`: the scratch database `sp_dev4`, members through dev hand-offs, curl with signed action and run tokens, headless
Chromium at 375 × 740 and 1280 × 800 — two sessions open on one channel; the registry `--check`; `sync_approvals --check`)
The world (`tests/phase3/slice4/lib.php` `channel_world()`): Product (closed; Marco owner; Priya, Dana members; Seamus the agent a member of General and
Product), `#launch` public, `#leads-only` private (Marco, Priya), the guest Ann added to `#launch` by Marco; General's `#general`.
- [ ] **Channels**: create in Product (`#launch`), a name with capitals refused in the database's words, a duplicate refused; private `#leads-only` with its
  members; Dana (not in the space) sees neither; Bea sees `#launch` but not `#leads-only`; the admin reads both; archive → readable, a post refused in
  words; unarchive; delete only when archived; join/follow/leave (General's `#general` refuses leaving); notify none silences; star and section show in the sidebar.
- [ ] **Messages**: post, the rendered row returned and visible to a second session within 3 s (the poll; 204 when nothing); Load earlier pages back 50;
  the unread line sits above the first unread for Marco and moves when he reaches the bottom; edit by the author (the `edited` mark), another's edit
  refused; delete own → the tombstone keeps the thread's shape; Marco deletes Priya's (`deletion` in the registry); a reply, a reply to a reply refused,
  "also send to channel" shows it in both; the thread pane at 1280 and the page at 375; reactions toggle and count, a non-member's refused; pins and
  bookmarks; saved; a scheduled message unseen by others, shown to its author with its time, released by `sp_pass_scheduled()` in the proof and then
  seen; an attachment posted, served through `/files/{id}`, refused to a non-member.
- [ ] **Mentions and notices**: `@Priya` notifies her (bell; an email row since she is away); `@Engineering` notifies the department; `@channel` by Marco
  notifies everyone in the space's public channel; a muted member gets nothing; `@channel` typed by the agent's session is stripped; `channel_announce`
  is `other` in the registry.
- [ ] **DMs**: `dm_open` makes the pair once and finds it again; a third member refused; a group of three found again by its members; a guest cannot DM
  someone she cannot see; a DM notifies the other.
- [ ] **The agent loop**: Priya mentions `@Seamus` in `#launch` → a dispatch row (`mention`, `chat`, the thread as the conversation); the proof plays the worker:
  `sp_dispatch_record(running)` → the thread shows "Seamus is thinking…" within 3 s; `answered` with Markdown → the reply as Seamus in the thread, the
  pending row replaced, Priya told; a DM to Seamus dispatches `dm`; Seamus's own reply dispatches nothing.
- [ ] **JSON mode**: every action under a signed action token answers `{ok, did, record_id, location, refresh}` with its facts; the expert (run token + relay)
  posts in a channel it is in, is refused where it is not (403 in words), its `@channel` stripped, its `thread_reply` lands in the thread.
- [ ] **375 × 740 and 1280 × 800**: the channel on a phone — the composer above the keyboard, the long-press menu, the thread as a page; at 1280 the three
  panes with the thread on the right; two browsers see each other's messages within 3 s; every control ≥ 44 px, `scrollWidth` = viewport, no console
  errors; JavaScript off posts through the textarea.

## Open questions
