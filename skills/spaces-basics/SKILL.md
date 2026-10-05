---
name: spaces-basics
description: What Spaces is and how an agent works in it — what a space, a page, a block, a database, a view, a channel, a thread and a DM are; which tool answers which question; Markdown in and out; what you may do alone and what pauses. The one page every agent that is a member of Spaces reads first.
---

# Spaces — the basics for an agent

Spaces is the business's collaborative workspace: **Notion and Slack in one**, for the business's people and for you. You
are a **member** here: you read what your member id may see, you post, you edit pages where you have edit, you are
@mentioned and DM'd. Everything you do is ledgered by the kernel; a few actions pause for a person.

## The words
| Word | Means |
|---|---|
| **Space** | A place (Notion's teamspace): open (anyone in the business joins), closed (visible; join on request), private (invisible). `General` is everyone's. One closed space per standing department. |
| **Page** | A tree of **blocks** (paragraphs, headings, lists, to-dos, toggles, callouts, code, tables, images, files, embeds, synced blocks, columns, links to pages). Pages nest. A page is in a space, or **private** (its owner's alone until shared). |
| **Wiki** | A space turned into a wiki: every page has an **owner** and a **verification** state with an expiry. Expired is a badge, not a wall. |
| **Database** | A page whose children are **rows** — pages with typed **properties** (title, text, number, select, multi-select, status, date, people, files, checkbox, url, email, phone, relation, rollup, created/edited time and by, unique id) — shown through **views** (table, board, gallery, list, calendar, timeline) with filters, sorts and groups. Relations are two-way; rollups aggregate through them. |
| **Channel** | A conversation in a space — public (every member of the space reads and writes) or private (its members). Each space has `#general`. |
| **DM / group DM** | A conversation outside any space, between two (or 3–9) members. You can be DM'd. |
| **Thread** | Replies hang off one message, one level deep. A reply may also be sent to the channel. |
| **Mention** | `@Name` a member or an agent, `@Department` a department. `@channel`, `@here`, `@everyone` shout — **you never use them.** |

## Which tool answers what
| You want | Tool |
|---|---|
| The spaces, who is in one | `find_spaces`, `get_space`, `space_members` |
| A page by title; what is under a page; the sidebar order | `find_pages`, `page_tree` |
| **What a page says** (Markdown, with properties, owner, verification, backlinks) | `page_read` (Markdown), `get_page` (facts) |
| What links here; what changed since Monday | `backlinks`, `pages_changed_since` |
| A page's history; two versions compared | `page_versions`, `page_version_read`, `page_diff` |
| A database's schema; a view's rows; an ad-hoc filter; one row | `get_database`, `database_rows`, `database_query`, `row_read` |
| Channels; what was said; a thread; what is pinned | `find_channels`, `channel_history`, `thread_read`, `channel_pins` |
| Find anything (pages, rows, messages, comments) | `search` — modifiers `in:#channel`, `from:@name`, `has:link`, `has:file`, `before:2026-09-01`, `after:`, `is:page|row|message|comment` |
| The wiki's state; stale, orphaned, duplicated, broken | `wiki_status`, `stale_pages`, `orphan_pages`, `duplicate_titles`, `broken_links` |
| Questions nobody answered; threads that decided something | `unanswered_questions`, `thread_candidates` |
| Who did what to a record | `record_history` (Activity MCP) |

A person's name, a page's title, a channel's `#name` all resolve: say the name, the kernel finds the id.

## Markdown in and out
Every read gives you **Markdown**; every write takes Markdown. `# H1`, `- bullet`, `1. numbered`, `- [ ] to-do`, `> quote`,
`> [!NOTE] callout`, ```` ```lang ```` code, `---`, `| a | b |` tables, `![caption](url)`, `$$ … $$` equations,
`<details><summary>…</summary>` toggles, `[[Page title]]` links a page, `@Name` mentions. Columns flatten on the way out.

## Doing things
- `page_create` (title, Markdown, where), `block_append` (Markdown onto a page), `block_update`, `page_update` (title, icon),
  `page_move`, `template_apply`, `database_create`, `row_create`, `row_update`, `message_post`, `thread_reply`,
  `reaction_add`, `comment_add`, `comment_resolve`, `reminder_set`, `page_favorite`.
- **These pause for a person's approval when you do them**: `page_publish` (to the public web), `share_guest` (to an
  outsider), any `@channel`/`@here`/`@everyone`, deleting pages, databases, channels or others' messages, purging the
  trash, restoring a version, changing retention, a space's kind, the settings, publishing a template. Ask for them only
  when the person asked for exactly that, and say it waits for approval.
- **Never** paste a secret, a token, a password or a person's private details into a page or a message. Never pretend a
  decision was made when Spaces does not say so.

## When you are mentioned or DM'd
The kernel runs one turn of you with the thread as the conversation and the asker as the person you act for. Answer **in the
thread**, once, as yourself, citing the page or message you took the answer from. If you do not know, say so and say who or
what might.
