---
name: thread-to-page
description: Runbook for turning a channel thread that decided something into a draft page — read the thread, find the decision, draft from the Decision record template with the thread cited and the people named, propose it. Use when asked "make this a page", when a thread_candidate is accepted, or during the Monday report.
kind: runbook
---

# Thread to page

**Input**: a message id (the thread's first message) or a channel and a question. **Output**: a draft page, proposed — never
published, never shared.

## 1. Read the thread
`thread_read` on the root. Note: who asked, who answered, the sentence that decided ("we decided", "let's go with",
"agreed"), the date, anything left open. If nothing was decided, stop: say the thread discussed but did not decide, and ask
whether a Decision record "proposed" is wanted anyway.

## 2. Find where it belongs
`find_spaces` → the space the channel is in. `find_pages` for a "Decisions" page or a Decision log database in that space;
else the space's root. If a page on the same topic already exists (`search` by the title words), **do not make a second** —
propose adding a section to the existing page instead (`block_append`), citing the thread.

## 3. Draft it
`template_apply` (Decision record) under the parent found, titled with the decision in five to eight words ("Launch on
Monday 9am", not "Thread about launch"). Then `block_append` with Markdown:

```
> [!NOTE] 📌 Status: decided · Decided by: @Marco · Date: 2026-10-03 · Source: #launch (thread)

## Context
What was asked and why — two sentences from the thread.

## Decision
> "Yes — we decided: Monday 9am."  — Marco, #launch, 3 Oct

## Options considered
- …
## Consequences
- …
## Where it was discussed
The thread in #launch (the message is linked above). Open questions from the thread: …
```
Name the people with `@`; mention the root message so the page links back to the thread.

## 4. Propose
`proposal_make` (kind `thread_to_page`, the thread, the draft's page id, the title, one line why). In the thread, **one**
reply: "I drafted [[Launch on Monday 9am]] from this thread — Marco, would you verify it?" Do not post it to the channel.

## Never
Publish, share to a guest, mark the page verified, delete the thread's "also send to channel", or write a decision the thread
did not make.
