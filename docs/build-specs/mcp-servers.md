# Phase 4 — the two MCP servers (spec and record)

2026-10-06 · built and proven by the worker model on the planning model's tool surface (`docs/spaces-mcp-tool-surface.md`, approved 2026-10-05). **Nothing here is installed:** the proofs run on a scratch
database (`sp_dev10`), ports 8404 / 8405 / 8410 and the fakes; Phase 5 (the owner's `apply`) installs the units.

## What was built

| Piece | Where |
|---|---|
| Records server — **62 tools** (the surface's table rows; its text says 57, a miscount: every named tool is built, none dropped) | `mcp/records_server.py`, tool modules `mcp/sp_spaces.py` (7), `sp_pages.py` (20), `sp_databases.py` (8), `sp_channels.py` (14), `sp_search.py` (3), `sp_agents.py` (3), `sp_misc.py` (4: `records_search`, `my_tokens`, `my_exports`, `my_notifications`), `sp_shares.py` (2) and `app_roles` in the server file |
| Activity server — **6 tools** | `mcp/activity_server.py` (`record_history`, `actor_timeline`, `recent_activity`, `who_touched`, `share_reads`, `activity_search`) |
| The door (copied from Consultant Tracking, `server_common.py` / `db.py`, kernel token widened to `app_roles` + the two shares) | `mcp/server_common.py`, `mcp/db.py` (`APP_INTERNAL_PORT` added to the env keys the servers read) |
| The two shares' internal door | `html/api/v1/shares/read.php` (signed with `ACTIONS_RELAY_KEY`, ±120 s) over `app/features/shares/queries.php` |
| Registry wrapper | `deploy/kernel-registry-spaces.json`: **12 resolve entities** (space, page, database, view, channel, message, member, department, template, proposal, version, export) |
| Units, venv, root's lines | `deploy/spaces-records-mcp.service`, `spaces-activity-mcp.service` (Phase 0's, unchanged), `mcp/venv` (gitignored; the installer's step `venv` now says done), `deploy/ROOT_STEPS.sh` step 5 |
| Proof | `tests/phase4/run.sh` (`gate`, `tools_pages`, `tools_databases`, `tools_channels`, `tools_search_agents`, `shares`, `tools_activity`, `registry`, `coverage`) |

The servers are **read-only**: every writing tool is an action of the kernel's Actions MCP, built from `mcp/action_registry.json` (the surface: "Writes are never here"). So no tool re-implements or POSTs a handler; the
kernel's tool factory does, with the approval hook in front, and the proof drives it with the kernel's own `application_actions.py`.

## The decisions (all recorded here; none overturns the surface)

1. **Tools read through the `mcp_*` views and the SQL read functions only** (`sp_page_markdown`, `sp_channel_history`, `sp_thread`, `sp_database_rows`, `sp_search`, `sp_wiki_status` …), as the caller (`app.member_id` set per call). A guest gets
   the same tools and a smaller world; four tools that are about the workspace and not about what was shared (`find_departments`, `get_settings`, `agents_here`, `librarian_proposals`) refuse a guest in words.
2. **Markdown is the agents' language.** A page, a row, a message, a comment and a version reach an agent as Markdown; the stored run arrays are dropped; a rich-text or title property is plain text; a person in a property is a name.
3. **The kernel's resolver contract.** Every resolve tool answers a **plain list** (`id_field`, `label_field`) when it is called with `q` alone: `find_spaces`, `find_pages`, `find_databases`, `find_channels`, `find_members`, `find_departments`,
   `find_templates`, `librarian_proposals`, `my_exports`, `page_versions` (the versions of the pages whose title matches; label "v3 — manual, 3 Oct 14:02 (Title)"), `channel_history` (a message by its words; label "Priya, 3 Oct: …"), `get_database`
   (a **view** by name across the databases the caller may see). **A UUID given as `q` is an id lookup** (`find_pages` — trash included, so `page_delete` can name a page `trash` listed —, `find_databases`, `get_database`, `find_templates`): the
   kernel's resolver treats only digit strings as ids, and Spaces' pages, databases and views are UUIDs an agent reads from `page_read` and passes back.
4. **`records_search` takes words, never SQL** (`q`, `kinds[]`: space, page, database, channel, member), as the surface says; there is no SQL escape hatch on either server (`activity_search` is a phrase over actions, titles and names).
5. **`page_tree`** builds a space's roots from `mcp_pages` and the children from `sp_page_children()`: `sp_sidebar()` lists only the caller's own spaces, and an open space one has not joined must be readable too.
6. **`channel_history`'s `since`** is a time or a message id; it returns the newest `limit` messages after it, oldest first (the SQL function's rule). **`record_history`** takes the newest `limit` rows and returns them oldest first; a page's history
   includes its blocks' and comments' events (through `after.page_id`); a database's its rows' (`after.database_id`). **`page_diff`** compares the two versions' Markdown (a snapshot carries the title heading, so the present page is read with it).
7. **`actor_timeline`**: an agent's trail to any member; a person's to themselves, to a space owner for the spaces they own (rows with that `space_id`), to the admin. **`who_touched`**: a space for its owners and the admin, a page for those who
   can edit it, a channel for those who may read it. **`share_reads`**: the admin's.
8. **The shares.** `pages_index` (`os.spaces-pages/1`) and `page_markdown` (`os.spaces-page/1`) are tools of the records server that ask the internal door to call the PHP functions in `app/features/shares/queries.php` — one truth, as Consultant
   Tracking built it. A person or an agent calling them reads as themselves. **The kernel's token** has no member: the share runs as the **requesting application's expert agent, named in `as_agent`** (its kernel member id), which must be an
   active agent the directory admitted; with no `as_agent` the index is empty (with a note) and a page is refused — "an application with no expert reads nothing". Both documents are people-free (a person in a row's properties is a name).
   The kernel's call logs `share.read` (source application, no actor; the tool, the argument KEYS, the rows, the agent) — never a title.
9. **`_partial`**: the `*_update` actions are marked `partial` in the registry; the kernel's tool sends `_partial=1` and `app/partial_update.php` fills the rest of the record from the base-table row. Proved over HTTP under an action token.
10. **The agents' grants and the Librarian's duty** are declared in `maludb-os.json` (Phase 0) and checked: every granted tool exists on the server it is granted on, every granted action exists in the registry, the Librarian is granted nothing that
    publishes, shares, shouts or deletes. The duty (`30 6 * * 1`) needs no server of ours; the installer does not read `agents[].duty` yet (ROOT_STEPS step 3 creates it in Agent HR).

## A defect of Phase 1's manifest, found and fixed here

The registry builder splits a manifest cell on commas, so a comma or semicolon inside a parameter's parentheses made **phantom parameters and lost the real ones**: `page_delete` had no `page`, `channel_create` no `name`, `channel_archive`
and `channel_delete` no `channel`, `thread_reply` no `message`, `database_delete` no `database`, `row_create` no `properties`, `import_start` no `file`, `database_property_save` no `options`, `settings_save` no `business_name`, and
`channel_member_add` a stray note — their kernel tools would have been unusable. Eleven manifest cells were reworded (no commas inside parentheses), the registry rebuilt (118 built, 32 approvals in step) and `tests/phase4/registry.php`
now fails on any phantom parameter and names the eight that mattered.

## What the kernel owes (found in Phase 4; nothing here waits on it)

- **K7 passes a provider no identity.** `application_read()` calls the provider's tool with the consumer's own arguments and nothing else — no consumer key, no acting agent. The surface (and design §8) assumed `X-Acting-Member`. Until the kernel
  injects the consumer's expert agent (and strips a consumer-supplied `as_agent`), the consumer names its agent itself, and a consumer could name any admitted agent. The right fix is the kernel's: bind the acting agent to the connection.
  The `share.read` log can likewise not name the consuming application (`application: kernel`).
- Installer: reading `agents[].duty` (the Librarian's schedule) — already an open note in ROOT_STEPS.

## Proof (the counts)

`bash tests/phase4/run.sh` — **443 checks green** on a fresh scratch database: gate 60 (tokens, the whole surface for people, each agent's grants, fail-closed, first contact, an eval run writes nothing, the kernel's token, `app_roles`), tools_pages 82,
tools_databases 35, tools_channels 45, tools_search_agents 42, shares 43, tools_activity 41, registry 91 (the kernel's own loader and resolver against the live server, whole actions through the kernel's tool with the hook stubbed, a real partial
update, the manifest's grants, approvals and skills), coverage 4 (every one of the 68 tools called at least once; every §7 question has a tool). Phase 2 re-run: **313 green**. The installer's `plan` is clean (the step `venv` is done; the ports
and `{{…}}` are the owner's step 0).
