# Spaces — MCP tool surface

2026-10-05 · **Phase 1, for the checkpoint.** The read tools every question in `docs/spaces-design.md` §7 (S1–S4, P1–P12, D1–D6,
C1–C12, Q1–Q2, G1–G3, ACT1–ACT5) is answered by. Writes are never here: they are the action tools the kernel's Actions MCP builds
from `docs/spaces-action-manifest.md`.

Conventions (mcp-and-api.md): Python 3 + FastMCP, streamable HTTP at `/mcp`, loopback, one systemd unit each; `db.py` and
`server_common.py` copied from Consultant Tracking (already in `mcp/`); every query runs with `app.member_id` set transaction-locally
from the verified bearer, so the `mcp_*` views (db/017) decide the rows and the read functions (db/014, db/015) answer as the caller.
Tool names are snake_case; every tool takes one `params` object; every list tool takes `limit` (1–100, default 25) and, where it
pages, `cursor`; every tool carries `readOnlyHint: true`, `openWorldHint: false`; results are JSON strings. **Every page, row, message
and comment reaches an agent as Markdown** (`sp_page_markdown`, `sp_rich_text_markdown`) beside its facts; ids are UUIDs for pages,
databases, views, rows, blocks and comments, integers for everything else; times are ISO 8601 with the zone.

Gates are what the view already enforces — the tool only trims. Words used below (db/004, db/007, db/010, the design §3):

| Word | Means |
|---|---|
| `member` | `sp_is_member_here()` — a Member, a Space owner, an admin or an agent granted Member (a guest sees only what is shared) |
| `view` / `comment` / `edit` / `full` | the caller's level on the page (`sp_page_level()`), the view having already filtered |
| `channel` | `sp_in_channel()` for a DM or a private channel; `sp_visible_channel_ids()` for a public one |
| `own` | the caller's own: notifications, reminders, saved, favorites, recents, tokens, scheduled messages |
| `owner` | `sp_is_space_owner()` |
| `admin` | `sp_is_admin()` — every space, private ones included; the admin lists |

**Permissions are the tree's**: a tool never shows a page the caller may not see, a row of a database they may not see, a message
of a channel they are not in. **A guest** (`sp_is_guest()`) gets the pages and channels shared with them and the people present in
them — every tool answers the same, smaller world. **An agent is a member** — the same functions, no special case; the run-facts
gate decides which tools it is offered.

Two token shapes, one key: a person's own `mcp_` token (`mcp_resolve_token`), or the tenant's signed run token (`ACTION_TOKEN_KEY`),
for which the server asks the kernel's run-facts call once per run and offers exactly the tools granted — fail closed; no tool acts when
`is_eval`. The kernel's own token reaches `app_roles` and the two `shares[]` tools (`pages_index`, `page_markdown`) and nothing else.

## Records server — `spaces_records_mcp` (role `spaces_records_ro`, `MCP_RECORDS_PORT`)

### Roles (kernel db/145)
`app_roles` — no arguments — answers `os.app-roles/1`: the four roles (key, name, description, capability, is_admin, rights[]) and
the 21 rights, from `mcp_app_roles` (db/004).

### Spaces and people (S1–S4)
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `find_spaces` | The spaces by name — mine, the open ones to join, the closed ones to request; `q` alone resolves a space. Each with its kind, whether I am a member or an owner, counts | S1 | `mcp_spaces` | `q?`, `kind?` (open, closed, private), `mine?` (yes), `include_archived?`, `limit` | member; a guest sees only spaces holding something shared |
| `get_space` | One space in full: description, levels, wiki setting, its default channel, its sections with root pages, its channels I may see, its member count | S1 | `mcp_spaces`, `mcp_space_sections`, `mcp_pages`, `mcp_channels` | `space` | member |
| `space_members` | Who is in a space and who owns it, agents marked; derived members (a department's) marked so | S2 | `mcp_space_members` | `space`, `q?`, `limit` | member |
| `find_members` | Who is who — members, agents, guests I may see — with status line and presence; `q` alone resolves a person or an agent by name | S3 | `mcp_members` | `q?`, `kind?` (human, agent), `space?` (members of that space), `limit` | member (names, kind, roles, status; no email but one's own) |
| `find_departments` | The departments as principals and `@handles`, each with its space when it has one | S3 | `mcp_departments` | `q?`, `include_archived?` | member |
| `get_settings` | The workspace's public settings: name, default kinds and levels, retention days, stale days, unanswered hours, verification default, the attachment limit, the admin channel | S4 | `mcp_settings` | — | member |
| `find_templates` | The templates: page templates and database templates, the workspace's and a space's; `q` alone resolves one | S4 | `mcp_pages` (`is_template`) | `q?`, `kind?` (page, database), `space?`, `limit` | member |

### Pages and the wiki (P1–P12)
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `page_tree` | The pages of a space in sidebar order (sections, root pages, children lazily), or the subpages under a page | P1 | `sp_sidebar()`, `sp_page_children()` | `space?` or `page?`, `depth?` (1–5, default 2) | member |
| `find_pages` | Pages by title words, in a space, under a parent, by kind, changed since; `q` alone resolves a page by title (newest edited first) | P1 | `mcp_pages`, `sp_search()` (`is: page`) | `q?`, `space?`, `parent?`, `kind?` (page, database, row), `changed_since?`, `limit` | member |
| `page_read` | **What a page says**: its body as Markdown, with its properties (a row), owner, verification, backlinks count, breadcrumb — the tool an agent reads with | P2 | `sp_page_markdown()`, `mcp_pages`, `sp_page_ancestors()` | `page`, `with_subpages?` (list them), `with_backlinks?` | view |
| `get_page` | A page's facts without the body: title, icon, where it sits, levels, lock, verification, versions count, published or not, last edit | P2 | `mcp_pages` | `page` | view |
| `page_blocks` | The block tree as JSON (ids, types, versions, content) — for an agent that edits one block (`block_update` needs the block id and its version) | P2 | `sp_page_tree()` | `page`, `parent?` (a block, for its children) | view |
| `backlinks` | What links to a page: pages (with the block), rows (a relation), messages that mention it | P3 | `sp_backlinks()` | `page`, `limit` | view |
| `pages_changed_since` | Pages edited since a time in my spaces (or one), newest first, who edited | P4 | `sp_pages_changed_since()` | `since`, `space?`, `limit` | member |
| `page_permissions` | Who may see or edit a page and why: the space's rule or the inherited explicit set, each principal with its level and where it comes from | P5 | `sp_page_permissions_explained()` | `page` | edit |
| `page_versions` | A page's versions: number, when, why, by whom | P6 | `mcp_page_versions` | `page`, `limit` | view |
| `page_version_read` | One version as Markdown, as it was | P6 | `sp_version_markdown()` | `version` | view |
| `page_diff` | Two versions (or a version and the present) compared, line by line, as a unified diff | P6 | `sp_version_markdown()`, `sp_page_markdown()` | `page`, `from_version`, `to_version?` (the present) | view |
| `wiki_status` | A wiki space's pages with owner and verification state: verified, expired, never; days since edit | P7 | `sp_wiki_status()` | `space?`, `state?`, `owner?`, `limit` | member |
| `stale_pages` | Pages not edited in N days, still in a sidebar | P8 | `sp_stale_pages()` | `days?`, `space?`, `limit` | member |
| `orphan_pages` | Pages nothing links to and no sidebar holds | P8 | `sp_orphan_pages()` | `space?`, `limit` | member |
| `duplicate_titles` | Pages that share a title, regardless of case | P8 | `sp_duplicate_titles()` | `space?`, `limit` | member |
| `broken_links` | Links to the trash or to nothing, with the page and block that carry them | P8 | `sp_broken_links()` | `space?`, `limit` | member |
| `my_favorites` | My favorites in order | P9 | `mcp_page_favorites`, `mcp_pages` | — | own |
| `my_recents` | The pages I opened recently | P9 | `mcp_page_recents`, `mcp_pages` | `limit` | own |
| `trash` | What is in the trash I may restore, and when each is purged | P10 | `mcp_trash` | `space?`, `limit` | full (the admin: all) |
| `published_pages` | The published pages: when, by whom, subpages, views, last opened — never the token | P11 | `mcp_page_publications`, `mcp_pages` | `limit` | admin, or full on the page |

### Databases (D1–D6)
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `find_databases` | The databases in my spaces with their row counts; `q` alone resolves one by title | D1 | `mcp_databases` | `q?`, `space?`, `limit` | member |
| `get_database` | A database's schema (every property with its type, options, relation target, rollup) and its views (layout, filter, sort, group); `q` with `part: views` resolves a view by name | D1 | `mcp_databases`, `mcp_database_views` | `database`, `q?`, `part?` | view |
| `database_rows` | The rows a view shows — its filter, sort and group applied in SQL — paged, each row's properties resolved (relations with titles, rollups computed, people with names) and its title | D2 | `sp_database_rows()` | `database` or `view`, `cursor?`, `limit` (≤ 100) | view |
| `database_query` | The rows matching an ad-hoc Notion filter object and sort, paged | D3 | `sp_database_rows()` | `database`, `filter` (JSON), `sort?` (JSON), `cursor?`, `limit` | view |
| `row_read` | One row as Markdown: its properties as a list (relations as `[[titles]]`, rollups computed) and its body | D4 | `sp_page_markdown()`, `sp_row_resolved()` | `row` | view |
| `my_rows` | Rows naming me in a `people` property, across my databases or one | D5 | `sp_database_rows()` over `mcp_databases` | `database?`, `property?`, `limit` | member |
| `rows_due` | Rows whose date property falls within N days (or is past), across my databases or one; by status | D5 | `sp_database_rows()` | `database?`, `property?`, `within_days?` (7), `overdue?`, `status?`, `limit` | member |
| `row_relations` | The rows related to a row through a property, with their titles | D6 | `mcp_row_relations`, `mcp_pages` | `row`, `property` | view |

### Channels and messages (C1–C12)
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `find_channels` | The channels of a space (or all mine): kind, topic, whether I follow it, unread, archived apart; `q` alone resolves `#name` | C1 | `mcp_channels` | `q?`, `space?`, `kind?`, `mine?`, `include_archived?`, `limit` | channel |
| `get_channel` | One channel: topic, purpose, members count, pins count, retention, my notify setting, the last message time | C1 | `mcp_channels` | `channel` | channel |
| `channel_history` | **What was said**: the channel's messages oldest first (threads collapsed with reply count and repliers), each as Markdown with author, time, reactions, attachments; `since` for "since Monday", `before` to page back | C2 | `sp_channel_history()` | `channel`, `since?` (a time or a message id), `before?`, `limit` (≤ 200) | channel |
| `thread_read` | A thread in order: the first message and every reply, as Markdown | C3 | `sp_thread()` | `message` (any message of the thread) | channel |
| `my_unread` | What is unread for me, by channel: the count, the first unread id, mentions among them; the DMs first | C4 | `sp_unread()`, `mcp_channels` | — | own |
| `my_activity` | Who mentioned me, replied to me, reacted to me, commented on my pages since a time | C5 | `sp_activity_feed()` | `since?` (7 days), `kind?`, `limit` | own |
| `my_saved` | The messages I saved, newest first, as Markdown with where they are | C6 | `mcp_saved_messages`, `mcp_messages` | `limit` | own |
| `my_reminders` | My reminders due and coming, with what each is about | C6 | `mcp_reminders` | `include_done?`, `limit` | own |
| `channel_pins` | What is pinned (messages, pages) and bookmarked in a channel | C7 | `mcp_channel_pins`, `mcp_channel_bookmarks` | `channel` | channel |
| `unanswered_questions` | Questions in public channels nobody answered within N hours | C8 | `sp_unanswered_questions()` | `hours?`, `channel?`, `space?`, `limit` | member |
| `thread_candidates` | Threads that decided something (decision words, many replies) with no page citing them | C9 | `sp_thread_candidates()` | `min_replies?` (8), `days?` (30), `space?`, `limit` | member |
| `my_scheduled` | My messages scheduled to go out, and when | C10 | `mcp_messages` (`sent_at IS NULL`, mine) | `limit` | own |
| `my_dms` | My direct and group messages with the people in them, the last line and the unread count | C12 | `sp_my_dms()` | `limit` | own |
| `channel_members` | Who is in a channel (a private one's members, a public one's followers), agents marked | C1 | `mcp_channel_members` | `channel`, `limit` | channel |

### Search and comments (Q1–Q2)
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `search` | **Find anything** — pages, rows, messages, comments — with Slack's modifiers as fields: `in` (a channel), `from` (a member), `has` (link or file), `before`, `after`, `is` (page, row, message, comment), `space`; the match marked in an excerpt; ranked | Q1 | `sp_search()` | `q`, `in?`, `from?`, `has?`, `before?`, `after?`, `is?`, `space?`, `cursor?`, `limit` | member (the view's world) |
| `page_comments` | A page's comments and discussions, open ones first, each as Markdown with its block | Q2 | `mcp_comments` | `page`, `open_only?`, `limit` | view |
| `my_open_discussions` | Unresolved discussions that mention me or sit on my pages | Q2 | `mcp_comments`, `mcp_pages` | `limit` | own |

### Agents (G1–G3)
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `agents_here` | The agents that are members: their spaces and channels, their last reply, pending dispatches | G1 | `mcp_members`, `mcp_space_members`, `mcp_channel_members`, `mcp_agent_dispatches` | `limit` | member |
| `agent_dispatches` | Dispatches to agents: pending, running, answered, failed — with the message, the asker, the run | G2 | `mcp_agent_dispatches` | `status?`, `agent?`, `channel?`, `limit` | admin (a member: the ones they caused or may read) |
| `librarian_proposals` | The Librarian's proposals and their fate | G3 | `mcp_librarian_proposals` | `status?`, `kind?`, `limit` | member |

### The long tail
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `records_search` | A guarded search across every view by words — the contract's one generic search: spaces, pages, databases, channels, members by name | — | the `mcp_*` views | `q`, `kinds?[]`, `limit` | member |
| `my_tokens` | My own MCP tokens (labels, scopes, last use) — never a value | — | `mcp_access_tokens_mine` | — | own |
| `my_exports` | My exports and whether each is still downloadable | — | `mcp_exports` | `limit` | own |
| `my_notifications` | The bell: my notices, unread first | — | `mcp_notifications` | `unread_only?`, `limit` | own |

### The shares — for the kernel's token alone (K7, design §8)
| Tool | Document | Answers |
|---|---|---|
| `pages_index` | `os.spaces-pages/1` | the pages the **requesting application's expert agent** may see (the kernel passes the agent's member id as `X-Acting-Member`; the share runs as it): `page_id`, `title`, `space`, `path` (the breadcrumb), `kind`, `last_edited_at`, `verification_state`; `q?` searches titles; paged |
| `page_markdown` | `os.spaces-page/1` | one page as Markdown with its properties and breadcrumb, as that agent may see it; `page` |

Both are people-free (no member identity crosses: authors are names, never ids beyond the acting agent's own view). An application
with no expert agent that is a member here reads nothing (the share answers an empty index), which is the contract's rule: a sibling
reads what one of its agents may read.

## Activity server — `spaces_activity_mcp` (role `spaces_activity_ro`, `MCP_ACTIVITY_PORT`)
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `record_history` | Who did what to a record, in order: a page (its edits block by block, shares, moves, locks, publication), a channel, a message, a space, a database | ACT1, P12 | `mcp_activity_log` | `entity_type`, `entity_id` or `entity_uuid`, `limit` | what the view admits (the record visible to the caller) |
| `actor_timeline` | What a person or an agent did here since a time — an agent's posts and edits this week | ACT2, C11 | `mcp_activity_log` | `member`, `since?`, `limit` | member (an agent's trail: any member; a person's: themselves, a space owner for their spaces, the admin) |
| `recent_activity` | What changed since I last looked, across what I may see | ACT3 | `mcp_activity_log` | `since`, `space?`, `limit` | member |
| `who_touched` | Everyone who acted in a space, a page or a channel in a period, with counts | ACT4 | `mcp_activity_log` | `space?` or `page?` or `channel?`, `since?`, `limit` | owner (a space), edit (a page), channel |
| `share_reads` | What sibling applications read of ours through the kernel, when (`share.read` events) | ACT4 | `mcp_activity_log` | `since?`, `limit` | admin |
| `activity_search` | The trail by a phrase in actions, titles and channel names | ACT5 | `mcp_activity_log` | `q`, `since?`, `limit` | member |

## Coverage of the question inventory (design §7 → tools)
| # | Tools |
|---|---|
| S1 | `find_spaces`, `get_space` · S2 `space_members` · S3 `find_members`, `find_departments` · S4 `get_settings`, `find_templates` |
| P1 | `page_tree`, `find_pages` · P2 `page_read`, `get_page`, `page_blocks` · P3 `backlinks` · P4 `pages_changed_since` · P5 `page_permissions` · P6 `page_versions`, `page_version_read`, `page_diff` · P7 `wiki_status` · P8 `stale_pages`, `orphan_pages`, `duplicate_titles`, `broken_links` · P9 `my_favorites`, `my_recents` · P10 `trash` · P11 `published_pages` · P12 `record_history` |
| D1 | `find_databases`, `get_database` · D2 `database_rows` · D3 `database_query` · D4 `row_read` · D5 `my_rows`, `rows_due` · D6 `row_relations` |
| C1 | `find_channels`, `get_channel`, `channel_members` · C2 `channel_history` · C3 `thread_read` · C4 `my_unread` · C5 `my_activity` · C6 `my_saved`, `my_reminders` · C7 `channel_pins` · C8 `unanswered_questions` · C9 `thread_candidates` · C10 `my_scheduled` · C11 `actor_timeline` · C12 `my_dms` |
| Q1 | `search` · Q2 `page_comments`, `my_open_discussions` |
| G1 | `agents_here` · G2 `agent_dispatches` · G3 `librarian_proposals` |
| ACT1 | `record_history` · ACT2 `actor_timeline` · ACT3 `recent_activity` · ACT4 `who_touched`, `share_reads` · ACT5 `activity_search` |

Every question is a named tool; the reads are the same SQL functions and views the screens call. **Records tools: 57** (including
`app_roles`, `records_search` and the two shares); **activity tools: 6**.

## What the log must carry for the activity tools (the manifest's log-payload rules)
Every row: `actor_member_id`, `source` (`web` · `agent` · `assistant` · `cron` · `portal` · `mcp`), `action` (`entity.verb`),
`entity_type` + `entity_id` (a space, channel, message, reminder, export …) or `entity_uuid` (a page, block, database, view, comment),
and the audit keys the record says: `space_id`, `channel_id`, `message_id`; `department_id` when a department is the principal.
- `space.*`: `after.name`, `kind`, `member_level`, `everyone_level`; `member_add`/`member_remove`: `member_id` or `department_id`, `role`.
- `page.create|update|move|duplicate`: `after.title` (plain), `space_id`, `parent_page_id`, `kind`; `move`: `before.parent_page_id`.
  `page.share|unshare|share_guest`: `principal_kind`, `principal_id`, `level`. `page.restrict|unrestrict|lock|verify|owner_set|favorite`: the fact.
  `page.publish|publish_rotate|unpublish`: `include_subpages`, `noindex` — **never the token**. `page.trash|restore|delete`: `subtree_count`.
  `page.version_save|version_restore`: `version_no`, `reason`. `page.public_view` (source `portal`): `publication_id`, `page_id` — never the token.
- `block.append|insert|update|move|delete`: `type`, `block_id`, `count` (append), `parent_block_id`, `version` — **never the text**.
- `database.*`: `after.title`, the property keys changed (`properties_added[]`, `properties_removed[]`, `properties_retyped[]`) — never values.
  `row.create|update|delete`: `database_id`, `title`, the property keys changed. `view.save|delete|reorder`: `name`, `layout`.
- `channel.*`: `after.name`, `kind`, `topic` (plain, ≤ 200), `retention_days`; `member_add`/`guest_add`/`member_remove`: `member_id`.
- `message.post|edit|delete|delete_own|announce|schedule|unschedule`: `message_id`, `thread_root_id`, `length`, `mentions` (kinds and
  ids), `has_attachments`, `scheduled_for` — **never the body**. `thread.reply` the same + `also_to_channel`. `message.react|unreact`:
  `emoji`. `message.save`, `channel.pin|unpin|bookmark_save|bookmark_delete`, `channel.read` (`message_id`), `channel.notify_set`.
- `comment.add|edit|resolve|delete`: `comment_id`, `block_id`, `parent_comment_id`, `length` — never the words.
- `attachment.add|delete`: `attachment_id`, `record_type`, `filename`, `mime_type`, `byte_size`.
- `agent.dispatch|reply|fail`: `dispatch_id`, `agent_member_id`, `kind`, `run_id`, `request_id`, `status`, `reply_length`.
  `librarian.propose|accept|dismiss`: `proposal_id`, `kind`, `subject`.
- `page.import`: `import_id`, `kind`, `pages_made`, `rows_made`, `unsupported`. `page.export|space.export|channel.export|export.all`:
  `export_id`, `kind`, `format`, `item_count`. `export.download`.
- `member.sign_on`, `member.sign_on.refused` (`reason`), `member.refused`, `directory.sync`, `prefs.save`, `status.set`, `token.mint|revoke`
  (`label`, never a value), `settings.save` (the fields changed), `space.admin_view` (`space_id`), `share.read` (`application`, `tool`), `screen.view`.

## Entity resolution for the action tools (the registry's `resolve` block — `deploy/kernel-registry-spaces.json`)
| Param(s) | Tool (with `q`) | id field | label |
|---|---|---|---|
| `space` | `find_spaces` | `space_id` | `name` |
| `page`, `parent`, `parent_page`, `proposed_page` | `find_pages` | `page_id` | `title` |
| `database`, `relation_database` | `find_databases` | `database_id` | `title` |
| `view` | `get_database` (with `part: views`) | `view_id` | `name` |
| `channel` | `find_channels` | `channel_id` | `name` (`#name`) |
| `message`, `thread`, `root` | `channel_history` | `message_id` | `excerpt` ("Priya, 3 Oct: Are we launching…") |
| `template` | `find_templates` | `page_id` | `title` |
| `member`, `to_member`, `person`, `owner`, `agent`, `guest` | `find_members` | `member_id` | `display_name` |
| `department` | `find_departments` | `department_id` | `name` |
| `proposal` | `librarian_proposals` | `proposal_id` | `title` |
| `version` | `page_versions` | `version_id` | `label` ("v3 — manual, 3 Oct 14:02") |
| `export` | `my_exports` | `export_id` | `label` |
| `block`, `comment`, `attachment`, `bookmark`, `reminder`, `section`, `request`, `dispatch`, `token`, `notification`, `shortcode` | **ids only** — never resolved by name (an agent reads them from `page_blocks`, `page_comments`, `channel_pins`, `my_reminders`, the screens) | | |

## The share documents (what a sibling reads through the kernel — K7, design §8)
Every share answers a versioned document: `{"schema": "os.spaces-<name>/1", "generated_at", "application": "spaces", "as_member":
<the acting agent's id>, "rows" | "page": …}`.
- `os.spaces-pages/1` rows: `page_id`, `title`, `kind` (page, database, row), `space` {`space_id`, `name`}, `path` (the breadcrumb titles),
  `last_edited_at`, `verification_state`, `is_wiki`; `q` searches titles; `cursor`/`limit` page; nothing of the body.
- `os.spaces-page/1`: `page_id`, `title`, `path`, `markdown` (the body as `page_read` gives it, ≤ 200 kB), `properties` (a row's, resolved),
  `last_edited_at`, `verification_state`.
- Reads this application makes: none in v1 (`reads: []`). The sibling cards (HD1 `ticket_card`, P2 `task_card`) are Extended.

## Size
57 records tools, 6 activity tools; the resolve block names 12 entities; the two shares. Phase 4 builds them (`mcp/records_server.py`
with `sp_spaces`, `sp_pages`, `sp_databases`, `sp_channels`, `sp_search`, `sp_agents`, `sp_misc`; `mcp/activity_server.py`) over the
same views and functions the screens read.
