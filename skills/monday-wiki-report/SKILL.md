---
name: monday-wiki-report
description: Runbook for the Librarian's Monday duty — gather the wiki report and the channel report, write one note in #spaces-admin and to the admin's assistant, propose pages for decided threads, nudge each owner once. Run every Monday 06:30 or when a person asks for the report.
kind: runbook
---

# The Monday wiki report

**When**: every Monday 06:30 (the duty), or when a Spaces admin asks. **Who runs it**: the Knowledge Librarian.
**Takes**: about ten tool calls. **Writes**: one note, N proposals, one DM per owner with something to do.

## 1. Gather the wiki report
1. `wiki_status` (no space → every wiki space): split into **expired**, **never verified**, **verified**; by owner.
2. `stale_pages`: pages not edited in the setting's days. Drop any already in the expired list (one mention each).
3. `orphan_pages`, `broken_links`, `duplicate_titles`.
4. For databases that matter (`find_databases`), `database_rows` with a filter `is_empty` on each required property — rows
   missing them. Skip when none are required.

## 2. Gather the channel report
1. `unanswered_questions`: questions in public channels with no reply within the setting's hours.
2. `thread_candidates`: threads with 8+ replies, or decision words, with no page citing them. For each, `thread_read` and
   judge: did it decide something a person would look for later? If yes, it is a **proposal**.

## 3. Propose (only what deserves it)
For each candidate thread that decided something: `proposal_make` (kind `thread_to_page`, the thread, a title that names
the decision, one line why). When the admin's settings say drafts are wanted, also `page_create` a **draft** in the space's
Decisions page (or the space root), from the template Decision record, with the thread cited and the decider named — a
draft, never published, never shared.

## 4. Write the Monday note
One message in the admin channel (`get_settings` names it; default `#spaces-admin`) — `message_post`:

```
Monday wiki report — 6 Oct
Wiki: 3 expired (Priya 2, Marco 1) · 1 never verified · 2 stale · 1 orphan · 1 broken link · 0 duplicates
Channels: 2 unanswered questions · 1 thread that decided something without a page
Needs a hand this week:
- [[How we deploy]] — verification expired 30 Sep (Marco)
- [[Onboarding checklist]] — not edited in 120 days (Priya)
- #launch thread "Are we launching Monday?" — decided Monday 9am; proposed as a page (Launch date decision)
Questions nobody answered: "Does anyone know the wifi password?" (#general, 30 h)
```
Then the kernel's `message_send` to the Spaces admin's assistant with the same text.

## 5. Nudge each owner once
For each owner with something to do: `dm_open`, then one `message_post` listing their pages and what each needs (verify /
look / fix the link). **Once.** Do not repeat next week for the same page unless the admin asks you to.

## 6. Stop
Do not fix pages, verify, merge, trash or publish. Say in the note what you proposed; people decide.

## If something is off
- A tool answers an error: say so in the note ("stale_pages did not answer") and go on.
- Nothing needs a hand: post the short note anyway — "All verified, nothing stale, no open questions." People like to know.
