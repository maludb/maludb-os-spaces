# Spaces — design (Phase 0 of the new-app workflow, fitted to the Business OS)

2026-10-05 · the business's **collaborative workspace**: Notion and Slack in one application — spaces that hold pages built
from blocks, a wiki, databases with views, and channels with threads — for the business's people **and the OS's agents,
as members of the same spaces**. Its own repository `/srv/apps/spaces` (origin `github.com/maludb/maludb-os-spaces`), served
as `spaces.<domain>` (`spaces.subello.com` here), built with `htmx-php-builder` (how it is built) and fitted to
`maludb-os-integration` 0.6.0 (how it fits), exactly as HR, Projects, Help Desk, General Ledger and Consultant Tracking were.
**`/srv/apps/consultant_tracking/docs/consultant-tracking-design.md` is the nearest plan** (the same shape, the same owner's
rules); `/srv/apps/helpdesk` and `/srv/apps/consultant_tracking` are the nearest **built** exemplars (their kit, specs and
proof suites are what this repository copies). The kernel's retired `documents.md` (pages with versions), `content.md`,
`inbox.md` and the living `assistants-and-messaging.md` (`docs/build-specs/` in `maludb-os-core`) are the prior art this
design keeps or overturns by name.

> **What this document is.** The plan for the application, in the kernel's words, for the owner's go, and the brief a
> worker model builds from. **Checkpoint.** This document and the owner's answers (§13, recorded in §15) are Phase 0's
> first half; the schema in `db/`, `maludb-os.json`, `os/`, `skills/` and `deploy/` complete it. Phase 1 adds
> `docs/spaces-mcp-tool-surface.md`, `docs/spaces-action-manifest.md` and the slice specs in `docs/build-specs/`. **No
> feature PHP is written until the owner approves them together.** The decisions this document needed from the owner are §13, answered in §15 (2026-10-05).

## 0. The research — Notion and Slack, feature by feature, and what this design does with each

Researched 2026-10-05 from the products' own documentation (Notion's API reference and help centre; Slack's API reference,
release notes and help centre) and the year's reviews. The two products are summarised as **what the clone must have**, and
each feature is marked **kept** (version 1), **changed** (kept in a different form, because of the OS), **Extended** (a later
spec), or **dropped** (by decision — the reason given).

### 0.1 Notion — "an all-in-one workspace: documents, wiki, databases, projects, knowledge"

| Feature | What Notion does | Here |
|---|---|---|
| **Workspace → teamspaces → pages** | One workspace per company; teamspaces **open** (anyone joins), **closed** (visible, invite to join), **private** (invisible); **default teamspaces** hold everyone; teamspace **owners** (settings, every page) and **members**; sidebar sections Favorites · Teamspaces · Shared · Private | **Kept**: one installation = one workspace; a **space** = a teamspace with the same three kinds; a **General** space is the default and every member is in it; a space per standing department seeded; owners and members (§3); the same sidebar (§9) |
| **Members and guests** | Members (seats) vs guests (outsiders invited to specific pages); permission groups | **Changed**: a member is a kernel member mirrored here; a guest is a kernel member flagged external holding the **Guest** role, reaching only what is shared with them (§4); groups are the kernel's departments |
| **Page permissions** | Full access · Can edit · Can edit content (databases) · Can comment · Can view; inherited down the tree; restricted pages; share to anyone with the link; publish to the web; **Mar 2026: "can create pages" on a database** | **Kept**, all six levels and the inheritance (§3); the public page is a token door `/p/<token>` (§4); page-level access on databases = the row is a page with its own permission |
| **Pages are blocks; blocks nest** | The API's 32 live block types (0.3); fourteen of them hold children; a page is a `child_page` block; icon, cover, properties | **Kept** verbatim — the block types, the nesting, the page as a block (§6) — so an export to Notion's format and an import from it are a transcription |
| **Rich text** | Runs of `text` / `mention` / `equation` with annotations bold, italic, strikethrough, underline, code, color; links | **Kept** as the one rich-text format for pages, messages, comments and properties (§6) |
| **Databases** | Inline or full page; one title; property types (0.3); views table · board · gallery · list · calendar · timeline · **chart**; filters, sorts, groups, sub-groups; templates; linked (filtered) views; "data sources" (one database view over several sources, 2025) | **Kept**: every property type but formula and button (Extended) and place; six views; filters, sorts, groups, sub-groups; templates; linked views. **Chart** Extended. **Data sources dropped** — one database, one schema (a linked view answers the multi-view need) |
| **Formulas, relations, rollups** | Expression language; relations two-way; rollups aggregate through a relation | **Relations and rollups kept** (count, sum, min, max, earliest, latest, percent checked, show original); **formulas Extended** (a safe expression language is its own spec) |
| **Wiki** | A teamspace or page turned into a wiki: owners, **verification** (verified by an owner, expires in 1/3/6/12 months or never), tags, a home view of every page | **Kept** (§6, `pages.wiki_*`): a space may be a wiki; every page then has an owner and a verification state; the Librarian agent watches it (§5) |
| **Comments and discussions** | Page comments; inline (block) comments that open a discussion; resolve; mentions | **Kept** (§6) |
| **History** | Page history (snapshots), restore; 7/30/90 days by plan | **Kept**: versions never rewritten, restore = a new version; retention a setting (§6) |
| **Search, favorites, recents, trash, templates, locked pages** | Workspace search with filters; favorites; recently visited; trash with restore (30 days); page and database templates; lock a page | **Kept** (§6, §9) |
| **Import / export** | Markdown, HTML, CSV, Evernote, Confluence…; export Markdown/CSV/HTML/PDF per page or workspace | **Kept**: Markdown and CSV in; Markdown, HTML and CSV out, a space as a ZIP; **Notion's own export (Markdown & CSV zip) imports** since the block model is theirs; PDF Extended |
| **Notion AI** | Q&A over the workspace, writing help, autofill properties, meeting notes | **Changed**: the shipped **expert** answers from pages and channels, drafts and summarises — through the kernel's chat endpoint, never a model call from here (§5); autofill Extended |
| **Home, Inbox** | A home with recents and tasks; an inbox of notifications | **Kept** as the home and the bell (§9) |
| Calendar, Mail, Sites, Forms | Separate products | **Dropped**: calendar and mail are other applications' (the OS has MaluMail); a published page is a site enough; forms Extended |
| Integrations (Slack, GitHub, Jira), the public API | Link previews, sync | **Changed**: the two MCP servers are the API; previews of a sibling's records through K7 (Extended, §11) |
| **What people miss** | Speed on big pages and databases (5,000 rows and the page takes seconds); offline; granular permissions behind the top plans; mobile editing; no native charts | **Done better where the stack allows**: paging and lazy children on every list; permissions in every plan (there is one); phone-first reading; honest about the rest — no offline editing, no charts in v1 |

### 0.2 Slack — "channels, messages, people, and lately agents"

| Feature | What Slack does | Here |
|---|---|---|
| **Workspace, members, guests, roles, user groups** | Owners/admins/members; single- and multi-channel guests; `@handle` user groups; profiles, statuses, presence, DND | **Changed**: roles are the application's three (§3); guests as above; user groups are the kernel's **departments** (`@hr`); status and presence kept simply (a status line; "active in the last 5 minutes") |
| **Conversations** | Public and private channels, DMs, group DMs; topic, purpose, pins, bookmarks; archive; sidebar sections; default channels; channel managers; mute; star | **Kept** (§6): channels live **in a space** (a space's default `#general`); DMs and group DMs live outside spaces; everything else as named |
| **Messages** | `ts`/`thread_ts`; **threads** with "also send to channel"; reactions (custom emoji); edit, delete; pins; saved ("Later"); **scheduled messages**; reminders; mrkdwn; mentions `@user` `@channel` `@here` `@everyone`; link unfurls; code snippets; files; quotes | **Kept**, in the one rich-text format (§6): threads, also-send-to-channel, reactions (Unicode; custom emoji Extended), edit/delete with the "edited" mark, pins, saved, scheduled, reminders, mentions of members, agents, departments, `@channel`, `@here`; internal links unfurl as page/message cards; files as attachments |
| **Canvases, Lists** | A document in a channel; a task list with fields and views; Slackbot and MCP agents read and write both (2026) | **Changed**: a canvas is a **page** (a channel may pin pages); a list is a **database** — one model, not two |
| **Huddles, clips** | Audio/video with AI notes; audio/video clips | **Dropped** (no media server; a voice note is a file attachment) |
| **Workflow Builder** | Triggers and steps, forms, the AI answer step, repeat over a list | **Extended**: a rule engine over channel and page events (Help Desk's rules are the pattern) |
| **Search** | `in:` `from:` `has:` `before:` `after:` `is:` modifiers; messages, files, canvases; AI answers | **Kept**: one search across pages, databases and messages with the same modifiers (§7); the AI answer is the expert's |
| **Activity, Later, Catch up, Today** | A feed of mentions and reactions; saved items; a catch-up of unread | **Kept** as Activity (mentions, reactions, replies to me), Saved, and the unread catch-up (§9) |
| **Slack AI and agents** | Channel and thread summaries, recap, search answers; **Agentforce** agents and Slackbot acting on data; coding agents tagged into "code channels"; the assistant thread model (`assistant.threads.*`) | **Changed — this is the OS's whole point**: every hired agent is a member; @mention it, DM it, add it to a channel; it answers in the thread through the kernel's chat endpoint under the asker's identity; summaries are what the expert does when asked (§5) |
| **Slack Connect** | Shared channels across organisations | **Dropped**: one installation, one business (an external joins as a guest) |
| **Platform** | `conversations.*`, `chat.*`, `users.*`, `files.*`, `reactions.*`, `pins.*`, `search.*`, `bookmarks.*`, `canvases.*`, `usergroups.*`, `reminders.*`, `slackLists.*`, `assistant.*`; Events API, webhooks, slash commands, Block Kit | **Changed**: the records MCP mirrors the read families, the actions MCP the write ones (§7); no webhooks out (K7 reads are the OS's way); Block Kit is not needed — a message is rich text and a page is blocks |
| **Retention, export, audit** | Retention per workspace or channel; exports; audit logs; eDiscovery | **Kept** in the OS's form: retention per channel (never by default), channel and space exports, the activity log as the audit |
| **What people miss** | Noise; decisions buried in threads; search that fails in busy channels; 90-day free history; cost per seat | **Done better**: no seat price and no history limit (one installation); "also send to channel" plus the Librarian's "this thread should be a page"; one search over chat and wiki; notification preferences per channel and per person |

### 0.3 The exact vocabularies this design adopts (from the Notion API, so exports and imports transcribe)

**Block types** (every one stored; rendering and editing of each in §6): `paragraph`, `heading_1`, `heading_2`, `heading_3`,
`bulleted_list_item`, `numbered_list_item`, `to_do`, `toggle`, `quote`, `callout`, `code`, `divider`, `image`, `video`, `audio`,
`file`, `pdf`, `bookmark`, `embed`, `equation`, `table_of_contents`, `breadcrumb`, `column_list`, `column`, `synced_block`,
`template`, `link_to_page`, `child_page`, `child_database`, `table`, `table_row`, `link_preview`. Not adopted: `heading_4` (new in
2026, no renderer worth it yet — stored as `heading_3` on import), `tab`, `meeting_notes`, `transcription` (`unsupported` on import,
kept verbatim so a re-export loses nothing). Children are allowed on: list items, `to_do`, `toggle`, `quote`, `callout`,
`paragraph`, toggleable headings, `column`, `synced_block`, `template`, `table` (rows only), `child_page`, `child_database`.

**Property types**: `title`, `rich_text`, `number`, `select`, `multi_select`, `status`, `date`, `people`, `files`, `checkbox`,
`url`, `email`, `phone_number`, `relation`, `rollup`, `created_time`, `created_by`, `last_edited_time`, `last_edited_by`,
`unique_id`, `verification` (wiki pages only). Extended: `formula`, `button`, `place`.

**View layouts**: `table`, `board`, `gallery`, `list`, `calendar`, `timeline`. Extended: `chart`.

**Rich text** (one JSON array of runs on every text field): `{type: text|mention|equation, text:{content, link}, mention:{type:
member|agent|department|page|database|date|channel|message, id…}, equation:{expression}, annotations:{bold, italic, strikethrough,
underline, code, color}, plain_text, href}`. Colors: Notion's nine plus their `_background` forms.

## 1. What Spaces is, and is not

Spaces **owns the business's written collaboration**: where a team keeps what it knows (pages and the wiki), what it tracks
loosely (databases — a vendor list, a decision log, a reading list, a content calendar), and what it says to each other
(channels, threads and direct messages) — and it is the one place the OS's agents and its people are **in the same room**.
It is the Notion and the Slack a small business would otherwise pay for per seat, with the differences that matter here:

- **Agents are members, not integrations.** A hired agent appears in the people picker, is @mentioned, DM'd, added to a
  channel, given a page to edit; it answers in the thread as itself, as a run of the kernel's chat endpoint under the asker's
  identity, every model call ledgered by the kernel. A person's assistant (the kernel's tree) is reached here by a DM as it
  is on Telegram and SMS. Nothing of a model lives here (§5).
- **One model of text, two surfaces.** A page is a tree of blocks; a message is a short block; both hold the same rich text;
  a database row is a page with typed properties. A Slack canvas is a page here, a Slack list a database — nothing is kept
  twice. **Markdown is the agents' language**: every read tool answers Markdown, every write tool takes it (§6).
- **Permissions are the tree's, resolved once.** A page's permission is its nearest ancestor's with an explicit one — the
  space's by default; six levels, like Notion's; private pages; guests reach exactly what is shared; every read function
  resolves the visible set **once per statement** (the kernel's db/160 lesson), never per row (§3).
- **The wiki stays honest by a duty, not by hope.** The shipped Librarian lists the unverified, the stale, the orphaned and
  the unanswered every week and proposes a page for a thread that decided something; a person verifies (§5).
- **Full MCP coverage is a requirement**, not a feature: every question a screen answers is a records tool, every button an
  action tool (`maludb-os-integration`, `mcp-and-api.md`) — and here it matters doubly, because agents *live* in this
  application (§7).
- **Nothing of identity lives here.** The kernel signs people in; the application mirrors the directory. A guest is a kernel
  member flagged external; a page for someone with no account at all is a published page behind a token (§4).

Not in version 1: real-time co-editing of one block by two people (a stale save is refused and reloaded; the page shows who
else is here — a CRDT editor is Extended); formulas; charts; custom emoji; audio and video (huddles, clips); workflows and
rules; Slack Connect; forms; a PDF export; offline editing; sibling record previews (a Projects task, a Help Desk ticket
embedded live — a K7 read the siblings owe, §11). Each is an Extended item (§11).

## 2. Who uses it (the actors, in the OS's words)

**A word about words.** "Agent" always means an OS AI agent. A **member** is a kernel member mirrored here; a **guest** is a
member flagged external, holding the Guest role; a **space** is a place (Notion's teamspace); a **page** is a tree of
**blocks**; a **database** is a page whose children are **rows** (pages with typed **properties**) shown through **views**; a
**channel** is a conversation in a space; a **DM** a conversation outside any space; a **thread** hangs off one message.

| Actor | Who | How they reach the application |
|---|---|---|
| **Member** | Everyone inside the business: reads and writes in the spaces they belong to, keeps private pages, talks in channels and DMs, follows, saves, searches | The launcher → `spaces.<domain>`; the phone (375 px first for reading and chat); the command bar |
| **Space owner** | Owns a space: its settings and kind, who is in it, its channels, its wiki settings, its templates, the root pages' permissions | The space's settings |
| **Guest** (external) | A kernel member with `is_external` holding Guest: sees nothing by default; reaches the pages and channels explicitly shared with them; writes where given edit (db/159 opens the launcher to them) | The launcher, like an employee, to a near-empty sidebar |
| **Spaces admin** | Workspace settings, every space, retention, exports, templates, the public pages, the agents' settings. A super-admin holds it (the kernel gives them the admin role) | The admin area |
| **An agent** | Any hired agent granted Member (the shipped **expert** and **Librarian** on install, any other by a super-admin): a member in every sense — reads what its member id may see, posts, edits pages where given edit, is mentioned and DM'd | Records MCP (run token) · Actions MCP (relayed) · a chat turn on mention or DM · a duty (§5) |
| **The public** | Whoever holds a published page's link | `/p/<token>` — the page and its subpages, read-only (§4) |
| **The kernel** | Signs people in, delivers the directory, hires and runs the agents, pauses their actions for approval, answers the command bar and the mentions (the chat endpoint), carries texts (K6) and the siblings' reads (K7) | — |
| **System** | Scheduled messages, reminders, the notification outbox and digests, retention, the search index, page snapshots, the Librarian's wake — the one timer worker | `source = 'cron'`, every row logged |

Tenancy: the business running the application is the tenant — one installation, one database, one memory with every other
application's episodes. One installation is one workspace; multi-workspace (Enterprise Grid) is out of scope.

## 3. Who sees what — the rules, in one place

**Roles** (published by `app_roles`, `os.app-roles/1`; exactly one `is_admin`; ordered as shown). The base role's key is
`user` on purpose: the installer's `--grant-standing-departments` gives standing departments the role keyed `member`,
`user` or `write`, and **everyone in the business belongs in Spaces** (§13.3).

| Role key | Name | Capability | Rights |
|---|---|---|---|
| `guest` | Guest | read | `spaces.guest` — nothing by default; reaches only pages and channels shared to them explicitly (and may write where the share says edit or comment); may not create a space, a private page or a DM to anyone but a member who shared with them; never sees the directory beyond the people in what is shared |
| `user` | Member | write | `spaces.join` (open spaces), `pages.write` (where the tree allows), `pages.private` (own private pages), `databases.write`, `channels.write` (where a member), `channels.create` (in spaces they are in; private ones too), `dm.write`, `share.member` (share a page they fully control with a member or department), `files.write`, `export.own` (a page they can see, as Markdown) |
| `space_owner` | Space owner | write | Member's + on their spaces: `space.manage` (settings, kind, members, channels, templates, wiki, the root permission), `share.guest` (share to a guest — pauses for agents), `channel.manage` (archive, retention of their channels, pinned pages), `export.space` |
| `admin` | Spaces admin (is_admin) | admin | every right everywhere + `settings.manage`, `publish.web` (a page to the public — pauses for agents), `retention.manage`, `trash.purge`, `export.all`, `agents.settings`; sees every space including private ones (**as Notion's workspace owner does; logged `space.admin_view`**) |

The `space_owner` role is **application-wide capability**; who owns *which* space is `space_members.role = 'owner'`. A
Member who creates a space becomes its owner without holding the role — the role exists for the super-admin to name people
who may own spaces they did not create and to make the Guest/Member/owner ladder visible in the kernel's claims.

**The permission tree** (`sp_can_see_page(id)`, `sp_can_edit_page(id)`, `sp_page_level(id)` → `none|view|comment|
edit_content|edit|full`; `sp_visible_page_ids()` as ONE PL/pgSQL function returning the caller's set, tested once per
statement in every view):

- **A space grants a base level to its members** (`spaces.member_level`, default `edit`; owners `full`) and, when **open**,
  to every member of the business (`spaces.everyone_level`, default `view`; `none` for closed and private).
- **A page inherits the nearest explicit permission above it**: `page_permissions (page_id, principal_kind member|department|
  agent|guest|everyone_in_space|everyone, principal_id, level)`; a page with no row of its own uses its parent's resolution;
  a trigger maintains `pages.permission_root_id` (the nearest ancestor with rows, or the space) so the resolver walks nothing
  at read time. **Restricting** a page (removing the inherited "everyone in the space") writes an explicit set.
- **A private page** (`pages.space_id IS NULL`, `owner_member_id` set) is its owner's alone until shared; a shared private page
  shows under "Shared" for the recipient. A guest never owns a space page.
- **A database row is a page**: it inherits the database's resolution unless given rows of its own (Notion's page-level
  access); `edit_content` on a database = edit rows and their blocks, never the schema or the views.
- **Blocks have no permission of their own**; a synced block is readable where its original is readable (the copy is a
  pointer; a reader who may not see the original sees "a synced block you cannot see").
- **Channels**: a public channel is readable and writable by every member of its space (`channel_members` is the list of who
  *follows* it and gets notified — joining is free); a **private** channel by its members only; a DM by its two; a group DM
  by its members. A guest is in a channel only by explicit membership. Archived channels are readable, never writable.
- **Comments** follow their page or block; a `comment` level writes comments and nothing else.
- **An agent sees what its member id may see** — the same functions, no special case; the run-facts gate decides which tools
  it is offered; `is_eval` refuses every write.
- **Scope: none.** One business, one workspace. Departments are mirrored as **principals** (a share to a department reaches
  its members; `@hr` mentions it) and each standing department seeds a space; never a wall.

## 4. Guests and the public page — the two doors for people who are not inside

The kernel's rules: *application users are not OS users*; *never a password, a login form or an account of the
application's own*; for a page someone with no account must reach, **a token is the authority**.

**A guest is a kernel member flagged external** (the accountant, a contractor, a client's project lead), granted the Guest
role by the super-admin; the kernel's launcher opens the application to them (db/159); here they see a sidebar holding only
"Shared with me" and "Direct messages". A space owner shares a page or adds them to a channel (`share.guest` — pauses when an
agent does it); the share names a level; a guest with `edit` on a page edits it. A guest's mentions and directory reach only
the members present in what is shared with them (`sp_visible_member_ids()`).

**A published page** (`publish.web`, the admin's; an agent's attempt pauses under `external_send`): `/p/<48 hex>` renders the
page and, when chosen, its subpages, read-only, with the business's name, no comments, no properties the page did not mark
public, images served through the same door, `noindex` unless the admin says otherwise; the token is hashed, rotatable
(`page_publish_rotate`) and revocable; opening it is logged `page.public_view` with source `portal`, rate-limited, never with
a session. A database may be published as a read-only table or gallery. That is Notion Sites enough for v1.

**Rejected:** inviting outsiders by email into the application (the directory is the kernel's; an outsider who needs to
*write* becomes a kernel external); a magic-link comment door on a published page (Extended, Help Desk's secure-link pattern);
Slack Connect (one business).

## 5. Agents and the application — the OS requirement, in detail

**Reading and talking are free; publishing, sharing out, shouting, deleting and exporting are a person's.** For every agent:

| Action | Category | Why |
|---|---|---|
| `page_publish`, `page_publish_rotate`, `page_unpublish`, `share_guest` (a page or a channel to a guest), `export_space`, `export_channel`, `export_all` | `external_send` | Content leaves the business, or reaches someone outside it |
| `message_post` with `@channel`, `@here` or `@everyone`, `channel_announce` | `other` | Interrupts everyone; **policy default: pause for agents** |
| `page_delete`, `block_delete` (a block with children, or any block not the agent's own), `database_delete`, `channel_archive`, `channel_delete`, `message_delete` (not its own), `comment_delete` (not its own), `trash_purge`, `version_restore` | `deletion` | |
| `retention_set`, `space_delete`, `space_kind_set` (open ↔ closed ↔ private), `settings_save`, `template_publish` | `other` | Configuration and reach |
| `page_create`, `page_update` (title, icon, cover, properties), `block_append`, `block_update` (own or where edit), `block_move`, `page_move`, `database_create`, `database_schema_save`, `row_create`, `row_update`, `view_save`, `message_post`, `message_edit` (own), `thread_reply`, `reaction_add`, `message_pin`, `message_schedule`, `reminder_set`, `comment_add`, `comment_resolve`, `page_favorite`, `page_verify` (wiki — only when the agent is the page's owner), `channel_create` (in a space it is in), `channel_join`, `channel_topic_set`, `dm_open`, `space_create` (a closed one), `space_member_add` (a member, to a space it owns), `page_share_member` (a page it fully controls, to a member or department), `template_apply`, `import_markdown` | — | An agent must do these alone — this is what makes it a member |

**Working — how an agent learns there is work.** (a) **A mention or a DM**: a message that mentions an agent, or a DM to it,
writes an `agent_dispatches` row; the worker (every minute — or at once, when the poster's request can afford to wait up to
`wait` seconds) runs ONE turn of the kernel's chat endpoint **as that agent** (`?agent=<id>`) with the asker as
`X-Acting-Member`, the channel and thread as context, the last turns of the thread as the conversation (`conversation_id` =
the thread), and posts the reply in the thread as the agent (a 202 run is polled by the worker and the reply posted when it
finishes; the thread shows "Seamus is thinking…" meanwhile). **An agent's reply never mentions `@channel`** (the converter
strips it). (b) **A duty** (cron) for the Librarian. (c) **The command bar** — a person's turn on the expert. (d) When the
kernel's K8 (wake an agent from an application) lands, the dispatch wakes the agent directly instead of a chat turn, and a
long task (write this page) becomes a run with a result posted when done. Until K8, the chat turn.

**Shipped agents** (`maludb-os.json` `agents[]`; **hired on install** at the owner's word — §13.11):

| Key | Job | Reads | Acts | Pauses on |
|---|---|---|---|---|
| `expert` — Spaces Expert | The command bar and the house name to mention: answers "what did we decide about the pricing page", "where is the onboarding checklist", "summarise #ops since Monday", "who owns the vendor list and when was it verified"; **drafts** a page from what the asker says (a template applied, Markdown in); summarises a thread into a reply or a page when asked; explains a database's fields; never publishes, shares out or deletes unless the asker says so, and then pauses | every records tool, `record_history`, `actor_timeline` | `page_create`, `page_update`, `block_append`, `block_update`, `row_create`, `row_update`, `message_post`, `thread_reply`, `comment_add`, `template_apply`, `page_publish` (paused), `share_guest` (paused) | `page_publish`, `share_guest`, `@channel` |
| `librarian` — Knowledge Librarian | A duty every Monday 06:30 (`30 6 * * 1`): the **wiki report** — pages whose verification expired or never happened, pages not edited in N days (setting, 90) that are still linked from the sidebar, orphan pages (nothing links to them, not in a sidebar), broken internal links (a `link_to_page` to the trash), duplicate titles, databases with rows missing their required properties; the **channel report** — questions in public channels with no reply in 24 h (a message ending in "?" with no thread), threads of 8+ replies with an "also send to channel" that never came, decisions ("we decided", "let's go with") in threads with no page citing them — each a **proposal**: "make this thread a page in Engineering › Decisions" (`page_create` from the thread, a draft, the proposer named); writes one **Monday note** in `#spaces-admin` and to the admin's assistant; nudges each page owner once (a DM) | `wiki_status`, `stale_pages`, `orphan_pages`, `broken_links`, `unanswered_questions`, `thread_candidates`, `get_page`, `page_read`, `thread_read`, `find_*` | `page_create` (drafts), `message_post` (to `#spaces-admin` and DMs to owners), `comment_add` ("this page's verification expired on …"), `message_send` (the kernel's, to the admin's assistant) | nothing (it never publishes, shares, shouts or deletes) |

**Any other agent** hired by the kernel becomes a member of Spaces when a super-admin grants it the Member role (the
kernel's grant screen); its tool grants are its own; the shipped grants above are a floor the super-admin may widen. **A
person's assistant** reached by DM is the same chat turn with `?agent=<the assistant's id>` — the kernel's tree decides what
it delegates; nothing here knows the tree.

**Skills** (`skills/`): `spaces-basics` (what a space, a page, a block, a database, a view, a channel, a thread and a DM
*are*; which tool answers what; Markdown in and out — the one page every agent reads), `writing-pages` (structure, headings,
callouts, when a database beats a list, templates, how to cite a thread, never paste a secret), `talking-in-channels` (tone;
answer in the thread; one reply, not five; never `@channel`; say when you are unsure; cite the page), `keeping-the-wiki`
(verification, ownership, stale and orphan rules); runbooks (`kind: runbook`): `monday-wiki-report`, `thread-to-page`,
`answer-from-the-wiki`, `summarise-a-channel`.

## 6. Memory model — what Spaces remembers

**Record memory** (PostgreSQL 17, `<tenant>_spaces`, db/001–0NN). Pages, blocks, databases, views and comments are keyed
by **UUID** (the editor makes ids as it types, URLs do not enumerate, a Notion import keeps its ids); messages, channels,
spaces, members and everything administrative by **bigint** as in every sibling. Block order is a **fractional position**
(`position text`, a lexicographic key: an insert between two blocks never renumbers the rest — the standard of every block
editor). Every text field is the one **rich text** JSON of §0.3; `plain_text` is a generated column feeding `search_tsv`.

| Area | Tables | Notes |
|---|---|---|
| The mirror | `members` (+ `is_agent`, `is_external`, `status_text`, `status_emoji`, `status_until`, `last_seen_at`, `timezone`), `department_members`, `departments`, `sso_nonces`, `member_sessions`, `directory_sync_state` | the kernel's ids; `members.roles text[]` from the claims and `access[]`; departments as principals and `@handles` |
| Roles | `sp_rights`, `sp_roles`, `sp_role_rights`, `mcp_app_roles` | `sp_has_right(right)` |
| Settings | `sp_settings` (one row: business name on public pages, default space kind, default member level, version snapshot interval (minutes, 10), version retention days (90), trash retention days (30), stale-page days (90), unanswered hours (24), max attachment bytes, allowed embed hosts, public pages `noindex`, digest hour, week start), `emoji_shortcodes` (seeded, Unicode) | seeded |
| Spaces | `spaces` (name, slug, icon, description, `kind` open/closed/private, `is_default` (everyone is a member, cannot leave), `department_id` nullable (the standing department's space), `member_level`, `everyone_level`, `is_wiki`, `wiki_default_verify_months`, `archived_at`), `space_members` (member, `role` owner/member, joined_at, notify prefs), `space_sections` (the sidebar's ordered groups of root pages within a space) | `General` seeded as the default; one space per standing department seeded, named after it |
| Pages | `pages` (uuid, `space_id` nullable (NULL = private), `parent_page_id` nullable, `parent_database_id` nullable (a row), `title` rich text, `icon` (emoji or file), `cover` file, `kind` page/database/template, `is_locked`, `is_template`, `template_of_database_id`, `owner_member_id`, `wiki_owner_member_id`, `verification_state` none/verified/expired, `verified_at`, `verified_by`, `verify_until`, `properties jsonb` (a row's values), `permission_root_id`, `position`, `created_by/at`, `last_edited_by/at`, `archived_at` (the trash), `archived_by`, `version_no`), `page_permissions` (§3), `page_links` (from page, to page or database — maintained from `link_to_page`, mention and `child_page` blocks by trigger, for backlinks, orphans and broken links), `page_favorites` (member × page, position), `page_recents` (member × page, visited_at — the last 50 kept), `page_versions` (page, version_no, `snapshot jsonb` = the block tree, title and properties; written by the worker when a page was edited and the last snapshot is older than the interval, on `version_save`, before a restore, and on lock; never rewritten; pruned by retention), `page_publications` (page, token hash, include_subpages, noindex, published_by/at, revoked_at, views) | the retired `documents.md` kept its versions rule: restoring writes a NEW version |
| Blocks | `blocks` (uuid, `page_id`, `parent_block_id` nullable, `type` (§0.3), `position`, `content jsonb` (the type's object exactly as Notion's API shapes it: `rich_text`, `checked`, `language`, `caption`, `icon`, `color`, `is_toggleable`, `url`, `expression`, `has_column_header`, `synced_from`, `page_id` for `link_to_page`…), `plain_text` generated, `has_children` maintained, `version int` (optimistic: a save carries the version it read; a mismatch is refused, `SQLSTATE P0001 'stale'`, and the editor reloads the block), `created_by/at`, `last_edited_by/at`), `block_files` (a block's image/file/pdf/video/audio → `attachments`) | a block belongs to one page and, below the root, one parent block of that page (trigger); `child_page` and `child_database` blocks are the tree's edges (a page's `parent_page_id` and its `child_page` block are kept in step by trigger); a synced block's original is `synced_from IS NULL`; `table_row` only under `table`; `column` only under `column_list` |
| Databases | `databases` (uuid = its page's id, `title`, `description`, `is_inline`, `properties jsonb` — the schema: `{key: {id, name, type, options[], number_format, relation:{database_id, dual_property}, rollup:{relation, property, function}, unique_id:{prefix}}}`, `title_property_key`), `database_views` (uuid, database, name, `layout` (§0.3), `filter jsonb` (Notion's filter object: `and`/`or`, property, condition), `sort jsonb`, `group_by`, `sub_group_by`, `visible_properties[]`, `calendar_by`, `timeline_by` start/end, `card_size`, `wrap`, `linked_from_page_id` (a linked view on another page), position), `row_relations` (from row, property key, to row — the one table behind every `relation`; dual properties are two rows), `unique_id_sequences` (database × prefix → last) | a row's values live in `pages.properties`; `rollup` and the four `created_*`/`last_edited_*` are **computed in the read function**, never stored; `people` values are member ids; `files` values are attachment ids; a schema change that removes a property keeps the values (Notion's behaviour) until a person purges |
| Channels | `channels` (bigint, `space_id` nullable (NULL = DM / group DM), `kind` public/private/dm/group_dm, `name`, `topic`, `purpose`, `is_default` (the space's `#general`), `created_by`, `archived_at`, `retention_days` nullable, `last_message_at`, `message_count`), `channel_members` (channel, member, joined_at, `last_read_message_id`, `muted_until`, `notify` all/mentions/none, `starred`, `section` (the member's sidebar section)), `channel_pins` (channel, message or page, by, at), `channel_bookmarks` (channel, title, url or page, position), `dm_pairs` (the two member ids, ordered, unique → channel) | a DM has exactly two members, a group DM 3–9 (settings); a public channel's readers are the space's members (not `channel_members`), its *followers* are `channel_members` |
| Messages | `messages` (bigint, channel, `thread_root_id` nullable, `author_member_id`, `body` rich text, `plain_text` generated, `kind` message/system/agent_pending, `reply_count`, `last_reply_at`, `also_to_channel` (a thread reply shown in the channel too), `edited_at`, `deleted_at` (a tombstone keeps the thread's shape: "This message was deleted"), `scheduled_for` (a draft until then), `sent_at`, `agent_run_id` nullable, `attachments`), `message_mentions` (message, kind member/agent/department/channel/here/everyone, id), `message_reactions` (message, member, emoji; unique), `saved_messages` (member × message, at), `message_links` (message → page/database/message mentioned, for unfurls and backlinks), `reminders` (member, message or page or free text, `remind_at`, done_at) | the author alone edits; a delete is a tombstone; a thread reply's root must be a message of the same channel without a root (one level, as Slack); unread = `messages.id > channel_members.last_read_message_id` |
| Comments | `comments` (uuid, page, `block_id` nullable (inline), `parent_comment_id` (a discussion), author, body rich text, `resolved_at/by`, `edited_at`, `deleted_at`) | a block's comments show in the margin; a page's at the top |
| Notifications | `notifications` (member, kind mention/reply/reaction/comment/share/page_changed/verification/reminder/dm/channel, entity, read_at), `notification_prefs` (per member: mentions, DMs, replies, digests, texts), `notification_outbox` (email; K6 texts for a DM or a mention when the person chose them and is away) | txtSchedules' outbox, as Help Desk and Consultant Tracking copied it |
| Files | `attachments` (on a block, a message, a comment, a page cover/icon, a row's `files` property; filename, mime, bytes, sha256, `storage_path` under `storage/attachments/<kind>/<id>/`, `width`/`height` for images, by, at) | Help Desk's `store_attachment()` copied: `finfo` from the bytes, the allow-list (images incl. HEIC → kept as is, PDF, Office, text, Markdown, CSV, audio and video files as *files* — no transcoding), executables, HTML and SVG refused, 25 MB default; served through the gate at `/files/<id>`; an image block gets a thumbnail (GD) |
| Search | `search_index` (entity kind page/row/message/comment, id, space, channel, `tsv`, author, at; maintained by trigger from `plain_text`) and `sp_search(q, filters)` | one function, the Slack modifiers (`in:`, `from:`, `has:`, `before:`, `after:`, `is:`), ranked, paged |
| Templates | `templates` (page templates: a page with `is_template` in a space's "Templates" or the workspace's; database templates: a row with `template_of_database_id`), seeded: Meeting notes, Decision record, Project brief, Runbook, Weekly update, 1:1, Vendor list (database), Decision log (database), Reading list (database), Content calendar (database) | `template_apply` copies the tree with new ids |
| Imports and exports | `imports` (kind markdown_zip/notion_zip/csv, file name, pages made, rows made, by, at, log), `exports` (kind page/space/channel/all, format md/html/csv/json/zip, path, by, at, expires) | the files under `storage/exports/`, 7 days |
| Agents | `agent_dispatches` (message or page, agent member id, kind mention/dm/duty_proposal, chat run id, status pending/running/replied/failed, attempts, reply message id), `librarian_proposals` (kind thread_to_page/verify/orphan/duplicate, subject, proposed page id, status proposed/accepted/dismissed, by) | §5 |
| Tokens | `mcp_access_tokens` | the contract's; a person's own, read-only |

### 6.1 Tables against the estate — read, reuse, new (the shared-schema rule, 2026-10-05)

The estate has one data model in many databases (`maludb-os-integration` 0.7.0, `shared-schema.md`). Every table above
was decided against the seven sibling applications' `db/*.sql` before it was written; the definition of every reused table
lives in THIS repository's migrations, so the installer creates it whether or not the sibling is installed. Spaces is the
first application designed under the rule.

| Table(s) | Decision | Source and what differs |
|---|---|---|
| `members`, `departments`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state` | **reuse** | the kernel contract (Consultant Tracking `db/001`); `members` **appends** `status_text`, `status_emoji`, `status_until`, `last_seen_at` (Slack's status and presence) |
| `activity_log`, `activity_ingest_state` | **reuse** | `memory.md` / CT `db/002`; **appends** the audit keys `space_id`, `channel_id`, `message_id` and `entity_uuid` (pages, blocks, databases, views and comments are UUID-keyed — D6; `entity_id` stays for the bigint records) |
| `mcp_access_tokens` | **reuse** | CT `db/003`, verbatim |
| `sp_rights`, `sp_roles`, `sp_role_rights`, `mcp_app_roles` | **reuse** (shape) | CT `db/004` (`ct_` → `sp_`); the four roles of §3 |
| `sp_settings` | **reuse** (skeleton) | the `app_settings` singleton skeleton (Help Desk `db/005`, txtSchedules `db/005`); the columns are this application's |
| `attachments` | **reuse** | **GL `db/009` canonical** (`record_type`/`record_id`, `filename`, `mime_type`, `byte_size`, `sha256`, `storage_path`, `uploaded_by`); **appends** `record_uuid` (a block, page, comment or row — UUID records), `width`, `height`, `thumbnail_path`; **one deviation recorded**: `record_id` becomes nullable with `CHECK ((record_id IS NULL) <> (record_uuid IS NULL))`, because the canonical assumes bigint records — proposed as an appended optional key for the catalogue |
| `notifications`, `notification_prefs`, `notification_outbox` | **reuse** | **GL `db/014` canonical**; `kind` lists are this application's; `notification_prefs` **appends** `digest` (Help Desk's) and `away_minutes`; **the outbox has no external-party column** — every recipient here is a member (a guest is a member), so `member_id` is NOT NULL and the pair CHECK is dropped; recorded as the substitution |
| `agent_dispatches` | **reuse** | **GL `db/014` = CT `db/013` canonical**; `kind` widened to `mention`, `dm`, `duty_proposal`, `ask`; **appends** `record_uuid` (a page), `conversation_id` (the thread root), `reply_message_id` |
| `comments` | **new** (recorded) | Projects' `comments` (bigint, on an issue, Markdown body) and Help Desk's `messages` (on a ticket) were compared: a Spaces comment is on a page or a block (UUID), threaded, with the one rich-text body — a different concept; the name is kept because every sibling's `comments` means "a comment on one of my records" and the shape is the record key + author + body + edited/deleted, as Projects' |
| `messages` | **new** (recorded) | Help Desk's `messages` are a ticket's correspondence (public reply / internal note, email ids); a Spaces message is a channel message with a thread root. Same name, same family (author + body + edited_at + deleted_at + kind), different record key — recorded as a known sibling, not a reuse |
| `search_index` | **new** | no sibling keeps one (Projects and the kernel index in place with `tsvector` columns); Spaces searches four entity kinds at once — it becomes canonical for a cross-entity index |
| `pages`, `page_*`, `blocks`, `databases`, `database_views`, `row_relations`, `unique_id_sequences`, `channels`, `channel_*`, `dm_pairs`, `message_*`, `saved_messages`, `reminders`, `spaces`, `space_*`, `imports`, `exports`, `librarian_proposals`, `emoji_shortcodes` | **new** | nothing close in the estate (Help Desk's `articles`/`kb_sections` are its knowledge base — **read**, below; the kernel's retired `documents` is gone) — these become the canonical tables for pages, blocks and conversations |
| Help Desk's `articles` and `kb_sections`, Projects' `issues`, Consultant Tracking's `engagements`, HR's employment, the ledger's parties | **read** | another application's data: reached through K7 when a sibling shares it (HD1/P2 `ticket_card`/`task_card` are Extended); Spaces never copies a row of them |

**Owed to the catalogue** (`shared-schema.md` §4): `attachments` gains the optional `record_uuid` key; `messages` and `comments` are
recorded as two shapes under one family; `search_index`, `pages`/`blocks` and `channels`/`messages` are Spaces' canonical tables.

**Views**: `mcp_*` over every table above with `security_barrier`, the caller's visible sets (`sp_visible_page_ids()`,
`sp_visible_channel_ids()`, `sp_visible_member_ids()`) computed once per statement; the reads that matter are **SQL
functions** — `sp_page_tree(page)` (the block tree in order, children nested, one query — recursive CTE over `position`),
`sp_page_markdown(page)` (the tree rendered to Markdown in SQL for the agents and the exports; the PHP converter is its
twin for the way in), `sp_database_rows(view, cursor)` (the view's filter, sort and group applied in SQL; rollups computed;
paged 100 at a time), `sp_channel_history(channel, before, limit)`, `sp_thread(root)`, `sp_unread(member)` (every channel's
unread count and first unread id), `sp_activity_feed(member)` (mentions, replies, reactions to me), `sp_search(...)`,
`sp_backlinks(page)`, `sp_wiki_status(space)`, `sp_stale_pages()`, `sp_orphan_pages()`, `sp_broken_links()`,
`sp_unanswered_questions(hours)`, `sp_thread_candidates()` — so a screen, a tool and an export never disagree.

**Markdown ↔ blocks** (`app/richtext/`): one converter, both ways, covering every adopted block type (headings, lists with
nesting, to-dos, quotes, callouts (`> [!NOTE]`), code fences, dividers, tables, images and files by link, equations (`$…$`),
toggles (`<details>`), columns (lost on the way out — flattened), synced blocks (their content), `link_to_page` and mentions
(`[[Page title]]` and `@Name`), bookmarks and embeds (the URL). An agent writing a page writes Markdown; a person reading an
agent's page sees blocks. A Notion export (Markdown & CSV zip) imports: pages by their titles and ids, databases from the
CSVs with types inferred and then fixed by a person.

**Activity memory**: `activity_log` through one `log_activity()`, `entity.verb` events, shipped to the tenant's one MaluDB as
`activity` episodes tagged `spaces`. **There is no audit table**: a record's history is `mcp_activity_log WHERE entity_type =
… AND entity_id = …` (the sibling rule); **page content's history is its versions**. A message body and a page's text are
**never in a log payload** (ids, titles, counts, the block type and the channel name are). Events (the manifest names them):
`space.create|update|archive|delete|member_add|member_remove|kind_set`, `page.create|update|move|lock|unlock|archive|restore|
delete|share|unshare|publish|unpublish|verify|favorite|view|public_view|version_save|version_restore|import|export`,
`block.append|update|move|delete`, `database.create|schema_save|delete`, `row.create|update|delete`, `view.save|delete`,
`channel.create|update|join|leave|archive|unarchive|delete|pin|unpin|retention_set`, `message.post|edit|delete|schedule|react|
save|also_to_channel`, `thread.reply`, `comment.add|edit|resolve|delete`, `reminder.set|done`, `agent.dispatch|reply|fail`,
`librarian.propose|accept|dismiss`, `attachment.add|delete`, `export.download`, `share.read` (a sibling read us), `token.mint|
revoke`, `member.sign_on`, `directory.sync`, `screen.view`. A public token is never logged.

## 7. The question inventory — what Spaces exists to answer

Kind R = record (the records MCP), A = activity (the activity MCP). Who = the lowest role that may ask (every answer is
filtered by what the asker may see). The tool name is fixed here so Phase 1 is a transcription.

**Spaces and people**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| S1 | Which spaces exist, which am I in, which may I join, who owns each? | R | Member | `find_spaces`, `get_space` |
| S2 | Who is in a space, and who is an agent? | R | Member | `space_members` |
| S3 | Who is who here — members, agents, departments, guests I may see — with status and presence? | R | Member | `find_members` |
| S4 | What are the workspace's settings, templates and seeded vocabulary? | R | Member | `get_settings`, `find_templates` |

**Pages and the wiki**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| P1 | What pages are in this space, in sidebar order, and under this page? | R | Member | `page_tree`, `find_pages` |
| P2 | What does page X say? (**Markdown**, with its properties, owner, verification, backlinks) | R | Member | `page_read`, `get_page` |
| P3 | Which pages mention or link to this one? | R | Member | `backlinks` |
| P4 | Which pages changed since Monday, in my spaces? | R | Member | `pages_changed_since` |
| P5 | Who may see or edit page X, and why (which ancestor, which principal)? | R | Member (edit) | `page_permissions` |
| P6 | What did page X look like last Tuesday, and what changed between two versions? | R | Member | `page_versions`, `page_version_read`, `page_diff` |
| P7 | Which wiki pages are verified, expired, unverified, by owner? | R | Member | `wiki_status` |
| P8 | Which pages are stale, orphaned, duplicated, or link to the trash? | R | Member | `stale_pages`, `orphan_pages`, `duplicate_titles`, `broken_links` |
| P9 | What is in my favorites and what did I open recently? | R | Member | `my_favorites`, `my_recents` |
| P10 | What is in the trash, and when is it purged? | R | Member | `trash` |
| P11 | Which pages are published to the web, and how often were they opened? | R | Admin | `published_pages` |
| P12 | Who edited this page, block by block, and when? | A | Member | `record_history` |

**Databases**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| D1 | What databases exist in my spaces, with what properties? | R | Member | `find_databases`, `get_database` |
| D2 | What rows does view V show (its filter, sort, group applied), paged? | R | Member | `database_rows` |
| D3 | Which rows match this filter (an ad-hoc Notion-style filter object)? | R | Member | `database_query` |
| D4 | What is row R, with its relations and rollups resolved, as Markdown? | R | Member | `row_read` |
| D5 | Which rows are mine (a `people` property naming me), due this week (a `date`), or in status S? | R | Member | `my_rows`, `rows_due` |
| D6 | Which rows are related to row R through property P? | R | Member | `row_relations` |

**Channels and messages**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| C1 | Which channels exist in a space, which do I follow, which are archived? | R | Member | `find_channels`, `get_channel` |
| C2 | What was said in #ops since Monday (paged, oldest first), with threads collapsed? | R | Member | `channel_history` |
| C3 | What is in this thread, in order? | R | Member | `thread_read` |
| C4 | What is unread for me, by channel, and where is my first unread? | R | Member | `my_unread` |
| C5 | Who mentioned me, replied to me, or reacted to me since yesterday? | R | Member | `my_activity` |
| C6 | What have I saved, and what reminders do I have? | R | Member | `my_saved`, `my_reminders` |
| C7 | What is pinned and bookmarked in this channel? | R | Member | `channel_pins` |
| C8 | Which messages in a channel contain a question nobody answered within N hours? | R | Member | `unanswered_questions` |
| C9 | Which threads look like decisions with no page citing them? | R | Member | `thread_candidates` |
| C10 | Which messages are scheduled to go out, and when? | R | Member (own) | `my_scheduled` |
| C11 | What did an agent say in channels this week, and in reply to whom? | A | Member | `actor_timeline` |
| C12 | What are my open DMs, and the last line of each? | R | Member | `my_dms` |

**Search and comments**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| Q1 | Find "rate limit" in pages, rows and messages — `in:#ops`, `from:@priya`, `has:link`, `before:2026-09-01`, `is:page` | R | Member | `search` |
| Q2 | What comments are open on page X, and which discussions are unresolved that mention me? | R | Member | `page_comments`, `my_open_discussions` |

**Agents**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| G1 | Which agents are members here, in which spaces and channels, and when did each last reply? | R | Member | `agents_here` |
| G2 | Which dispatches are pending or failed (a mention nobody answered)? | R | Admin | `agent_dispatches` |
| G3 | What did the Librarian propose, and what was accepted? | R | Member | `librarian_proposals` |

**Activity**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| ACT1 | Who did what to this record, in order? | A | Member | `record_history` |
| ACT2 | What did person or agent X do here this week? | A | Member | `actor_timeline` |
| ACT3 | What changed since I last looked? | A | Member | `recent_activity` |
| ACT4 | Who touched this space, and what did a sibling application read of ours? | A | Space owner | `who_touched`, `share_reads` |
| ACT5 | Search the activity for a phrase | A | Member | `activity_search` |

Plus the contract's: `app_roles`, one guarded `search` on each server, `record_history`. Every question is a named tool;
the reads are the same SQL functions the screens call. Records tools: about 48; activity tools: 6.

## 8. The kernel's part, and the application's doors

- **Sign-on and the mirror**: the contract (`sign-on-and-directory.md`), the kit copied from Consultant Tracking (Help Desk's
  lineage). The mirror carries `is_agent` (so the people picker marks agents) and departments as principals.
- **Roles**: `app_roles` on the records server; the kernel's token admitted to it and to the `shares[]` alone.
- **Agents**: run tokens on the two read servers (the run-facts gate, fail closed); the Actions MCP from the registry; the
  approval hook before every action with a category; the chat endpoint for the command bar **and for every mention and DM**
  (§5 — `?agent=<id>`, `X-Acting-Member` = the asker, `conversation_id` = the thread); the duty for the Librarian.
- **K6 texts**: "Priya mentioned you in #ops: …" (the first 120 characters, never more), "New DM from Seamus" — when the
  person chose texts and has been away more than N minutes (setting, 15); every refusal falls back to email.
- **K7 — Spaces is a provider.** Shares (`shares[]`), every one a records-server tool, people-free, each answering a
  versioned document:
  | Tool | For | Document | Content |
  |---|---|---|---|
  | `pages_index` | every sibling (Help Desk's knowledge base "see also", Projects' "project page", Consultant Tracking's engagement notes) | `os.spaces-pages/1` | the pages the **requesting application's expert agent** may see (the share runs as that agent's member id — the kernel passes `X-Acting-Member`): id, title, space, path, last edited, verification; a search by title |
  | `page_markdown` | the same | `os.spaces-page/1` | one page as Markdown with its properties |
  | `channel_post_card` | *(not a share — reads only; see §11)* | — | — |
  Reads (`reads[]`): none in v1. **Posting into a channel from a sibling** ("ticket HD-1042 escalated" into `#support`) is a
  write between applications, which the kernel forbids; the v1 answer is the agent: a sibling's agent that is a member here
  posts it (its own grant, its own run). A feed subscription (a channel that polls a sibling's share) is Extended (§11).
- **Public doors** (allow-listed in the vhost; everything else needs a session): `/p/<token>`, `/p/<token>/files/<id>`,
  `/api/v1/health`, `/mcp/records`, `/mcp/activity`. Each is signature- or token-checked, rate-limited, and logged with
  `source = 'portal'`.
- **Live-ness without a socket** (the stack is Apache and PHP): a channel page polls `since=<last id>` every 3 s while
  visible (`hx-trigger="every 3s [document.visibilityState=='visible']"`), 20 s when hidden, and the server answers 204 with
  nothing new (one indexed query); a page being edited polls presence and "changed by someone else" every 10 s and shows a
  banner to reload the changed blocks. Server-sent events are Extended (a PHP process per open tab is the wrong price today).

## 9. Screens (the nxl look; a channel and a page are read at 375 px first; the editor is designed at 1280 px and works at
375; no modals — the slash menu, the mention picker, the emoji picker and the property editor are popovers inside the
editor; cards for named things — spaces, pages in a list, the gallery view —, tables for the table view and admin lists;
every name a link; every page a way back)

**The shell**: a three-pane layout at 1280 (the sidebar: Home · Activity · Saved · Search, then **Favorites**, then each
**space** with its sections and root pages and its channels, then **Shared**, then **Private**, then **Direct messages**; the
main pane; a right pane for a thread or a page's comments), a single pane with a bottom tab bar at 375 (Home · Channels ·
Pages · Search · Me). The command bar (the kernel's chat endpoint) at the top of every screen.

**Home** — unread channels with their first unread lines; mentions and replies waiting; recently edited pages in my spaces;
my favorites; pages I own needing verification; the Librarian's last Monday note; for a space owner: pending joins (closed
spaces), the space's unanswered questions; for the admin: pending dispatches, published pages, trash size.

**Spaces:** Spaces (cards by kind; join an open one; request a closed one; `space-view` = its home page: description, sections
with root pages, channels, members with agents marked, the wiki's status when it is one) · `space-form` (name, icon, kind,
default, levels, wiki) · Members (add a member, a department's members, an agent; make owner; remove) · Sections · Templates ·
Archive.

**Pages:** `page-view` (= **the editor** for anyone with edit, the reader for everyone else: breadcrumb, icon and cover,
title, properties when a row, the block tree with the slash menu (`/` lists every adopted type), Markdown shortcuts (`#`,
`-`, `1.`, `[]`, `>`, ```` ``` ````, `---`, `$$`), drag handles (SortableJS, as Help Desk's board), Tab/Shift-Tab nesting,
Enter splits, Backspace merges, `@` mentions a member, agent, department, page or date, `[[` links a page; comments in the
margin; "who is here"; the stale-save banner; Share, Publish, Lock, Verify, Favorite, Move, Duplicate, Export, History, Delete
in the page menu) · `page-share` (the principals and levels, inherited vs explicit, restrict, the guest share, the link) ·
`page-history` (versions, a diff between two, restore) · `page-move` · Trash (restore, purge) · Templates gallery · Import
(`/import`: a Markdown or Notion zip, a CSV into a database; a preview; the log) · the published page `/p/<token>`.

**Databases:** `database-view` (the view tabs; the **table** with inline property editing, add a row, hide/show properties,
filters, sorts, groups, sub-groups; the **board** by a select/status/people property with SortableJS moves; the **gallery**
of cards with a cover; the **list**; the **calendar** by a date property; the **timeline** by a date range; a row opens as a
page in the right pane at 1280 or full-page at 375) · `database-schema` (properties: add, rename, retype where lossless,
options, relation target and dual, rollup source and function, unique-id prefix) · `view-form` · Linked view (on any page).

**Channels:** `channel-view` (messages newest at the bottom, day dividers, the unread line, threads collapsed with reply
count and avatars, reactions, pins, hover actions: react, reply in thread, save, pin, edit, delete, copy link; the composer:
rich text with Markdown shortcuts, `@`, emoji `:`, attach, "send later"; "jump to first unread") · `thread-view` (the right
pane at 1280, full-page at 375; "also send to channel") · `channel-form` (name, topic, purpose, kind, retention) · Members ·
Pins and bookmarks · Browse channels (per space; join, follow) · `dm-view` (the same as a channel; a DM to an agent shows
"agent" and the thinking state) · New message (a people picker that makes or finds the DM) · Scheduled · Saved · Reminders ·
Activity (mentions, replies, reactions, by people or agents — Slack's 2026 filter).

**Search:** `/search?q=` with the modifiers as chips, results grouped pages / rows / messages / comments, the match in context.

**Admin:** Settings · Spaces (every one, the private ones marked and logged when opened) · Published pages · Retention ·
Exports (a page, a space, a channel, everything; Markdown/HTML/CSV/JSON; a ZIP) · Trash (all) · Agents (which agents are
members, their dispatches, last reply — read-only; hiring and grants link to the kernel) · Connections (which siblings read
us — read-only) · Librarian proposals · My settings (notifications: per channel and per kind; texts; digest; timezone;
status) · My MCP tokens.

## 10. Build order, gates and size

| Phase | Deliverable | Gate |
|---|---|---|
| 0 | this document and the owner's answers (§13); the schema `db/001`–`0NN` proven on a scratch database (`db/proof/phase0_proof.sql`: every actor of §2; a space of each kind and who sees it; a page tree with inherited, restricted and private permissions resolved for seven actors; a block inserted between two, nested, moved, split, merged; a stale save refused; a synced block read and unread; a database with every property type, a relation and its rollup, a view's filter/sort/group applied in SQL; a public channel, a private one, a DM and a group DM; a thread, a reaction, a pin, an unread count, a tombstone; a mention dispatch; a comment resolved; a version snapshot and restore; the trash and its purge; retention; search with every modifier; the Markdown round trip of every block type); the kit (Consultant Tracking's identity, roles, sign-on, MCP common files, HTTP helper, attachments and sync), proven without a kernel (`tests/phase0/run.sh`); `maludb-os.json` (every port env in `env.required`, the two `shares[]`), `os/{expert,librarian}.md`, eight skills (four runbooks), `deploy/` templates with the public doors and `ROOT_STEPS.sh` (the port pin first); the installer's `plan` reads the repository clean | the owner's go on §13 |
| 1 | `docs/spaces-mcp-tool-surface.md` (every question of §7 a tool; the resolve table; the log-payload rules; the share documents' shapes), `docs/spaces-action-manifest.md` (screens and actions with their categories — §5), `mcp/action_registry.json`, and the slice specs in `docs/build-specs/` (`sso-shell` and the nine slices; **the editor and the channel are the exemplars**) | **approved together, before any PHP** |
| 2 | `/sso`, `/sso/logout`, the mirror and its timer, `members.roles` from the claims and the feed, `sp_has_right()`, the ingest bridge, the shell (three panes at 1280, the tab bar at 375, the sidebar from the read functions, the command bar through the chat endpoint), `/api/v1/health`, the vhost with the public allow-list | hand-off replay refused; an unknown member refused; a revoked grant shuts every door within a minute; 375 and 1280 |
| 3 | the slices, in order: **(1) spaces, membership and settings** (spaces of three kinds, join and request, members and owners, sections, the settings, the seeded vocabulary — the CRUD exemplar) → **(2) pages: the tree and the permissions** (pages and the sidebar, create, move, duplicate, lock, favorites, recents, the share screen with the permission tree and the guest share, trash and restore, templates, the reader (`sp_page_markdown` rendered), the published page) → **(3) the block editor — THE FIRST EXEMPLAR** (every adopted block type rendered and edited; the slash menu; Markdown shortcuts; split, merge, nest, move, drag; images and files; mentions and page links; synced blocks; columns; the stale-save rule; versions and history with diff and restore; comments in the margin; presence) → **(4) channels and messages — THE SECOND EXEMPLAR** (channels of four kinds, browse and join, the composer, polling, threads and also-to-channel, reactions, pins and bookmarks, edit and delete, unread and the line, mute and star, saved, scheduled, reminders, mentions and the Activity feed, the agent mention → dispatch → reply loop with the thinking state) → **(5) databases and views** (the schema editor, every property type, relations and rollups, the six layouts, filters, sorts, groups and sub-groups, inline editing, templates, linked views, CSV in and out) → **(6) notifications, search and the wiki** (the bell and prefs, the outbox and digests, K6, the one search with modifiers, the wiki mode with ownership and verification, backlinks, stale/orphan/broken, duplicate titles) → **(7) agents in spaces** (agents in the picker, DMs to agents, the Librarian's duty and proposals, thread-to-page, the Agents and Connections pages, the two shares) → **(8) the worker, imports, exports and retention** (the one timer: scheduled messages, reminders, the outbox, snapshots, retention, trash purge, dispatch retry, the search index's catch-up; Markdown/Notion/CSV import; Markdown/HTML/CSV/JSON/ZIP export) → **(9) home, admin and tokens** (the homes, Activity, Saved, the admin pages, MCP tokens, settings polish) | each slice: screens + handlers + logging + manifest entries + tools, proven at 375 and 1280 on a scratch database, the installed application never touched |
| 4 | the two MCP servers with the run-facts gate, `app_roles` and the two `shares[]` tools admitted to the kernel's token, the registry wrapper (`deploy/kernel-registry-spaces.json`), the two agents declared with their grants and the duty; the kernel's Actions-MCP contract proved against its own `application_actions.register()` with a stubbed hook | the command bar answers "what did we decide about X" (`search` + `page_read`); an agent's `page_publish` pauses; a mention of the expert gets a reply in the thread; the Librarian's duty publishes nothing, shares nothing, deletes nothing |
| 5 | the owner's `app_install.php apply` (ports pinned first — §14), DNS and TLS for `spaces.<domain>`, the MaluMail key, the hires, the first spaces and channels; the end-to-end proof | **a member writes a page with every block type on a desktop and reads it on a phone; shares it to a department and restricts a subpage; a guest sees only the shared page; a database with a relation and a rollup shows the same rows in a table, a board and a calendar; a channel conversation runs on two phones with a thread, a reaction and an unread line; a member mentions the expert and gets an answer in the thread citing a page; a DM to Seamus is answered; the Librarian's Monday note lists a stale page and proposes a page from a thread, a person accepts it; a page is published and opened without a session; a Notion export imports; a space exports to a ZIP; a channel with 7-day retention loses a message on day 8; Help Desk reads `pages_index` over an approved connection** |

**Extended** (after version 1, each its own spec, §11). Size: ten specs (sso-shell + nine slices), about 60 screens, 120
actions, 48 records tools, 6 activity tools — a size above Help Desk's (65 screens, 96 actions) because of the two editors.
**Five to six days is the honest estimate**: the block editor two of them (it is the one piece of serious front-end
JavaScript in the whole OS), channels one, databases one, the rest and Phase 5's proof with the owner the remainder.

## 11. Extended, later, and what other applications owe

**Extended** (each its own spec, in the likely order): **formulas** (a safe expression language over properties — Notion's
functions, evaluated in PHP, cached on the row) · **charts** (a seventh layout over a view, following the `dataviz` rules) ·
**real-time co-editing** (a CRDT per block — Yjs or a PHP port — and SSE or a small WebSocket sidecar; today a stale save is
refused and reloaded) · **feed subscriptions** (a channel that polls a sibling's share over an approved connection and posts
a card: Help Desk's `tickets_changed_since`, Projects' sprint changes — reads only, the kernel's way; and **sibling record
previews**: a `link_preview` of a Help Desk ticket or a Projects task resolved live by K7 reads the siblings owe: HD1
`ticket_card`, P2 `task_card`) · **rules** (Help Desk's engine over channel and page events: "when a row's status becomes
Done, post to #wins") · **forms** (a public form that creates a row — Help Desk's `/request` pattern) · **custom emoji** ·
**a magic-link comment door** on published pages · **K8 wakes** replacing the chat-turn dispatch, so an agent asked to
"write the runbook" works as a run and posts when done · **PDF export** (dompdf, the siblings' shared decision) · **autofill**
(a property filled by the expert from the page) · **offline reading** (a service worker caching recents).

**Later**: multi-workspace; Slack Connect-like federation between two installations; huddles and clips (a media server);
Slack and Notion live sync (two-way) — an import is the honest v1.

## 12. What the kernel and its siblings owe this application

| # | Item | Why the application cannot do it | Until then |
|---|---|---|---|
| **K20** | **Catalog entry `spaces` (kind `ours`) seeded by a migration** in `maludb-os-core`, business area **Operations**, category `communication`, icon `feather-layers`, criticality `high` — so every installation shows "Spaces — from us, not installed" and the installer's `apply` finds its row | the catalog is the kernel's; Consultant Tracking's row (db/168) came the same way | `application_catalog_save` by hand on this install |
| **K21** | **`spaces` added to `bin/install_default_applications.sh`** (a fourth default beside HR, Projects and Help Desk) with the standing departments granted — if the owner says it is a default (§13.3) | the installer is the kernel's | installed on request by name |
| K22 | **The chat endpoint carries an attachment list** (image and file ids the application serves on its internal port) so a mention with a screenshot reaches the agent; and a **`stream` of the reply** (A6's "Open" item) so a long answer shows as it is written | the endpoint is the kernel's | text only; the reply appears whole |
| K16 | **K8 (wake an agent from an application)** — already owed to Help Desk, General Ledger and Consultant Tracking; here for mentions and DMs (today a chat turn) and for "write this page" (a run, not a turn) | messaging is an agent's tool | the chat-turn dispatch |
| K23 | **The kernel's agent inbox and escalations mirrored as a Spaces DM** (an agent's `message_send` to a person with an assistant, an `escalation_raise` to a person — shown in Spaces as a DM from the agent) — a kernel share `agent_messages_since` (people-free per recipient: the kernel runs it as the recipient) that Spaces reads as a feed | the inbox is the kernel's (db/155) | people read the kernel's `/agents/org` inbox; Spaces shows DMs made here only |
| HD1 / P2 | **Help Desk shares `ticket_card`**, **Projects shares `task_card`** (one record as a card, people-free) for live previews of a pasted link | the siblings' data | a pasted link is a bookmark block with the URL |

Nothing else is owed: sign-on, the directory, the chat endpoint, K6 texts and K7 reads all exist. K20 is small and comes
first; K21 is one line and a README row.

## 13. Decisions the owner is asked for (recommendation first)

1. **Name and key** — "Spaces", catalog key `spaces`, repository `maludb-os-spaces` (created private 2026-10-05, in the org),
   local clone and install path `/srv/apps/spaces`, DNS label `spaces` (`spaces.<domain>`), database `<tenant>_spaces`,
   roles `spaces_rw` / `_records_ro` / `_activity_ro`, SQL prefix `sp_`. Confirm, or choose another name (the key must be
   `[a-z][a-z0-9_]*`; "Workspace" and "Hub" were considered — "Spaces" says both halves).
2. **Roles and names** — Guest (`guest`, read, nothing by default), Member (`user`), Space owner, Spaces admin. Confirm the
   words, and that a **Spaces admin sees private spaces** (logged), as Notion's workspace owner does. Alternative: private
   spaces closed even to the admin (then an owner who leaves orphans the space; the admin may only archive it).
3. **A default of every install** — Spaces is the business's wiki and chat; recommended as the **fourth default** (K21,
   beside HR, Projects and Help Desk), installed with `--grant-standing-departments` so every insider is a Member; the
   repository **public** (secret-free, as the three defaults are). Confirm, or keep it on request and private.
4. **Scope none; one workspace per installation**; departments are principals and `@handles`; a space per standing
   department seeded (named after it, closed, its members the department's) plus **General** (open, default); agents join
   General on hire. Confirm the seed.
5. **Three space kinds as Notion's** — open, closed, private; a closed space is visible and joined on request (the owner
   approves); a private one invisible. Default member level **edit**, everyone-level **view** on open spaces. Confirm.
6. **The block model is Notion's, verbatim** (§0.3: 32 types, their content objects, the rich-text runs, fractional
   positions, UUID ids) so Notion exports import and ours export in a shape Notion reads. `heading_4`, `tab`,
   `meeting_notes` and `transcription` stored as `unsupported` on import. Confirm, or ask for a smaller editor (paragraphs,
   headings, lists, to-dos, code, images, tables, callouts — eleven types; saves a day; loses a clean Notion import).
7. **Co-editing** — v1 is **per-block optimistic saves** with a version; a stale save is refused and the block reloaded;
   presence ("Priya is here") and a "changed by Priya — reload" banner every 10 s; **no CRDT**. Confirm (real-time
   co-editing is Extended with a cost of several days and a sidecar process).
8. **Databases** — every Notion property type but `formula`, `button` and `place`; relations (two-way) and rollups (eight
   functions) in v1; six layouts (no chart); filters, sorts, groups, sub-groups; templates; linked views on any page;
   **one database, one schema** (no multi-source "data sources"). Confirm.
9. **Channels** — in a space (public, private) or outside (DM, group DM of up to 9); threads one level deep with "also send
   to channel"; Unicode reactions (custom emoji Extended); edit by the author only; delete = a tombstone; scheduled messages;
   reminders; pins and bookmarks; mute, star, per-channel notify; **retention per channel, off by default**; **polling**
   every 3 s while visible, no socket (SSE Extended). Confirm.
10. **Agents as members** — any hired agent granted Member is in the picker, mentionable and DM-able; a mention or a DM is
    one chat turn of the kernel's endpoint **as that agent, under the asker's identity**, posted in the thread, the thread
    the conversation; an agent's reply never shouts. The expert and the Librarian hired on install into General. Confirm, and
    confirm that the super-admin alone grants other agents the Member role (the kernel's grant screen — no auto-join).
11. **What pauses for an agent** (§5): publishing to the web and sharing to a guest (`external_send`); `@channel`, `@here`,
    `@everyone` (`other`, pause by default); deleting pages, databases, channels and others' messages, purging the trash,
    restoring a version (`deletion`); retention, space kind, settings, templates (`other`). Everything else an agent does
    alone. Confirm.
12. **The wiki** — a space may be turned into a wiki: every page then has an owner (the creator until changed) and a
    verification state with an expiry (default 6 months); the Librarian reports Mondays and nudges owners once; **nothing is
    hidden when it expires** (a badge, not a wall). Stale = not edited in 90 days (setting); unanswered = a question with no
    thread reply in 24 h (setting). Confirm.
13. **The public page** — `/p/<token>` with subpages optional, `noindex` by default, images through the door, a database as
    a read-only table or gallery; published by the admin (`publish.web`); no comments, no forms in v1. Confirm.
14. **Files** — 25 MB default; images, PDF, Office, text, Markdown, CSV, audio and video **as files** (no player beyond the
    browser's, no transcoding, no huddles); HTML, SVG and executables refused; thumbnails for images. Confirm.
15. **Kernel and sibling items** — K20 (the catalog seed, first), K21 (the default installer line, if §13.3), K22 (chat
    attachments and streaming, Extended), K23 (the kernel's agent inbox as a DM feed, Extended), HD1/P2 (sibling cards,
    Extended) approved and built in their own repositories beside this application. Approve.
16. **Ports and the handoff** — `APP_INTERNAL_PORT=8186`, `MCP_RECORDS_PORT=8833`, `MCP_ACTIVITY_PORT=8834` pinned before
    `apply` (the block after Consultant Tracking's); and the **Consultant Tracking division**: a planning-class model builds
    K20/K21, Phase 0's second half, Phase 1 for your approval, Phase 2, slices 1–2 and **the two exemplars — slice 3 (the
    block editor) and slice 4 (channels)**; a worker model (Sonnet 5.5) builds slices 5–9, Phase 4 and Phase 5 from the specs.
    Confirm, or hand the worker everything after slice 3.

## 14. Ports, names and files

- Repository `/srv/apps/spaces` (origin `github.com/maludb/maludb-os-spaces`); database `<tenant>_spaces` (`subello_spaces`
  here); roles `spaces_rw`, `spaces_records_ro`, `spaces_activity_ro`; MaluDB: the tenant's one memory, episodes tagged `spaces`.
- `spaces.<domain>` (`vhost.label = "spaces"`); `APP_INTERNAL_PORT`, `MCP_RECORDS_PORT`, `MCP_ACTIVITY_PORT` **all three in
  `env.required`** and written to `config/.env` before `apply` (§13.16); `deploy/` files are templates (`{{DOMAIN}}`,
  `{{APP_DIR}}`, `{{APP_KEY}}`, `{{APP_INTERNAL_PORT}}`, `{{MCP_RECORDS_PORT}}`, `{{MCP_ACTIVITY_PORT}}`).
- Units: `spaces-records-mcp`, `spaces-activity-mcp`, `spaces-activity-ingest` (+ timer, every minute), `spaces-directory-sync`
  (+ timer, every minute), `spaces-worker` (+ timer, every minute: scheduled messages, reminders, the outbox and digests,
  dispatches and their retries, page snapshots, retention, trash purge, export expiry, the search index's catch-up).
- `maludb-os.json`: `catalog_key spaces`, `name "Spaces"`, `business_area "Operations"`, `category communication`, `icon
  feather-layers`, `criticality high`, `vhost.label spaces`, no `scopes`, `sso`, `directory {reads: true, writes: false}`,
  `assistant {command_bar: true, agent: expert}`, `agents [expert, librarian]` each `hired_on_install: true`, `approvals`
  (§5), endpoints (records, activity, web, health, the public page), services (above), `shares` (the two of §8), `reads []`.
- Env: the contract's required keys plus `MALUMAIL_API_KEY`, `MAIL_FROM`, `MAIL_FROM_NAME` (optional, as Help Desk),
  `ATTACHMENT_MAX_BYTES`, `SP_PUBLIC_BASE_URL` (the public page's absolute links), `SP_EMBED_HOSTS`.
- Vhost allow-list (public): `/p/`, `/api/v1/health`, `/mcp/records`, `/mcp/activity`.
- Kernel files: `mcp/registries/spaces.json` (made by the installer), the K20 catalog seed migration, the K21 installer line.
- Proof scratch ports: 8401–8407.

## 15. The owner's answers (2026-10-05 — rules, not questions)

Every recommendation of §13 was taken, in one sitting, the same day the plan was written.

| # | Decision | Where it lives |
|---|---|---|
| D1 | **"Spaces", catalog key `spaces`**, repository `maludb-os-spaces`, clone and install path `/srv/apps/spaces`, DNS label `spaces`, database `<tenant>_spaces`, roles `spaces_rw` / `_records_ro` / `_activity_ro`, SQL prefix `sp_` | `maludb-os.json`; §14 |
| D2 | **Four roles**: Guest (`guest`, read, nothing by default), Member (`user`), Space owner, Spaces admin (is_admin); **the admin sees private spaces, logged** `space.admin_view` | §3; `sp_roles`; `app_roles` |
| D3 | **A default of every install** (the fourth, K21), installed with `--grant-standing-departments`; the repository **public** | §10; `bin/install_default_applications.sh`; the org |
| D4 | **Scope none**; departments are principals and `@handles`; seeded: **General** (open, default, agents join on hire) and one closed space per standing department | §3; db seeds |
| D5 | **Three space kinds** — open, closed (join on request, the owner approves), private; default member level **edit**, everyone-level **view** on open spaces | §6; `spaces.kind`, `member_level`, `everyone_level` |
| D6 | **The block model is Notion's, verbatim** (§0.3): 32 types, their content objects, the rich-text runs, fractional positions, UUID ids; `heading_4`, `tab`, `meeting_notes`, `transcription` stored as `unsupported` on import | §6; `blocks`; `app/richtext/` |
| D7 | **Per-block optimistic saves** with a version; a stale save refused and reloaded; presence and a "changed — reload" banner every 10 s; **no CRDT** in v1 | §6; `blocks.version`; slice 3 |
| D8 | **Databases**: every property type but `formula`, `button`, `place`; relations two-way and rollups (eight functions); six layouts; filters, sorts, groups, sub-groups; templates; linked views; **one database, one schema** | §6; slice 5 |
| D9 | **Channels**: public/private in a space, DM and group DM (≤ 9) outside; one-level threads with also-send-to-channel; Unicode reactions; author-only edits; tombstone deletes; scheduled; reminders; pins and bookmarks; **retention per channel, off by default**; **polling** 3 s visible / 20 s hidden, no socket | §6; §8; slice 4 |
| D10 | **Agents as members**: a mention or a DM = one chat-endpoint turn **as that agent, under the asker's identity**, the thread the conversation, the reply in the thread, never shouting; expert and Librarian hired on install into General; **the super-admin alone grants other agents Member** (no auto-join) | §5; `agent_dispatches`; `agents[]` |
| D11 | **What pauses for an agent**: `external_send` for publishing and guest shares; `other` (pause by default) for `@channel`/`@here`/`@everyone`; `deletion` for pages, databases, channels, others' messages, trash purge, version restore; `other` for retention, space kind, settings, templates | §5; `approvals[]` |
| D12 | **The wiki**: a space may be a wiki; owner = creator until changed; verification expiry default **6 months**; the Librarian reports Mondays and nudges owners once; **nothing hidden on expiry**; stale = 90 days; unanswered = 24 h (settings) | §6; `pages.wiki_*`; `sp_settings` |
| D13 | **The public page** `/p/<token>`: subpages optional, `noindex` default, images through the door, a database as a read-only table or gallery; the admin publishes; no comments, no forms | §4; `page_publications` |
| D14 | **Files**: 25 MB default; images, PDF, Office, text, Markdown, CSV, audio and video as files; HTML, SVG and executables refused; image thumbnails | §6; `attachments` |
| D15 | **K20 first** (the catalog seed), **K21** (the default installer line), K22, K23, HD1/P2 **Extended**, each in its own repository | §12 |
| D16 | **Ports** `APP_INTERNAL_PORT=8186`, `MCP_RECORDS_PORT=8833`, `MCP_ACTIVITY_PORT=8834` pinned before `apply`; **the Consultant Tracking division**: the planning model builds K20/K21, Phase 0's second half, Phase 1 for approval, Phase 2, slices 1–2 and **the two exemplars (3 the block editor, 4 channels)**; Sonnet 5.5 builds slices 5–9, Phase 4 and Phase 5 | §14; `CLAUDE.md` "Build order and the handoff" |

## 16. State

**PHASE 0, SECOND HALF — BUILT and proven 2026-10-05.** The schema `db/001`–`017` (the kernel contract copied from Consultant Tracking
with Spaces' appended columns; roles and rights; settings and the vocabularies; spaces with the department seeds; pages and the
permission tree — sharing additive, restricting explicit, the admin full everywhere; blocks with fractional positions, optimistic
versions, the structural rules, synced blocks, the child-page edges; databases with eighteen property types, two-way relations and
computed rollups; channels, messages, threads, DMs, reactions, pins, reminders; comments; notifications, the outbox, files and
agent dispatches on the estate's canonical shapes; the one search index; the read functions — tree, Markdown, rows, history,
unread, feed, sidebar, the wiki and the Librarian's questions; templates, versions, imports, exports and the worker's passes; the
`mcp_*` views) and `db/proof/phase0_proof.sql`: **281 checks green** on a scratch database — every actor of §2, the three space
kinds, a page tree resolved for seven actors with inherited, restricted and private permissions, blocks between/nested/moved/
split/merged, a stale save refused, a synced block read through its copy, every adopted block type rendered to Markdown, a
database with every property type, a relation and four rollups, a view's filter/sort/group, four channel kinds, a thread, reactions,
pins, unread, a tombstone, a mention dispatch and the agent's reply loop, comments resolved, snapshots and a restore, the trash and
its purge, retention, the wiki's expiry, search with every modifier, templates, favorites, the public door, notifications and the
outbox, files, and the views as the read roles. **The kit** copied from Consultant Tracking (`app/`, `html/`, `bin/`, `tests/`,
`deploy/`, `mcp/`; `ct_` → `sp_`; the gates rewritten to the permission tree) is proven without a kernel by `tests/phase0/run.sh`:
**18 checks green** (the fixture, a hand-off, replay, audience, unknown member, tampering, the refusals logged, the session listed,
the guest, General's membership, the department spaces, the sign-out notice). `maludb-os.json` (five endpoints, two shares, two
agents hired on install, 30 approval categories), `os/{expert,librarian}.md`, eight skills (four runbooks), `deploy/` templates and
`ROOT_STEPS.sh`; **the installer's `plan` reads the repository clean** (32 steps, 8 notes; the catalog row K20 found; every approval
covered). The shared-schema rule (§6.1) applied: every table marked read / reuse / new. Found on the way: the estate's
`attachments` needs an optional UUID record key (proposed to the catalogue); PostgreSQL evaluates an uncorrelated subquery
before a function beside it, so a proof that runs a pass and inspects it must do so in two statements.
**PHASE 1 — written 2026-10-05, for the checkpoint.** The tool surface (`docs/spaces-mcp-tool-surface.md`: **57 records tools** — `app_roles`,
`records_search` and the two K7 shares included — and **6 activity tools**; every question of §7 named to a tool; the resolve table of 12 entities;
the log-payload rules; the two share documents), the action manifest (`docs/spaces-action-manifest.md`: **58 screens, 118 actions in ten sections;
32 actions carry an approval category** — 8 `external_send` (publishing, rotating and revoking a public page, a guest on a page or a channel,
exporting a space, a channel or everything), 12 `deletion` (trashing and purging, deleting a database, a channel, a block, another's message or
comment, archiving a channel, restoring a version), 12 `other` (shouting, retention, a space's kind, archive, deletion and member removal, locking,
a database's schema, templates, the settings)), the registry built from it clean (`mcp/action_registry.json`, no unresolved endpoint, no prose
parameter), `maludb-os.json`'s `approvals[]` regenerated from the manifest by `bin/sync_approvals.php` (one source; `--check` in every proof), and
**ten specs** in `docs/build-specs/`: `sso-shell` (Phase 2), `spaces-core` (1, the CRUD exemplar for workers), `pages-tree` (2), **`block-editor` (3,
THE FIRST EXEMPLAR)**, **`channels` (4, THE SECOND EXEMPLAR)**, `databases` (5), `notify-search-wiki` (6), `agents-in-spaces` (7),
`worker-import-export` (8), `home-admin` (9). Every action and screen name in the specs matches the manifest (checked by script; every action
claimed by exactly one slice). Every "Open questions" section is empty. Decisions taken in the specs rather than asked: Phase 2's own-trail page is
`/trail` and `/activity` is the feed (the manifest says so); a member added to a space and a wiki page's new owner are told with the `share` kind
(db/012 has no kind of their own); the editor's presence is a file-backed cache, never a table; the dispatch loop (the worker calling the kernel's
chat endpoint) is slice 7's step, slice 4 proves its two halves through the SQL functions. **PHASE 1 APPROVED by the owner 2026-10-05** ("Continue with your original build process").
**PHASE 2 — BUILT and proven 2026-10-05** (`docs/build-specs/sso-shell.md`, "Built and proven"): the shell — three panes at 1280 (the
sidebar from one `sp_sidebar()` call with lazy page children, the main pane, the right pane `SP.rightPane` empty until slices 3 and 4), one
pane and the tab bar Home · Channels · Pages · Search · Me at 375, the header with the business name, the badge, my status and the bell,
the command bar through the kernel's chat endpoint (a `navigate` followed), presence once a minute plus the heartbeat, My settings with the
digest, away minutes, the status line (`status_set`) and the read-only time zone, the trail at `/trail`, the attachment door, the assets
and the brand, sixteen placeholders naming their slices; **312 checks green** under `php -S` and under a real Apache (sso 56, gates 110,
sync 33, ingest 12, kernel_compat 9, vhost 29, browser 63), the registry and approvals in step, Phase 0's 18 still green.
**SLICE 1 — spaces, membership and sections, THE CRUD EXEMPLAR FOR WORKERS — BUILT and proven 2026-10-05** (`docs/build-specs/spaces-core.md`,
"Built and proven"): 8 screens, 18 actions, the feature's four files on the kit's shape, **226 checks green** (`tests/phase3/slice1/run.sh`: seeds 19,
spaces 41, membership 56, sections 27, visibility 40, browser 43); `db/018` fixes the member guard on a space's delete cascade.
**SLICE 2 — pages: the tree, sharing, the wiki, the trash, templates, the public page — BUILT and proven 2026-10-05** (`docs/build-specs/pages-tree.md`,
"Built and proven"): the reader (`app/richtext/render.php`, every adopted block type), 12 screens, 22 actions, the public door `/p/{token}`, **271 checks green**
(`tests/phase3/slice2/run.sh`: make 20, read 30, move 28, trash 19, share 35, publish 28, wiki 29, json 44, browser 38).
**SLICE 3 — the block editor, THE FIRST EXEMPLAR — BUILT and proven 2026-10-05** (`docs/build-specs/block-editor.md`, "Built and proven"): the Markdown
converter and the editor mode of the one renderer, per-block optimistic saves with the version and the stale box (D7), Enter/Backspace/Tab/drag, the slash menu and
the pickers, uploads with thumbnails through the gated door (D14), synced blocks, tables, versions with the diff and restore, comments with one level of replies,
presence by file cache, the 13 actions, 1 screen and 21 controllers, **232 checks green** (`tests/phase3/slice3/run.sh`: convert 37, save 25, structure 24, widgets 26,
versions 19, comments 29, presence 12, json 27, browser 33). Next: slice 4, channels and messages — THE SECOND EXEMPLAR, then the handoff.

**Next: the owner's checkpoint; then Phase 2 (`sso-shell`), slices 1–2, the two exemplars (3, 4), the handoff.**

**2026-10-05 — the plan written and approved.** Notion and Slack researched (§0); the repository `maludb/maludb-os-spaces`
created in the org and cloned to `/srv/apps/spaces`; this document, `CLAUDE.md` and `README.md` committed; **the owner answered
all sixteen questions of §13 the same day, every recommendation taken (§15, D1–D16)**; the repository made **public** (D3).
**Next: K20 and K21 in the kernel, then Phase 0's second half.**
