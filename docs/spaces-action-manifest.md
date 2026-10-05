# Spaces Action Manifest

2026-10-05 · **Phase 1, for the checkpoint**

> The registry the command bar (through the kernel's chat endpoint), the kernel's Actions MCP and the screens resolve against.
> A screen or action missing here cannot be reached by voice or by an agent — unfinished design, like a question with no tool.
> Pairs with `db/` (tables), `docs/spaces-mcp-tool-surface.md` (read tools) and the slice specs in `docs/build-specs/`.
> Generated into `mcp/action_registry.json` by `bin/build_action_registry.php`; an action whose file does not exist yet is
> registered but not exposed. The approval categories here ARE `maludb-os.json` `approvals[]` (`bin/sync_approvals.php` rewrites
> that block from this file; `--check` in every proof).

## Conventions

### Screens
- **Ids** are kebab-case `{entity}-list`, `{entity}-add`, `{entity}-view`, `{entity}-edit`, plus named screens.
- **Canonical URLs** need the rewrites in `deploy/apache-spaces.conf`: `/{x}/new` → `form.php`, `/{x}/{id}/edit` → `form.php?id=`,
  `/{x}/{id}` → `view.php?id=`, `/{x}/{id}/{page}` → `{page}.php?id=`; a page, a database, a view and a comment are **UUIDs**
  (`/pages/{id}` carries one); a space, a channel, a message and everything administrative are integers. A channel and a page are
  read at 375 px first; the editor is designed at 1280 px and works at 375; no screen is a modal (the slash menu, the mention
  picker, the emoji picker and the property editor are popovers inside the editor).
- **Prefill params** become query-string values the GET controller reads.
- **Every screen partial stamps** `data-screen`, `data-entity`, `data-record-id` on `#page-content`, so "this page", "pin that" and
  "share it with Priya" resolve against the page the user is on.
- **The public door** (`/p/{token}`, its subpages and files) is a screen with no session; it has no POST and no action (no agent
  reaches it) and is listed apart at the end.

### Actions
- **Name** `{entity}_{verb}`; the **log event** `{entity}.{verb}` is what the handler writes with `log_activity()` (with `space_id`,
  `channel_id`, `message_id`, `entity_uuid` as the record says) and what the kernel's approval policies match — so an action an
  agent's call must pause on has **its own log event**. `.trash` moves a page to the trash; `.delete` destroys (a purged page, a
  channel, a database, a message's words); `.archive` keeps a space or a channel out of the way, readable.
- **Endpoints** are POST to `/{base}/{file}` with `require_post()`, `verify_csrf()` (or the relayed action token), the right, the
  record's permission (`require_page_level()`, `require_channel()`, `require_can_post()`, the space's owner) and `log_activity()`.
  **A write calls the database's verb** (`sp_page_create()`, `sp_block_insert()`, `sp_page_restrict()`, `sp_dm_open()`, …) inside
  `sp_guard()` and shows its sentence; PHP never re-implements a rule the database keeps (a stale block, a locked page, a thread's
  depth, a DM's two people). Handlers report through `emit_action_status()`; a create's `location` ends in the new record's id.
- **Params:** **bold** = required; `[]` = repeated; parentheses = a hint (no comma or semicolon inside; no note after the last
  parameter). A param named for a record (`space`, `page`, `parent`, `database`, `view`, `channel`, `message`, `thread`, `comment`,
  `block`, `template`, `member`, `department`, `agent`, `guest`, `proposal`, `version`, `export`, `import`) accepts an id or a name,
  resolved through the application's own tool (`docs/spaces-mcp-tool-surface.md`, resolution table); a page also accepts its title,
  a channel its `#name`. **`markdown`** is the one text format an agent writes (headings, lists, to-dos, quotes, callouts, code, tables,
  images by URL, `[[Page title]]`, `@Name`); `level` is one of `view`, `comment`, `edit_content`, `edit`, `full`; dates and times are
  ISO 8601 in the person's time zone. `any field of X` = X's fields, all optional, a partial update.
- **Who** is enforced in the endpoint: `own` = the record's own person (their message, their comment, their favorites, their tokens);
  `member` = `sp_is_member_here()` (a Member, a Space owner, an admin or an agent granted Member — never a guest unless the row says
  `guest`); `view` / `comment` / `edit` / `full` = that level on the page (`require_page_level()`); `channel` = in the channel and may
  post (`require_can_post()`); `owner` = the space's owner (`sp_is_space_owner()`); `share.guest`, `publish.web`, `retention.manage`,
  `trash.purge`, `export.*`, `settings.manage` = that right; `admin` = `sp_is_admin()`. A super-admin holds every right. An agent is
  granted like a person.
- **Undo** is the inverse `undo_last` applies; — means it cannot be undone and the reply says so.
- **Confirm (✔)** = the command bar asks first (destructive, or it reaches people outside the room or the business).
- **Agent approval** names the kernel's category that pauses the action when an **agent** performs it (design §5, D11):
  `external_send` (content leaves the business or reaches an outsider — publishing, rotating or revoking a public page, sharing a page
  or a channel to a guest, exporting a space, a channel or everything), `deletion` (trashing or purging a page, deleting a database,
  a channel, a block with children, another's message or comment, archiving a channel, restoring a version — which rewrites the
  page), `other` (shouting with @channel/@here/@everyone; retention; a space's kind, archive or deletion; removing a space member;
  locking a page; a database's schema; publishing a template; the settings). People are never paused. The categories are exactly
  `maludb-os.json` `approvals[]`.
- **Refresh:** a data action returns `HX-Trigger: {entity}Changed` (camelCase entity).
- **Log payloads** (what `before`/`after` must carry) are in the tool surface; **a message's body, a page's text, a comment's words
  and a public token are never in a payload** (ids, titles, counts, the block type and the channel name are).

### Result contract
```json
{"status": "success", "did": "Posted in #launch (thread of \"Are we launching Monday?\")", "record_id": 412, "undo_id": "act_31", "refresh": "messageChanged"}
{"status": "pending_approval", "did": "Publishing \"Pricing\" to the web waits for a person's approval", "approval_request_id": 17}
{"status": "error", "message": "Page \"Pricing\" is locked: unlock it to edit."}
```

## Home, me and the shell (Phase 2 and slice 9)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `home` | `/` | home: unread channels with their first unread lines, mentions and replies waiting, pages recently edited in my spaces, my favorites, pages I own needing verification, the Librarian's last Monday note; a space owner's pending joins and unanswered questions; the admin's pending dispatches, published pages and trash size |
| `activity` | `/activity` | my Activity: who mentioned me, replied to me, reacted to me, commented on my pages (params: `since`, `kind`) |
| `saved` | `/saved` | the messages I saved (Later) and my reminders |
| `notifications` | `/notifications` | what the bell holds, and to mark it read |
| `trail` | `/trail` | my own activity trail, or a record's history (params: `space`, `channel`, `message`, `page`) |
| `settings` | `/settings/` | how I am told (email, text, which kinds, digest), my status line, my time zone |
| `tokens` | `/settings/tokens/` | my own MCP tokens: mint one, revoke one |

Actions (base `/settings/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `prefs_save` | `prefs.php` | email_enabled (yes or no), text_enabled (yes or no), digest (yes or no), kinds[], text_kinds[], away_minutes | restore prior | | | `prefs.save` | own |
| `status_set` | `status.php` | text (up to 100 characters; empty clears), emoji, until (a time; empty means until cleared) | status_set | | | `status.set` | own |
| `notification_read` | `notifications/read.php` | notification (empty marks all) | — | | | `notification.read` | own |
| `token_mint` | `tokens/mint.php` | **label**, scope (mcp or api) | token_revoke | | | `token.mint` | own |
| `token_revoke` | `tokens/revoke.php` | **token** | — | ✔ | | `token.revoke` | own |

## Spaces and membership (slice 1)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `space-list` | `/spaces/` | the spaces as cards by kind: mine, open ones to join, closed ones to request; the private ones only as a member (params: `kind`, `q`) |
| `space-add` | `/spaces/new` | to make a space: name, icon, kind, description, levels, wiki |
| `space-view` | `/spaces/{id}` | a space's home: description, its sections with root pages, its channels, its members with agents marked, the wiki's status when it is one, Join or Request |
| `space-edit` | `/spaces/{id}/edit` | to change a space's name, icon, description, kind, default levels, wiki setting |
| `space-members` | `/spaces/{id}/members` | who is in the space and who owns it; add a member, a department's members or an agent; make an owner; remove |
| `space-sections` | `/spaces/{id}/sections` | the sidebar's sections of the space and their order; move root pages between them |
| `space-requests` | `/spaces/{id}/requests` | the pending requests to join a closed space, to approve or decline |
| `space-templates` | `/spaces/{id}/templates` | the templates of this space and the workspace's: open one, apply one, publish a page as a template |

Actions (base `/spaces/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `space_create` | `save.php` | **name**, kind (open or closed or private; closed by default), icon (an emoji), description, member_level (edit by default), everyone_level (an open space; view by default), is_wiki (yes or no) | space_archive | | | `space.create` | member |
| `space_update` | `save.php` | **space**, any field of space_create | restore prior | | | `space.update` | owner |
| `space_kind_set` | `kind.php` | **space**, **kind** (open or closed or private) | restore prior | ✔ | other | `space.kind_set` | owner |
| `space_archive` | `archive.php` | **space** | space_restore | ✔ | other | `space.archive` | owner |
| `space_restore` | `restore.php` | **space** | space_archive | | | `space.restore` | owner |
| `space_delete` | `delete.php` | **space** (archived and empty of pages) | — | ✔ | other | `space.delete` | admin |
| `space_join` | `join.php` | **space** (an open one) | space_leave | | | `space.member_add` | member |
| `space_leave` | `leave.php` | **space** (not the default one) | space_join | ✔ | | `space.member_remove` | member |
| `space_join_request` | `request.php` | **space** (a closed one), message | space_join_withdraw | | | `space.join_request` | member |
| `space_join_withdraw` | `request-withdraw.php` | **space** | — | | | `space.join_withdraw` | own |
| `space_join_decide` | `request-decide.php` | **request**, **decision** (approve or decline) | — | ✔ | | `space.join_decide` | owner |
| `space_member_add` | `members/add.php` | **space**, member (a person or an agent) or department (all its members), role (member or owner; member by default) | space_member_remove | | | `space.member_add` | owner |
| `space_member_remove` | `members/remove.php` | **space**, **member** | space_member_add | ✔ | other | `space.member_remove` | owner |
| `space_owner_set` | `members/owner.php` | **space**, **member**, owner (yes or no) | restore prior | ✔ | | `space.owner_set` | owner |
| `section_save` | `sections/save.php` | **space**, **name**, after (the section it follows), section (to rename or move an existing one) | section_delete | | | `section.save` | owner |
| `section_delete` | `sections/delete.php` | **section** (its pages go to the space root) | — | ✔ | | `section.delete` | owner |
| `page_section_set` | `sections/assign.php` | **page** (a root page of the space), section (empty for none), after (the page it follows) | restore prior | | | `page.section_set` | owner |

## Pages: the tree, sharing, the wiki, the trash, templates (slice 2)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `page-list` | `/pages/` | the pages I can see, newest edited first, by space; a search by title (params: `space`, `q`, `kind`, `changed`) |
| `page-add` | `/pages/new` | to make a page: title, icon, where (a space or a parent page, or private), a template (params: `space`, `parent`, `template`, `private`) |
| `page-view` | `/pages/{id}` | the page: for a reader the rendered tree, breadcrumb, icon and cover, properties when a row, comments; for an editor the same page editable (slice 3); the page menu — Share, Publish, Lock, Verify, Favorite, Move, Duplicate, Export, History, Trash |
| `page-edit` | `/pages/{id}/edit` | to change a page's title, icon, cover (the body is edited in place on the page) |
| `page-share` | `/pages/{id}/share` | who can see the page and why: the inherited principals, the explicit ones, their levels; add a member, a department, a guest; restrict; the public link's state |
| `page-move` | `/pages/{id}/move` | to move a page under another page, to a space's root, or to my private pages |
| `page-history` | `/pages/{id}/history` | the page's versions: open one, compare two, restore one (slice 3 fills the diff) |
| `page-publish` | `/pages/{id}/publish` | to publish the page to the web: subpages yes or no, noindex, a database's layout; the link; rotate; unpublish |
| `page-trash` | `/pages/trash` | my trash (the admin's: everyone's): restore or purge; when each is purged |
| `template-list` | `/templates/` | the templates gallery: the workspace's and my spaces'; apply one |
| `wiki-view` | `/spaces/{id}/wiki` | a wiki space's status: every page with its owner and verification, the expired first; stale, orphaned, duplicated, broken (slice 6 fills the reports) |

Actions (base `/pages/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `page_create` | `save.php` | **title**, space or parent (a space's root or a parent page; neither makes a private page), icon (an emoji), template (a template to apply), markdown (the body to start with) | page_trash | | | `page.create` | edit on the parent or member of the space |
| `page_update` | `save.php` | **page**, any field of page_create | restore prior | | | `page.update` | edit |
| `page_move` | `move.php` | **page**, parent (a page) or space (its root) or private (yes), after (the sibling it follows) | restore prior | | | `page.move` | full on the page and edit at the destination |
| `page_duplicate` | `duplicate.php` | **page**, title, parent (where; beside the original by default) | page_trash | | | `page.duplicate` | view on the page and edit at the destination |
| `page_trash` | `trash.php` | **page** (its subpages go with it) | page_restore | ✔ | deletion | `page.trash` | full |
| `page_restore` | `restore.php` | **page** (from the trash; to the root when its parent is still trashed) | page_trash | | | `page.restore` | full |
| `page_delete` | `purge.php` | **page** (one in the trash, for good) | — | ✔ | deletion | `page.delete` | full or trash.purge |
| `trash_purge` | `trash-purge.php` | space (empty for everything I may purge) | — | ✔ | deletion | `trash.purge` | trash.purge |
| `page_lock` | `lock.php` | **page**, locked (yes or no) | page_lock | | other | `page.lock` | full |
| `page_share_member` | `share.php` | **page**, member (a person or an agent) or department, **level** | page_unshare | | | `page.share` | full |
| `share_guest` | `share-guest.php` | **page**, **guest** (an external member holding Guest), **level** (view or comment or edit) | page_unshare | ✔ | external_send | `page.share_guest` | full and share.guest |
| `page_unshare` | `unshare.php` | **page**, member or department or guest | page_share_member | | | `page.unshare` | full |
| `page_restrict` | `restrict.php` | **page** (only the principals named on it reach it) | page_unrestrict | ✔ | | `page.restrict` | full |
| `page_unrestrict` | `unrestrict.php` | **page** (the space's default comes back) | page_restrict | | | `page.unrestrict` | full |
| `page_favorite` | `favorite.php` | **page**, favorite (yes or no; yes by default) | page_favorite | | | `page.favorite` | view |
| `page_publish` | `publish.php` | **page**, include_subpages (yes or no), noindex (yes or no; yes by default), layout (table or gallery for a database), public_properties[] | page_unpublish | ✔ | external_send | `page.publish` | publish.web |
| `page_publish_rotate` | `publish-rotate.php` | **page** (a new link; the old one stops) | — | ✔ | external_send | `page.publish_rotate` | publish.web |
| `page_unpublish` | `unpublish.php` | **page** | page_publish | ✔ | external_send | `page.unpublish` | publish.web |
| `template_apply` | `template-apply.php` | **template**, space or parent (where the page goes), title | page_trash | | | `page.template_apply` | edit at the destination |
| `template_publish` | `template-publish.php` | **page**, template (yes or no: make it a template or stop) | template_publish | | other | `page.template_publish` | owner of its space |
| `page_verify` | `verify.php` | **page** (a wiki page I own), months (1 or 3 or 6 or 12; the space's default) | — | | | `page.verify` | the wiki owner or the space owner |
| `page_owner_set` | `owner.php` | **page** (a wiki page), **member** | restore prior | | | `page.owner_set` | full |
| `space_wiki_set` | `wiki.php` | **space**, wiki (yes or no), verify_months (1 or 3 or 6 or 12) | restore prior | ✔ | other | `space.wiki_set` | owner |

## The block editor — THE FIRST EXEMPLAR (slice 3)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `page-version` | `/pages/{id}/versions/{version}` | one version of the page as it was, with a diff against the current page or another version (params: `against`) |

Actions (base `/blocks/`; versions, comments and files name their files absolutely):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `block_append` | `append.php` | **page**, **markdown** (one or many blocks), parent (a block to nest under), after (the block it follows; the end by default) | block_delete | | | `block.append` | edit |
| `block_insert` | `insert.php` | **page**, **type** (a block type), content (the type's object; empty for a blank one), parent, after, at_start (yes or no) | block_delete | | | `block.insert` | edit |
| `block_update` | `update.php` | **block**, **version** (the one the editor read), content (the type's object) or markdown (one block's text), type (to turn it into another type) | restore prior | | | `block.update` | edit |
| `block_move` | `move.php` | **block**, parent (a block or empty for the root), after (the sibling it follows; empty for first) | restore prior | | | `block.move` | edit |
| `block_delete` | `delete.php` | **block** (and its children) | — | ✔ | deletion | `block.delete` | edit |
| `version_save` | `/pages/versions/save.php` | **page** | — | | | `page.version_save` | edit |
| `version_restore` | `/pages/versions/restore.php` | **version** (the present is snapshotted first) | version_restore | ✔ | deletion | `page.version_restore` | full |
| `comment_add` | `/pages/comments/add.php` | **page**, **markdown**, block (an inline comment on a block), parent (a reply in a discussion) | comment_delete | | | `comment.add` | comment |
| `comment_edit` | `/pages/comments/edit.php` | **comment**, **markdown** | restore prior | | | `comment.edit` | own |
| `comment_resolve` | `/pages/comments/resolve.php` | **comment** (a discussion's first), resolved (yes or no; yes by default) | comment_resolve | | | `comment.resolve` | comment |
| `comment_delete` | `/pages/comments/delete.php` | **comment** | — | ✔ | deletion | `comment.delete` | own or full |
| `file_upload` | `/files/upload.php` | **file** (multipart), block or page (its cover or icon) or message or comment or row (which record it belongs to), property (a row's files property) | attachment_delete | | | `attachment.add` | edit on the page or channel on the message |
| `attachment_delete` | `/files/delete.php` | **attachment** | — | ✔ | deletion | `attachment.delete` | edit on the page or own message |

## Channels and messages — THE SECOND EXEMPLAR (slice 4)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `channel-browse` | `/channels/` | the channels of my spaces: the ones I follow and the ones I can join, by space; archived apart (params: `space`, `q`, `archived`) |
| `channel-add` | `/channels/new` | to make a channel in a space: name, kind, topic, purpose (params: `space`) |
| `channel-view` | `/channels/{id}` | the channel: messages newest at the bottom, day dividers, the unread line, threads collapsed, reactions, pins, the composer; polling while open (params: `message` to jump to one) |
| `channel-edit` | `/channels/{id}/edit` | to change a channel's name, topic, purpose, kind (public or private), retention |
| `channel-members` | `/channels/{id}/members` | who is in a private channel or follows a public one; add a member or a guest; remove |
| `channel-pins` | `/channels/{id}/pins` | what is pinned and bookmarked in the channel |
| `thread-view` | `/channels/{id}/threads/{message}` | a thread: the first message and every reply, in order; reply; also send to channel |
| `dm-list` | `/dm/` | my direct and group messages with their last lines and unread counts |
| `dm-new` | `/dm/new` | to start a direct or group message: a people picker that finds or makes the conversation |
| `dm-view` | `/dm/{id}` | a direct or group message, read and answered like a channel; a DM to an agent shows "agent" and its thinking state |
| `scheduled-list` | `/channels/scheduled` | my scheduled messages, to change or cancel |
| `reminder-list` | `/reminders/` | my reminders, due and done |

Actions (base `/channels/`; direct messages and reminders name their files absolutely):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `channel_create` | `save.php` | **space**, **name** (lowercase, digits, dashes), kind (public or private; public by default), topic, purpose | channel_archive | | | `channel.create` | member of the space with channels.create |
| `channel_update` | `save.php` | **channel**, any field of channel_create | restore prior | | | `channel.update` | channel for topic and purpose; owner or channel.manage for the rest |
| `channel_archive` | `archive.php` | **channel** (readable, never writable afterwards) | channel_unarchive | ✔ | deletion | `channel.archive` | owner or channel.manage |
| `channel_unarchive` | `unarchive.php` | **channel** | channel_archive | | | `channel.unarchive` | owner or channel.manage |
| `channel_delete` | `delete.php` | **channel** (an archived one, for good) | — | ✔ | deletion | `channel.delete` | admin |
| `channel_join` | `join.php` | **channel** (follow a public one; join a private one I was invited to) | channel_leave | | | `channel.join` | member of the space |
| `channel_leave` | `leave.php` | **channel** (not a space's default) | channel_join | | | `channel.leave` | own |
| `channel_member_add` | `members/add.php` | **channel**, **member** (a person or an agent; a private channel's member, a public one's follower) | channel_member_remove | | | `channel.member_add` | channel |
| `channel_guest_add` | `members/add-guest.php` | **channel**, **guest** (an external member holding Guest) | channel_member_remove | ✔ | external_send | `channel.guest_add` | owner and share.guest |
| `channel_member_remove` | `members/remove.php` | **channel**, **member** | channel_member_add | ✔ | | `channel.member_remove` | owner or channel.manage |
| `channel_notify_set` | `notify.php` | **channel**, notify (all or mentions or none), muted_until (a time; empty unmutes), starred (yes or no), section (my sidebar section) | restore prior | | | `channel.notify_set` | own |
| `retention_set` | `retention.php` | **channel**, **days** (1 to 3650; empty keeps forever) | restore prior | ✔ | other | `channel.retention_set` | retention.manage or channel.manage |
| `channel_pin` | `pins/add.php` | **channel**, message or page | channel_unpin | | | `channel.pin` | channel |
| `channel_unpin` | `pins/remove.php` | **channel**, message or page | channel_pin | | | `channel.unpin` | channel |
| `bookmark_save` | `bookmarks/save.php` | **channel**, **title**, url or page, emoji, bookmark (to change one) | bookmark_delete | | | `channel.bookmark_save` | channel |
| `bookmark_delete` | `bookmarks/delete.php` | **bookmark** | — | | | `channel.bookmark_delete` | channel |
| `message_post` | `messages/post.php` | **channel**, **markdown** (mentions as @Name; never @channel — see channel_announce), schedule_for (a time; sends later), attachments[] (attachment ids uploaded first) | message_delete_own | | | `message.post` | channel |
| `thread_reply` | `messages/reply.php` | **message** (the thread's first, or any in it), **markdown**, also_to_channel (yes or no) | message_delete_own | | | `thread.reply` | channel |
| `channel_announce` | `messages/announce.php` | **channel**, **markdown**, reach (channel or here or everyone; channel by default) | message_delete_own | ✔ | other | `message.announce` | channel |
| `message_edit` | `messages/edit.php` | **message**, **markdown** | restore prior | | | `message.edit` | own |
| `message_delete_own` | `messages/delete-own.php` | **message** (my own; a tombstone stays) | — | ✔ | | `message.delete_own` | own |
| `message_delete` | `messages/delete.php` | **message** (anyone's; a tombstone stays) | — | ✔ | deletion | `message.delete` | owner or channel.manage |
| `message_schedule` | `messages/schedule.php` | **message** (one of mine not yet sent), **schedule_for** (a time; empty sends now) | message_unschedule | | | `message.schedule` | own |
| `message_unschedule` | `messages/unschedule.php` | **message** (a scheduled one of mine — it is discarded) | — | ✔ | | `message.unschedule` | own |
| `reaction_add` | `messages/react.php` | **message**, **emoji** (a Unicode emoji or :shortcode:) | reaction_remove | | | `message.react` | channel |
| `reaction_remove` | `messages/unreact.php` | **message**, **emoji** | reaction_add | | | `message.unreact` | own |
| `message_save` | `messages/save.php` | **message**, saved (yes or no; yes by default) | message_save | | | `message.save` | channel |
| `channel_mark_read` | `read.php` | **channel**, message (up to this one; the latest by default) | — | | | `channel.read` | own |
| `dm_open` | `/dm/open.php` | **member** (a person or an agent) | — | | | `channel.create` | dm.write |
| `group_dm_open` | `/dm/open-group.php` | **members[]** (two to eight others) | — | | | `channel.create` | dm.write |
| `reminder_set` | `/reminders/save.php` | **remind_at** (a time), message or page or text (what to be reminded of) | reminder_done | | | `reminder.set` | own |
| `reminder_done` | `/reminders/done.php` | **reminder** | — | | | `reminder.done` | own |

## Databases and views (slice 5)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `database-list` | `/databases/` | the databases in my spaces as cards with their row counts (params: `space`, `q`) |
| `database-view` | `/databases/{id}` | a database through its views: the tabs; the table with inline editing, add a row, hide or show properties, filters, sorts, groups; the board with drag between columns; the gallery; the list; the calendar; the timeline; a row opens as a page (params: `view`) |
| `database-schema` | `/databases/{id}/schema` | the properties: add, rename, change type where lossless, options, a relation's target and whether two-way, a rollup's source and function, a unique id's prefix; remove one |
| `view-add` | `/databases/{id}/views/new` | to add a view: name, layout, the properties it needs |
| `view-edit` | `/databases/{id}/views/{view}/edit` | to change a view's name, layout, filter, sort, group, visible properties, card settings |
| `row-view` | `/databases/{id}/rows/{row}` | one row as a page: its properties in a panel, its body below (the editor, slice 3) |

Actions (base `/databases/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `database_create` | `save.php` | **title**, space or parent (a space's root or a parent page), inline (yes or no: shown inside the parent page), properties (the schema as JSON; a Name title by default), template (a database template to copy) | page_trash | | | `database.create` | edit on the parent or member of the space |
| `database_update` | `save.php` | **database**, any field of database_create | restore prior | | | `database.update` | edit |
| `database_schema_save` | `schema.php` | **database**, **properties** (the whole schema as JSON) | restore prior | ✔ | other | `database.schema_save` | edit |
| `database_property_save` | `properties/save.php` | **database**, **key** (the property's key or name), **type**, options[] (select, multi-select, status), relation_database (a relation's target), two_way (yes or no), rollup_relation, rollup_property, rollup_function, prefix (a unique id), number_format | restore prior | | | `database.property_save` | edit |
| `database_property_remove` | `properties/remove.php` | **database**, **key**, purge_values (yes or no; no keeps the rows' values) | — | ✔ | other | `database.property_remove` | edit |
| `database_delete` | `delete.php` | **database** (to the trash, rows and views with it) | page_restore | ✔ | deletion | `database.delete` | full |
| `row_create` | `rows/save.php` | **database**, title, properties (the values as JSON, keyed by property name), template (a row template), markdown (the body) | row_delete | | | `row.create` | edit_content |
| `row_update` | `rows/save.php` | **row**, any field of row_create | restore prior | | | `row.update` | edit_content |
| `row_delete` | `rows/delete.php` | **row** (to the trash) | page_restore | ✔ | deletion | `row.delete` | edit_content |
| `row_relation_set` | `rows/relation.php` | **row**, **property** (a relation), targets[] (the related rows; the whole list) | restore prior | | | `row.relation_set` | edit_content |
| `view_save` | `views/save.php` | **database**, **name**, layout (table or board or gallery or list or calendar or timeline), filter (Notion's filter object as JSON), sort (as JSON), group_by, sub_group_by, visible_properties[], calendar_by, timeline_start, timeline_end, card_size, card_cover, wrap (yes or no), linked_from (a page this view is embedded on), view (to change one) | view_delete | | | `view.save` | edit |
| `view_delete` | `views/delete.php` | **view** (not a database's last) | — | ✔ | | `view.delete` | edit |
| `view_reorder` | `views/reorder.php` | **view**, after (the view it follows; empty for first) | restore prior | | | `view.reorder` | edit |

## Notifications, search and the wiki's reports (slice 6)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `search` | `/search` | to find something across pages, rows, messages and comments, with the modifiers as chips; results grouped, the match in context (params: `q`, `in`, `from`, `has`, `before`, `after`, `is`, `space`) |
| `wiki-report` | `/spaces/{id}/wiki/report` | the wiki's reports for a space: expired, unverified, stale, orphans, broken links, duplicates; nudge an owner |

Actions (base `/spaces/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `wiki_nudge_send` | `wiki/nudge.php` | **page** (a wiki page needing a hand), member (its owner by default) | — | ✔ | | `page.nudge` | owner |

## Agents in Spaces (slice 7)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `agent-list` | `/admin/agents` | the agents that are members here, their spaces and channels, their last reply, their pending and failed dispatches — read-only; hiring and grants link to the kernel |
| `dispatch-list` | `/admin/dispatches` | every dispatch: pending, running, answered, failed; retry a failed one (params: `status`, `agent`) |
| `proposal-list` | `/proposals/` | the Librarian's proposals: accept or dismiss each (params: `status`, `kind`) |
| `connection-list` | `/admin/connections` | which sibling applications read what of ours (the two shares) and when — the kernel's facts, read-only |

Actions (base `/proposals/`; the dispatch retry names its file absolutely):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `proposal_make` | `save.php` | **kind** (thread_to_page or verify or orphan or duplicate or broken_link or unanswered or stale), **title**, **reason**, message (the thread's first message) or page (the subject), proposed_page (the draft made) | proposal_dismiss | | | `librarian.propose` | member |
| `proposal_accept` | `accept.php` | **proposal** (a thread_to_page one moves its draft where I say: parent) | — | ✔ | | `librarian.accept` | member with edit at the destination |
| `proposal_dismiss` | `dismiss.php` | **proposal**, reason | — | | | `librarian.dismiss` | member |
| `dispatch_retry` | `/admin/dispatches/retry.php` | **dispatch** (a failed one) | — | | | `agent.dispatch` | agents.settings |

## The worker, imports, exports and retention (slice 8)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `import` | `/import` | to import: a Markdown file or zip, a Notion export zip, a CSV into a database; a preview; the log of past imports (params: `space`, `parent`, `database`) |
| `export-list` | `/exports/` | my exports (the admin's: everyone's): make one, download one before it expires |

Actions (base `/exports/`; the import names its file absolutely):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `import_start` | `/import/start.php` | **file** (multipart: .md, .zip or .csv), kind (markdown or markdown_zip or notion_zip or csv; from the file by default), space or parent (where pages land), database (a CSV's target) | — | ✔ | | `page.import` | edit at the destination |
| `export_page` | `page.php` | **page**, format (md or html; md by default), include_subpages (yes or no) | — | | | `page.export` | view and export.own |
| `export_database` | `database.php` | **database**, format (csv or json), view (a view's filter and columns) | — | | | `page.export` | view and export.own |
| `export_space` | `space.php` | **space**, format (zip of md or zip of html or json) | — | ✔ | external_send | `space.export` | owner and export.space |
| `export_channel` | `channel.php` | **channel**, format (json or md or csv), from, to | — | ✔ | external_send | `channel.export` | owner and export.space |
| `export_all` | `all.php` | format (zip) | — | ✔ | external_send | `export.all` | export.all |
| `export_delete` | `delete.php` | **export** | — | | | `export.delete` | own |

## Admin and settings (slice 9)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `admin-settings` | `/admin/settings` | the workspace's settings: business name, default space kind and levels, snapshot interval, version and trash retention, stale days, unanswered hours, verification default, the attachment limit, embed hosts, public pages noindex, digest hour, week start, time zone, group DM size, away minutes, the admin channel |
| `admin-spaces` | `/admin/spaces` | every space, the private ones marked (opening one is logged `space.admin_view`); archive, restore, delete |
| `admin-published` | `/admin/published` | every published page, its views and last opened; rotate or unpublish |
| `admin-retention` | `/admin/retention` | every channel's retention and the version and trash retentions |
| `admin-trash` | `/admin/trash` | everyone's trash: restore, purge, purge all |

Actions (base `/admin/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `settings_save` | `settings.php` | any field of the settings (business_name, default_space_kind, default_member_level, default_everyone_level, version_snapshot_minutes, version_retention_days, trash_retention_days, stale_page_days, unanswered_hours, wiki_default_verify_months, max_attachment_bytes, allowed_embed_hosts[], public_pages_noindex, public_base_url, digest_hour, week_start_dow, timezone, group_dm_max_members, away_minutes, admin_channel_name, agents_join_default_space) | restore prior | | other | `settings.save` | admin |
| `emoji_save` | `emoji.php` | **shortcode**, **emoji**, keywords[] | emoji_delete | | | `emoji.save` | admin |
| `emoji_delete` | `emoji-delete.php` | **shortcode** | — | | | `emoji.delete` | admin |

## The public door (no session, no action)

| Screen id | URL | When the visitor wants… |
| --- | --- | --- |
| `public-page` | `/p/{token}` | a published page, read-only, with the business's name; its subpages when the publisher said so; a database as a table or gallery; images through the same door (`/p/{token}/files/{id}`; a subpage `/p/{token}/{page}`). Opening it is logged `page.public_view` with source `portal`, rate-limited; the token is never logged |
