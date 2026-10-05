---
name: keeping-the-wiki
description: The rules of a Spaces wiki — ownership, verification and expiry, stale, orphaned, duplicate and broken pages, and what an agent may and may not do about each. Use when reading wiki_status, stale_pages, orphan_pages, broken_links or duplicate_titles, or when asked whether a page is current.
---

# Keeping the wiki

A wiki space is a space where every page carries an **owner** and a **verification**. The wiki is honest when its pages are
verified recently by someone who knows, and when nothing points to the trash. The Librarian watches; people decide.

## The states
| State | Means | Who fixes it |
|---|---|---|
| **verified** | An owner confirmed the page on a date; it expires after the window (1, 3, 6 or 12 months; the space's default) | — |
| **expired** | The window passed. The page still shows (a badge, not a wall) | The owner re-reads and verifies, or hands it on |
| **none** | Never verified | The owner |
| **stale** | Not edited in N days (the setting, 90) though still in a sidebar | The owner decides: still true (verify), outdated (edit), finished (trash) |
| **orphan** | Nothing links to it and it is in no sidebar | A person links it from where it belongs, or trashes it |
| **duplicate** | Two pages with the same title | A person merges them; the loser becomes a link to the winner |
| **broken link** | A `[[page]]` that points to the trash or to nothing | The page's owner fixes or removes the link |
| **missing required properties** | A database row with an empty required property | The row's owner |

## What an agent does
- **Reads** them: `wiki_status`, `stale_pages`, `orphan_pages`, `duplicate_titles`, `broken_links`.
- **Tells** the owner once (a DM, a comment on the page) with the fact and the date: "This page's verification expired on
  2026-09-30."
- **Proposes**: "merge these two", "link this from Engineering › Runbooks", "this thread should be a page".
- **Verifies only pages it owns** — and it owns few. A page you drafted from a thread is owned by the person you named.

## What an agent never does
- Verify someone else's page. Verification is a person saying "I know this is true."
- Edit a page to make it look fresh. A stale page that is still true is verified, not touched.
- Trash, merge or move pages. Propose; a person does it.
- Nudge about the same page twice in a row. Once a week at most, once per page.

## Judging "is this current?"
Read the page (`page_read`). Check its verification and last edit (`get_page`). Check what links to it and what it links to
(`backlinks`). Search the channels for a newer decision (`search` with `after:`). Then answer: verified when, by whom, last
edited when, and whether anything newer contradicts it — with the thread cited if so.
