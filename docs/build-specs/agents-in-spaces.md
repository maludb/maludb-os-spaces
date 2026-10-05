# Build spec: agents in Spaces (slice 7)

What exists at the end: an agent is a member you can see, mention and DM — and it answers: a mention or a DM becomes a dispatch (the
trigger did that in Phase 0), the worker turns each into **one turn of the kernel's chat endpoint as that agent**, and the reply lands in
the thread as the agent, with "Seamus is thinking…" shown meanwhile; the Librarian's proposals are listed, accepted (the draft moved where
a person says) or dismissed; the admin sees the agents, their dispatches (and retries a failed one) and what sibling applications read of
ours. Nothing here calls a model: the kernel runs the agent; nothing here holds a key.
Schema: `agent_dispatches`, the trigger `messages_dispatch` (`sp_dispatch_from_message()`), `librarian_proposals`, `sp_notify()` (db/012);
`sp_dispatches_due()`, `sp_dispatch_record()`, `sp_thread_context()` (db/016, db/015); `messages.kind = agent_pending`, `agent_run_id`
(db/010); `sp_page_move()` (db/008); the views `mcp_members` (`is_agent`), `mcp_agent_dispatches`, `mcp_librarian_proposals`,
`mcp_space_members`, `mcp_channel_members`, `mcp_activity_log` (db/017). Never modify them. **The database is the referee**: which
message dispatches to which agent (a mention of an admitted agent in a channel it is in; a DM or group DM to it; never an agent's own words),
one dispatch per message and agent, the backoff and the five attempts, the placeholder and the reply as one row — the worker calls
`sp_dispatch_record()` and shows what it said; PHP never decides whether an agent should answer.

## Screens (375 px is the design for the thread; the admin pages usable at 375 px, designed at 1280 px)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `channel-view` / `dm-view` / `thread-view` | slice 4's | an agent in the people picker (`@` lists agents with a chip **agent**; `mcp_members.is_agent`); a DM to an agent shows **agent** in its header; a dispatched message shows, under it in the thread, the `agent_pending` row rendered as "<agent> is thinking…" (`message-pending-{id}`, the agent's avatar, a spinner) until the reply replaces it (the poll re-renders the thread); a reply shows the agent's name with the chip and, for `agents.settings`, a link to the run in the OS (`OS_LAUNCHER_URL`'s AI Ops) |
| `proposal-list` | `/proposals/?status=&kind=` | the Librarian's proposals as cards (`proposal-card-{id}`): kind chip, title, reason, the subject (the thread's first line → the thread; the page → the page), the draft when one was made (→ the page), proposed when; **Accept** (a `thread_to_page` one asks where: a parent page or a space root — a picker; the draft moves there) and **Dismiss** (a reason); accepted and dismissed under their tabs |
| `agent-list` | `/admin/agents` | the agents that are members (`mcp_members` where `is_agent`): name, roles here, the spaces and channels they are in (`mcp_space_members`, `mcp_channel_members`), their last reply (`mcp_agent_dispatches` answered, newest), pending and failed counts; a note that hiring, grants and duties are the kernel's, with links to the OS (`OS_LAUNCHER_URL`'s Agent HR) |
| `dispatch-list` | `/admin/dispatches?status=&agent=` | every dispatch (`mcp_agent_dispatches`): the agent, the asker, the kind, the channel, the message's first line, status chip, attempts, the run id, when, the reply's excerpt; **Retry** on a failed one |
| `connection-list` | `/admin/connections` | this application's `shares[]` (`pages_index`, `page_markdown` — from `maludb-os.json`, with what each answers) and the `share.read` rows of `mcp_activity_log` (which application read which tool, when, how many rows); a note that approving a connection is the super-admin's (`bin/app_connection.php` in the kernel — there is no API; the page links to the OS's application page) |

## The dispatch loop (the worker's step `dispatches`, `bin/worker.php` — slice 8 owns the worker; this slice owns the step)
1. `sp_dispatches_due(10)` — each: `dispatch_id`, `agent_member_id`, `acting_member_id`, `kind`, `channel_id`, `channel_kind`, `channel_name`,
   `message_id`, `thread_root_id`, `conversation_id`, `utterance` (Markdown), `context` (the last 12 turns as "Name: text"), `attempts`.
2. **The call** (`dispatch_agent()` in `app/features/agents/dispatch.php`): `kernel_call('POST', '/api/v1/agents/chat.php?agent=' . $agentId,
   ['utterance' => $utterance, 'conversation_id' => $conversationId, 'context' => $contextText, 'wait' => 25], ['X-Acting-Member: ' . $actingMemberId])`
   — the application token, the asker as the acting person (design §5, D10). `$contextText` = "You are <agent name>, a member of Spaces. You were
   <mentioned in #name of space X | sent a direct message>. The thread so far:\n<context>\nAnswer in this thread, once, as yourself, citing the
   page or message you took it from. Never write @channel, @here or @everyone." (the skill `talking-in-channels` says the rest).
3. **The answer**: 200 with `finished: true` → `sp_dispatch_record(id, 'answered', run_id, request_id, <reply as rich text>, NULL)` — the reply's
   Markdown through the converter (`markdown_to_rich_text()`, slice 3's `app/richtext/`), with every `@channel`/`@here`/`@everyone` run stripped
   and every `@Name` resolved to a mention run where the name is a member the agent may see; the placeholder row becomes the message
   (`kind = message`, `sent_at` now) — the trigger counts, notifies the asker (`agent_replied`), indexes it; the kernel's `cost` and `currency`
   go in the log. **202** (a run still going) → `sp_dispatch_record(id, 'running', run_id, request_id)` — the placeholder row is born ("thinking…"),
   and the next passes `GET /api/v1/agents/chat.php?run=<id>` until it finishes (`poll_running_dispatches()`); after 10 minutes → `failed`
   ("the run did not finish"). **A refusal** (403/409/422 from the kernel: the agent not hired, inactive, the application not granted, a bad
   conversation) → `refused` with the kernel's sentence; a `pending_approval` answer (the agent's action paused) → `awaiting_approval` with the
   `approval_request_id` in `detail` (the placeholder stays: "waiting for a person's approval"); unreachable or 5xx → `failed` with backoff
   (`sp_dispatch_record` doubles the wait; five attempts then failed for good — the placeholder removed, the asker told once: `sp_notify(asker,
   'agent_replied', '<agent> could not answer', detail)`).
4. Log `agent.dispatch` (sent), `agent.reply` (`run_id`, `request_id`, `reply_length`, `cost`), `agent.fail` (`attempts`, `detail`) — never the words.
5. `dispatch_retry` (the admin's action) resets a failed one to `sent`, `attempts` kept, `next_attempt_at` now.
**K8** (the kernel waking an agent instead of a chat turn) is owed: when it lands, step 2 becomes the wake call; nothing else changes.

## The Librarian's proposals
- **`proposal_make`** (an agent through the Actions MCP, or a person): kind, title, reason, the subject (`message` — a thread's first; or `page`),
  `proposed_page` (the draft the Librarian made with `page_create` first); one open proposal per subject (the index; a second → 422 "already proposed");
  the admin channel's members are told once (`sp_notify(... 'proposal' ...)` to the space's owners); log `librarian.propose`.
- **`proposal_accept`**: a `thread_to_page` one needs `parent` (a page or a space root the acceptor may edit): `sp_page_move(draft, parent)` and
  `status = accepted`; a `verify` one links to the page (the acceptor verifies there — slice 2's `page_verify`); `orphan`, `duplicate`, `broken_link`,
  `unanswered`, `stale` are marked accepted and the page or thread linked for the person to act; log `librarian.accept`.
- **`proposal_dismiss`**: `status = dismissed`, the reason kept; log `librarian.dismiss`. The Librarian reads `librarian_proposals` and does not
  propose again what was dismissed within 90 days (the skill says so; the function `recently_dismissed()` serves the tool).
- The Librarian's **duty** (Monday 06:30) is the kernel's cron of the agent — nothing here but the skills (`monday-wiki-report`, `thread-to-page`)
  and the tools (the Phase 1 surface; Phase 4 builds them).

## The two shares
`pages_index` and `page_markdown` are Phase 4's tools (the kernel's token alone, run as the requesting application's expert agent); this slice
writes nothing for them but the `connection-list` page that shows their `share.read` rows.

## Files (exactly these)
- `app/features/agents/{dispatch,queries,present}.php` (`dispatch_agent()`, `poll_running_dispatches()`, `dispatches_pass()`, the pages' queries) · `app/features/proposals/{queries,present,write}.php`
- `html/proposals/index.php` · `save.php` · `accept.php` · `dismiss.php` · `html/admin/agents.php` · `html/admin/dispatches.php` · `html/admin/dispatches/retry.php` · `html/admin/connections.php`
- `app/views/proposals/{index,partials/card,partials/accept-form}.php` · `app/views/admin/{agents,dispatches,connections,partials/dispatch-row}.php` · `app/views/channels/partials/message-pending.php` (slice 4's thread partial renders it)
- `tests/fake_kernel.php`: `/api/v1/agents/chat.php` answering 200 (a scripted reply with `@channel` in it), 202 then finished on the next GET, 403, `pending_approval`, and 500

## Query functions (signatures fixed)
- `dispatches_pass(PDO, DateTimeImmutable $now, int $limit): array` (`['called', 'answered', 'running', 'refused', 'failed', 'polled']`) · `dispatch_agent(PDO, array $d): array` · `poll_running_dispatches(PDO, DateTimeImmutable $now): array` · `dispatch_context(array $d): string` · `reply_to_rich_text(PDO, string $markdown, int $agentId): array`
- `agents_here(PDO): array` · `find_dispatches(PDO, array $f, int $page): array` · `retry_dispatch(PDO, int $id, int $by): void` · `share_reads(PDO, int $limit): array` · `declared_shares(): array` (from `maludb-os.json`)
- `find_proposals(PDO, array $f): array` · `make_proposal(PDO, array $fields, int $by): int` · `accept_proposal(PDO, int $id, ?string $parentUuid, int $by): array` · `dismiss_proposal(PDO, int $id, ?string $reason, int $by): void` · `recently_dismissed(PDO, int $days): array`

## Handlers (every one: `sp_handler_begin()`; the gate; `sp_guard()`; `log_activity`; `sp_done()`)
- `proposals/save.php` (`proposal_make`): `sp_is_member_here()`; `librarian.propose` (`proposal_id`, `kind`, `subject`). `accept.php` (`proposal_accept`): member with `require_page_level(parent, 'edit')` when a parent is given; `librarian.accept`. `dismiss.php`: member; `librarian.dismiss`. `HX-Trigger: proposalChanged`.
- `admin/dispatches/retry.php` (`dispatch_retry`): `require_right('agents.settings')`; `agent.dispatch` (`after.retry = true`); `HX-Trigger: dispatchChanged`.
- The admin pages: `require_right('agents.settings')` (`agent-list`, `dispatch-list`, `connection-list`); `log_screen_view()`.

## Action manifest entries
Screens: `proposal-list`, `agent-list`, `dispatch-list`, `connection-list`. Actions (4): `proposal_make`, `proposal_accept`, `proposal_dismiss`, `dispatch_retry`. Agent approvals: none (the Librarian proposes and drafts alone — D11). `PARTIAL_UPDATE_TARGETS` gains nothing.

## Activity log events
`agent.dispatch` (cron or the retry; `dispatch_id`, `agent_member_id`, `kind`, `channel_id`, `message_id`), `agent.reply` (cron; `run_id`, `request_id`, `reply_length`, `cost`, `currency`), `agent.fail` (cron; `attempts`, `detail` ≤ 200), `librarian.propose|accept|dismiss` (`proposal_id`, `kind`, `subject`, `proposed_page` as `entity_uuid`), `share.read` (written by Phase 4's server), `screen.view`. **An utterance, a reply or a context is never in a payload.**

## Notifications this slice queues (the sender is slice 8)
| Step | Who is told | Kind |
|---|---|---|
| an agent replied | the asker (`sp_dispatch_record` does it) | `agent_replied` |
| an agent could not answer after five attempts | the asker | `agent_replied` (the detail) |
| a proposal is made | the subject space's owners | `proposal` |

## Status vocabulary
Dispatch status chips: sent `secondary` ("pending" when `run_id` is null, "running" when set), answered `success`, awaiting_approval `warning`, refused `dark`, failed `danger`. Proposal kinds: thread_to_page `feather-file-plus`, verify `feather-check-circle`, orphan `feather-link-2`, duplicate `feather-copy`, broken_link `feather-alert-triangle`, unanswered `feather-help-circle`, stale `feather-clock`; status proposed `info`, accepted `success`, dismissed `secondary`. The thinking placeholder: a muted row with a spinner, `aria-live="polite"`. Ids: `message-pending-{id}`, `proposal-card-{id}`, `proposal-accept-{id}`, `agent-row-{id}`, `dispatch-row-{id}`, `dispatch-list`, `share-reads`.

## Out of scope for this slice
The worker's other steps and the sender (slice 8); the MCP servers and the two shares' tools (Phase 4); K8 wakes and chat attachments (K22) — owed; the Librarian's duty itself (the kernel's); a person's assistant's tree (the kernel's — a DM to Seamus is the same chat turn, `?agent=` Seamus's id).

## Proof (`tests/phase3/slice7/run.sh`: the scratch database `sp_dev7`, the fake kernel's chat endpoint in each of its states, `SP_WORKER_NOW`, curl with signed action and run tokens, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
The world (`tests/phase3/slice7/lib.php` `agents_world()`): Seamus (agent, Member, in General and `#launch`), the Librarian (agent), Watcher (an agent not admitted); Priya, Marco (owner of Product), the admin.
- [ ] **The picker**: `@` lists Seamus with the agent chip and not Watcher; a DM to Seamus shows the chip in its header.
- [ ] **A mention**: Priya mentions Seamus in `#launch` → one dispatch (kind mention, acting Priya, the thread as conversation); the worker's pass calls the fake kernel with `?agent=<Seamus>`, `X-Acting-Member: <Priya>`, the utterance, the conversation id, the context holding the thread's lines and the "never @channel" sentence; the fake's 200 reply (with `@channel` in it) lands in the thread as Seamus with `@channel` stripped and `@Priya` as a mention run, `agent_run_id` set, `agent.reply` logged with the cost and no words; Priya told `agent_replied`; a second pass dispatches nothing.
- [ ] **202 then done**: the fake answers 202 → the placeholder "Seamus is thinking…" in the thread (JSON and page), `running` with the run id; the next pass GETs the run → finished → the placeholder becomes the reply; the thread poll shows the change.
- [ ] **A DM**: Priya DMs Seamus → kind dm; answered in the DM. **A group DM** with two agents → two dispatches, two replies.
- [ ] **Refused and failed**: the fake's 403 → `refused` with its sentence, no placeholder; a 500 → `sent` with `attempts 2` and `next_attempt_at` ahead, the pass skips it until due; after five → `failed`, the placeholder gone, Priya told once; **Retry** by the admin → `sent` and answered on the next pass; `pending_approval` → `awaiting_approval` with the request id in the detail and the placeholder saying so.
- [ ] **No loops**: Seamus's reply mentioning the Librarian dispatches nothing; a scheduled message mentioning Seamus dispatches when sent, not before; a tombstoned message is skipped.
- [ ] **Proposals**: the Librarian (run token + relay through the Actions MCP path) makes a `thread_to_page` proposal with a draft it created; a second for the same thread → 422; the space owners told; Marco accepts naming Product › Decisions → the draft moved there (`sp_page_move`), accepted; Priya dismisses another with a reason; `recently_dismissed` lists it; the list's tabs and filters; a member of another space sees only proposals on what they may see.
- [ ] **Admin pages**: agents with their spaces, channels, last reply and counts; dispatches filtered by status and agent with the excerpts; connections showing the two shares and a `share.read` row the fixture wrote; a Member → 403 in words.
- [ ] **375 × 740 and 1280 × 800**: the placeholder and the reply in the thread on the phone; the proposal cards; the admin tables as cards; every control ≥ 44 px, `scrollWidth` = viewport, no console errors.

## Open questions
