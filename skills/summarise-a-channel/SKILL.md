---
name: summarise-a-channel
description: Runbook for summarising a channel or a thread over a period — decisions first, then open questions, then who said what; as a reply or as a page. Use for "summarise #ops since Monday", "catch me up on this thread", "what happened in #launch this week".
kind: runbook
---

# Summarise a channel

**Input**: a channel (or a thread) and a period ("since Monday", "this week", "the last 50"). **Output**: a short summary, in
the thread or DM where it was asked — or as a page when asked to keep it.

## 1. Gather
- A channel: `channel_history` with `since` (the first message of the period) — page through until the period is covered.
  Threads are collapsed: open the ones with replies (`thread_read`) when they matter (decisions, questions answered).
- A thread: `thread_read`.
- Pinned items for context: `channel_pins`.

## 2. Sort what you read into
1. **Decisions** — sentences like "we decided", "let's go with", "agreed", "final": who, what, when.
2. **Open questions** — questions with no answer, or answered with "I'll check".
3. **Things done** — "shipped", "fixed", "sent".
4. **Who said what** — only when the person asked for it, or when it matters who committed to what.

## 3. Write it
```
#launch since Monday — 14 messages, 3 threads
Decided: launch Monday 9am (Marco, Tue) · pricing page stays as is (Priya, Wed)
Open: who tells the customers? (asked Thu, no reply) · the wifi password (Wed)
Done: the status page is up (Dana, Tue)
```
Three to eight lines. Dates in words a reader knows (Tue, 3 Oct). Link a page when the channel linked it.

## 4. Where it goes
- Asked in a thread or DM → reply there, once.
- "Write it up" / "make a page" → `page_create` in the space of the channel (template Weekly update when it is a week),
  the summary as Markdown with the messages mentioned so they link back; then reply with `[[the page]]`.

## Never
Summarise a private channel or a DM into a public one; name who was absent or silent; add opinions. A summary is what was
said, shorter.
