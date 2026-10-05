# Spaces — CLAUDE.md

This repository is the **Spaces** application of the MaluDB Business OS (`github.com/maludb/maludb-os-spaces`, public; local clone and
install path `/srv/apps/spaces`; catalog key `spaces`; DNS label `spaces`). The kernel is `/var/www` (`maludb-os-core`); read its
CLAUDE.md first — the kernel owns identity, the directory, the agents and approvals; this application owns the business's
**collaborative workspace**: spaces, pages built from blocks, wikis, databases with views, channels with threads, and the
OS's agents as members of all of it (Notion and Slack in one, for people and agents alike).

## Read first
1. `docs/spaces-design.md` — the plan (Phase 0): what it is and is not (§1), the actors (§2), roles, rights and the
   permission tree (§3), guests and the public page (§4), agents as members and what pauses (§5), the memory model (§6 —
   the tables, the block and rich-text formats, the property types), the question inventory with every tool named (§7),
   the kernel's part and the application's doors (§8), the screens (§9), the build order (§10), Extended (§11), what the
   kernel and siblings owe (§12), **the decisions the owner is asked for (§13)**, ports and files (§14), the owner's
   answers (§15, all sixteen given 2026-10-05 and recorded as D1–D16 — rules, not questions), the state (§16).
2. The plugin `maludb-os-integration` (`~/maludb-os-integration`, skill `os-integration` and its references:
   `memory.md`, `mcp-and-api.md`, `sign-on-and-directory.md`, `agents.md`, `roles-and-rights.md`, `sms-and-reads.md`,
   `registration.md`, `php-sign-on-kit.md`, `testing-without-a-kernel.md`) — how it fits.
3. `/srv/apps/helpdesk` and `/srv/apps/consultant_tracking` — the nearest **built** exemplars: the kit (`app/*.php`),
   `maludb-os.json`, `docs/build-specs/tickets-core.md` / `time-core.md` (the shape of a slice spec), `tests/phase0|phase2|
   phase3|phase4` (the shape of a proof suite), `deploy/` (templates and `ROOT_STEPS.sh`). `/srv/apps/consultant_tracking/docs/
   consultant-tracking-design.md` is the sibling plan in the same shape.
4. The kernel's retired specs — prior art: `docs/build-specs/documents.md` (pages and versions), `content.md`, `inbox.md`,
   `assistants-and-messaging.md` (agent messaging, the tree) in `/var/www`; the design says by name what it keeps and overturns.

## Rules that bind here
- `htmx-php-builder` skills govern the PHP (`php-patterns` before any PHP, `design-system` before any markup, `php-session-auth`
  for session hardening and CSRF, `mcp-servers` for the two read servers, `chat-actions` for the command bar, `new-screen` for
  every screen); `os-integration` governs the fit. Where the two conflict, the integration contract wins: **no password, no
  login form, no account, no model key, no Twilio key, no shared tables, no actions server of our own, no writes to the directory,
  no writes to another application** (K7 is reads only).
- **Vocabulary: "agent" means an OS AI agent**, always. A *member* is a kernel member mirrored here; a *guest* is an external
  member granted the Guest role; a *space* is a place (Notion's teamspace); a *page* is a tree of *blocks*; a *database* is a
  page whose children are rows with typed *properties*, shown through *views*; a *channel* is a conversation in a space; a *DM*
  is a conversation outside any space; a *thread* hangs off one message.
- **The checkpoint gate**: no feature PHP until the owner approves the schema + tool surface + action manifest + slice specs
  together (Phase 1). Schema changes after that are numbered additive migrations, each with sign-off. **Never modify a
  migration; add one.**
- **The database is the referee**: a block belongs to one parent and one page; a page's effective permission is resolved by
  one function; a private page is its owner's alone; a locked page refuses block writes; a published page has one token;
  a stale block save (version mismatch) is refused, never merged; a DM has exactly its two members; a thread's parent is a
  message of the same channel; retention deletes only what the policy names. PHP presents refusals as field errors.
- **One rich-text format** (design §6): blocks, messages, comments and `rich_text` properties all store the same JSON array
  of runs; **Markdown is the agents' language** — every tool that reads returns Markdown, every tool that writes takes Markdown,
  and the one converter (`app/richtext/`) is the only place the two meet.
- **Agents are members** (design §5): an agent reads what its member id may see — the same functions, no special case; it
  posts, edits its own messages, writes pages where it has edit; **publishing to the web, sharing to a guest, @channel and
  @everyone, deleting, emptying trash, exporting and changing retention pause** for an agent; a person doing the same is never
  paused.
- Every state-changing handler: `require_post()` + `verify_csrf()` (an action token stands in) + the right (`require_right()`,
  SQL `sp_has_right()`) + the record's permission (`sp_can_see_page()`, `sp_can_edit_page()`, `sp_in_channel()`) + `log_activity()`
  + `emit_action_status()`; a create's `location` ends in the record id. A message's body is never in a log payload; a page's
  text is never in a log payload (ids, titles and counts are).
- The kernel's rules copied here: activity `source` is `web` for the UI, `agent` under a run token, `cron` for the worker,
  `portal` for a public door; `action` is `entity.verb`; `mcp_*` views are the only thing the read roles see, with caller checks
  as uncorrelated sets tested once per statement (the kernel's db/160 lesson — the permission tree is resolved by ONE PL/pgSQL
  function per statement, never per row); `app.member_id` is set before any query; an unknown member id is refused, never created.
  **A record's history is the activity log** — except page content, which keeps **versions** (snapshots, never rewritten).
- Reads are **SQL functions and views** so a screen, a tool and an export never disagree: `sp_visible_page_ids()`,
  `sp_visible_channel_ids()`, `sp_page_markdown(id)`, `sp_search(q, filters)`, `sp_unread(member)`.
- Email is MaluMail (the application's own optional `MALUMAIL_API_KEY`); texts only through the kernel (K6); no model is ever
  called from PHP: "summarise this thread", "draft a page", "answer from the wiki" are the expert's, through the kernel's chat
  endpoint; an @mention of an agent is a dispatch the worker turns into one chat turn.
- **Every port env (`APP_INTERNAL_PORT`, `MCP_RECORDS_PORT`, `MCP_ACTIVITY_PORT`) is in `maludb-os.json` `env.required`** and
  pinned in `config/.env` before the installer's `apply` (8186 / 8833 / 8834 — design §13). **Never put a `config/.env` here
  before `apply`** except that three-line port pin (`deploy/ROOT_STEPS.sh` step 0); proofs read `$SP_DEV_ENV`.
- Phone first: a channel is read and answered at 375 px; a page is read at 375 px and edited at 1280 px first (the editor
  works on a phone, designed for a desktop); no modals (the slash menu and the mention picker are popovers inside the editor,
  not modals); full-page create/edit for records; cards for named things (spaces, pages in a list, databases' gallery view),
  tables for database table views and admin lists; every name a link, every page a way back.
- Commit after every finished step on `main`, in the sibling repos' message style (`Spaces: …`); never push unless the owner
  asks. Smokes and fixtures are named `SMOKE <run>`; proofs run on a scratch database and never touch the installed
  application; the installer's `plan` (`php /var/www/bin/app_install.php plan /srv/apps/spaces`) runs at the end of every phase.

## Build order and the handoff (decided 2026-10-05 — design D16)

The division General Ledger and Consultant Tracking used: a **planning-class model** builds what the database enforces, the
specs and the exemplars; a **worker model (Sonnet 5.5)** replicates every other slice from a spec, stopping and escalating on any
ambiguity rather than improvising. The handoff is a clean checkpoint with everything a worker needs in this repository — never
mid-slice.

**Before the handoff (planning-class model):**
1. **K20** in the kernel (`/var/www`): a migration seeding the `spaces` catalog row (Operations / `communication` /
   `feather-layers` / `high`), on the pattern of db/168; **K21** adds `spaces` to `bin/install_default_applications.sh` — Spaces is
   the fourth default install (D3).
2. **Phase 0, second half**: `db/001`–`0NN` — the mirror and roles (copied from Consultant Tracking, prefix `sp_`), settings,
   spaces and membership, pages and the permission tree, blocks and versions, databases, properties and views, channels,
   messages, threads and reactions, comments, mentions and notifications, favorites and recents, files, search, agents and
   dispatches, the public page, retention, tokens — with the referee rules as triggers; the read functions; `sp_has_right()` and
   the three permission functions; the `mcp_*` views; `db/proof/phase0_proof.sql` on a scratch database; the kit copied from
   Consultant Tracking and proven without a kernel (`tests/phase0/run.sh`); `maludb-os.json`; `os/{expert,librarian}.md`; the
   skills; `deploy/` with the vhost allow-list and `ROOT_STEPS.sh` pinning 8186/8833/8834; the installer's `plan` clean.
3. **Phase 1**: `docs/spaces-mcp-tool-surface.md`, `docs/spaces-action-manifest.md`, `mcp/action_registry.json`, and the specs in
   `docs/build-specs/` — `sso-shell`, then slices 1–9 of design §10, each in the shape of Consultant Tracking's `time-core.md`
   (screens, files, query-function signatures, handlers, manifest entries, log events, notifications, vocabulary, out of scope,
   proof, "Open questions" EMPTY). **The owner approves Phase 1 as a whole before any PHP.**
4. **Phase 2** (`sso-shell`), **slice 1** (spaces, membership, settings — the CRUD pattern), **slice 2** (pages: the tree, the
   sidebar, sharing and the permission tree, favorites, recents, trash, templates).
5. **Slice 3, the block editor — THE FIRST EXEMPLAR** and **slice 4, channels and messages — THE SECOND**: the two novel
   surfaces (a page being edited; a conversation being read live) that every later slice composes; each with its proof suite
   green at 375 and 1280.

**The handoff point** = the commit after slice 4 with design §16 saying "exemplars built; slices 5–9 open to workers".

**After the handoff (worker model — Sonnet 5.5):** slices 5 → 9 in order, one at a time, each on the exemplars' pattern from
its spec: screens + handlers + `log_activity()` + manifest entries + the tools' query functions, proven on a scratch database,
the spec's "Built and proven" and design §16 updated, one commit per slice. Then **Phase 4** (the two MCP servers over the same
query functions — `mcp/records_server.py`, `mcp/activity_server.py`, tool modules `mcp/sp_*.py`, own `mcp/venv`; `app_roles` and
the `shares[]` admitted to the kernel's token; the registry wrapper `deploy/kernel-registry-spaces.json`; the agents' grants and
the Librarian's duty — Help Desk's `tests/phase4/run.sh` is the pattern), and **Phase 5** with the owner (ports pinned, `apply` —
root, the owner runs it —, DNS and TLS for `spaces.<domain>`, the MaluMail key, the hires, the first spaces, the end-to-end proof
of design §10).

**A worker's rules:** read this file, design §3 (rights and the permission tree), §5 (what pauses), §6 (the tables and the two
formats — never modify a migration, add one), §15 (D1–D16) and the slice's spec before touching a file; reads through
`mcp_*` views and the read functions, writes to base tables after the gate; the database's refusal is shown as a field error,
never re-implemented in PHP; a message body or page text never in a payload; a question the spec does not answer stops the
slice and is written into the spec's "Open questions" for the owner — never guessed.

## Environment facts
- Install path `/srv/apps/spaces`; database `subello_spaces` on the office's PostgreSQL 17 (roles `spaces_rw`, `spaces_records_ro`,
  `spaces_activity_ro`) — not yet created.
- Vhost `spaces.subello.com`; ports to pin: `APP_INTERNAL_PORT=8186`, `MCP_RECORDS_PORT=8833`, `MCP_ACTIVITY_PORT=8834` (the block
  after Consultant Tracking's 8185/8831/8832).
- Proof scratch ports: 8401–8407 (app, fake kernel, fake MaluMail, the two MCP servers; the siblings use 8291–8297, 8301–8307,
  8391–8397).
- Nothing is installed yet; `plan` only, never `apply`, from this clone (the owner runs `apply`).
