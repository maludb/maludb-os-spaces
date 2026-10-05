# maludb-os-spaces — Spaces

MaluDB Business OS application: **Spaces** — the business's collaborative workspace. Notion and Slack in one, for people
and for the OS's agents: **spaces** (a place per team or topic) holding **pages built from blocks** (paragraphs, headings,
lists, to-dos, toggles, callouts, code, tables, images, files, embeds, synced blocks, columns, links to other pages), a
**wiki** with owners and verification, **databases** (typed properties, table / board / gallery / list / calendar / timeline
views, filters, sorts, groups, relations and rollups, templates), and **channels** (public, private, direct and group
messages; threads, reactions, pins, saved items, mentions, scheduled messages, reminders) — with one search across all of it,
page history, comments and discussions, notifications, import and export, and a public page door for what the business
chooses to publish.

**OS agents are members.** An agent is @mentioned in a channel or DM'd and answers in the thread (one turn of the kernel's
chat endpoint); it reads pages as Markdown and writes pages from Markdown through the records and actions MCP servers;
the shipped **expert** answers "what did we decide about X" from the wiki and the channels, summarises a thread on request
and drafts pages; the shipped **Librarian** keeps the wiki honest (stale, orphaned, unverified pages; unanswered questions;
threads that should become pages). Publishing to the web, sharing to a guest, @channel, deleting and exporting pause for
an agent; a person is never paused.

A collaboration application installed beside [maludb-os-core](https://github.com/maludb/maludb-os-core) — **a default of every
install** (the fourth, with HR, Projects and Help Desk), granted to every insider. Built with `htmx-php-builder`, fitted to `maludb-os-integration`
0.6.0, served as `spaces.<domain>`; catalog key `spaces`.

- **The plan:** `docs/spaces-design.md` (Phase 0, 2026-10-05 — **approved**; the owner's sixteen decisions are §15, D1–D16).
- **The build:** `CLAUDE.md` — the order, the rules, the handoff to the worker model.
- Status: **Phase 0 complete 2026-10-05** — the schema (db/001–017, 281 proof checks), the kit (18 checks), `maludb-os.json`, the agents' job descriptions, eight skills, the deploy templates; the kernel's installer plan reads the repository clean. Phase 1 (the tool surface, the action manifest, the slice specs) next, for the owner's checkpoint.
