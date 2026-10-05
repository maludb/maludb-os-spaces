# Build spec: Phase 2 — sign-on, the mirror, the shell (before any slice)

What exists at the end: a person clicks Spaces on the launcher — or types `spaces.<domain>` on a phone — and lands, signed in, on a home
that looks finished though it holds no page yet: the sidebar lists their spaces and channels from `sp_sidebar()`, the bell, the command bar,
the bottom tab bar; the mirror refreshes every minute; every request is logged; the command bar answers through the kernel. The kit is
in the repo already (Phase 0: Consultant Tracking's identity, roles and sign-on files rewritten to the permission tree, the MCP common
files, the HTTP helper and sync) and is proven without a kernel (`tests/phase0/run.sh`, 18 checks); this phase wires the shell around it
and proves it.
Schema: `members` (with `status_text`, `status_emoji`, `status_until`, `last_seen_at`), `departments`, `department_members`,
`directory_sync_state`, `sso_nonces`, `member_sessions` (db/001), `activity_log`, `activity_ingest_state` (db/002), `mcp_access_tokens` +
`mcp_resolve_token()` (db/003), `sp_rights`, `sp_roles`, `sp_role_rights`, `sp_has_right()`, `sp_is_admin()`, `sp_is_guest()`,
`sp_is_member_here()`, `mcp_app_roles` (db/004), `sp_settings` (db/005), `spaces`, `space_members`, `sp_member_space_ids()` (db/006),
`sp_sidebar()`, `sp_my_dms()`, `sp_unread()` (db/015), `notifications`, `notification_prefs` (db/012), `mcp_members`, `mcp_departments`,
`mcp_settings`, `mcp_notifications`, `mcp_notification_prefs`, `mcp_access_tokens_mine`, `mcp_activity_log` (db/017). Never modify them.

## Files
**Already in the repo (Phase 0, proven):** `app/bootstrap.php` (env from `config/.env`, session cookie `SPSID`, `json_mode_begin()`, the acting
member on the PDO connection, the action-token / approval-replay path, the per-request mirror re-check), `app/db.php`, `app/http.php`,
`app/auth.php` (`has_right()`, `require_right()`, `is_sp_admin()`, `require_admin()`, `is_guest()`, `page_level()`, `can_see_page()`,
`can_edit_page()`, `require_page_level()`, `in_channel()`, `can_post()`, `require_channel()`, `require_can_post()`, `require_visible()`, the
verifiers), `app/directory.php` (the mirror appliers incl. `mirror_apply_roles()` and `access[]`), `app/activity.php` (`log_activity()` with
`space_id`, `channel_id`, `message_id`, `entity_uuid`), `app/handler.php` (`sp_handler_begin()`, `sp_guard()`, `sp_refuse_fields()`,
`sp_done()`, `req_val()`, `sp_int()`, `sp_ref()`, `sp_yes()`, `sp_diff()`), `app/partial_update.php`, `app/mail.php`, `app/api/bootstrap.php`,
`app/features/shell/nav.php` (the one menu table), `html/sso.php`, `html/sso/logout.php`, `html/api/v1/health.php`, `app/views/sso/refused.php`,
`bin/directory_sync.php` (`--full`, `--from-file`), `bin/mint_mcp_token.php`, `bin/build_action_registry.php`, `bin/sync_approvals.php`,
`bin/dev_handoff.php` + `bin/dev_directory.json`, `mcp/activity_ingest.py`, `tests/dev_router.php`, `tests/setup_dev.sh`, `deploy/*` templates,
and the Phase 0 placeholders `html/index.php`, `html/activity.php`, `html/notifications.php`, `html/settings/*`, `app/views/layout.php`.
**Phase 2 makes real:** `app/views/layout.php` (the nxl shell, three panes at 1280, one pane and the tab bar at 375),
`app/views/shared/{assistant-bar,tab-bar,flash,message,page-header,sidebar,right-pane}.php`, `app/views/home/dashboard.php`, `html/index.php`
(screen `home`), `html/login.php` (302 to `OS_LAUNCHER_URL?app=spaces`), `html/logout.php` (own sign-out: POST + CSRF, end this session, back to
the launcher), `html/notifications.php` + `html/settings/notifications/read.php`, `html/settings/index.php` (screen `settings`) +
`html/settings/prefs.php` + `html/settings/status.php`, `html/settings/tokens/{index,mint,revoke}.php`, `html/trail.php` (screen `trail`),
`html/assistant/ask.php` (the bar's POST → the kernel's chat endpoint), `html/presence.php` (POST, every request's `last_seen_at` is set by
the bootstrap; this endpoint is the explicit heartbeat the editor and a channel page call), `app/features/home/queries.php`,
`app/features/settings/{queries,present}.php`, `app/features/activity/{queries,present}.php`, `html/assets/` (the design system's
`examples/assets/` verbatim + `htmx.min.js` + `sortable.min.js`), `html/manifest.webmanifest` + two icons (installable on a phone; no service
worker, no push), `tests/phase2/` (the proofs: `servers.sh`, `lib.php`, `sso.php`, `sync.php`, `gates.php`, `ingest.php`, `vhost.php`, `browser.mjs`;
the fakes `tests/fake_kernel.php`, `tests/fake_maludb.php`, `tests/fake_malumail.php`; ports 8401–8407). Every other menu item of `nav.php`
answers a placeholder page saying which slice builds it (200 with an empty state, or 403 by the right); the registry builder reads a placeholder
as unbuilt (`render_nav_stub()` in `nav.php`).

## Spaces is not Consultant Tracking
- **Everyone is in.** The installer grants every standing department the Member role (D3); the base role's key is `user`; a guest is an external
  member holding `guest` and nothing else. The shell's badge shows the highest role held (Spaces admin › Space owner › Member › Guest).
- **The sidebar is data.** `sp_sidebar()` answers favorites, the spaces the person is in (sections → root pages, the channels they follow or that
  are the space's default or private), Shared with me, Private, DMs, and the open/closed spaces they could join — one call per page render,
  cached for the request. A guest's sidebar holds Shared with me and Direct messages only.
- **Two panes become three.** At 1280 px: the sidebar (280 px), the main pane, a right pane (`#right-pane`, 380 px, empty until slice 3 puts
  comments and slice 4 a thread in it). At 375 px: one pane, the bottom tab bar **Home · Channels · Pages · Search · Me**; the sidebar opens
  as an offcanvas from the header's menu button; the right pane is a full page.
- **Presence**: the bootstrap sets `members.last_seen_at = now()` at most once a minute on a signed-in request (one UPDATE, guarded by a
  session timestamp); `html/presence.php` is the heartbeat the editor and a channel page POST every 60 s while visible. "Active in the last
  5 minutes" is what `mcp_members.is_active_now` says.
- **Status**: `status_set` writes `status_text`, `status_emoji`, `status_until`; the header shows mine; the people picker shows everyone's (slice 4).
- **The public door is rewritten by the vhost already** (`/p/{token}`, its files and subpages); in Phase 2 its handler does not exist and the
  rewrites land on 404.

## The receiver (`/sso`) — in order (as the kit does it)
1. `verify_sso_token($token, APP_KEY)` and `verify_sso_claims($claims)` — constant-time, both must pass, member ids must match.
2. Expiry, audience (`spaces`), then the nonce: `INSERT INTO sso_nonces … ON CONFLICT DO NOTHING`; zero rows = replay → refuse.
3. In one transaction: `mirror_apply_member()` (the row with `capability`), `mirror_apply_roles()` from `claims.roles` (only keys `sp_roles` knows).
   The `members_admitted` trigger puts a new member in General (db/006).
4. Open the session: `session_regenerate_id(true)`, `$_SESSION['member_id']`, fresh CSRF token; `member_sessions` row.
5. `log_activity('member.sign_on', 'member', $id, ['after' => ['capability', 'roles']])`; redirect to `/`.
6. Any failure: one page `sso/refused.php` (*"This sign-on link has expired. Open Spaces from app.<domain> again."*), `member.sign_on.refused`
   with `after.reason` (token, claims, status, capability, replay, mirror) — never shown.

## Every request (bootstrap, as the kit does it)
- Cookie `SPSID`, `SameSite=Lax`, `Secure` when HTTPS, `HttpOnly`, strict mode. A signed-in request re-checks the mirror row and the session
  list; otherwise the session is destroyed (`ended_by = 'directory'`) and the visitor sent to the launcher with `?app=spaces`.
- Action token (`X-Action-Token` + `X-Action-Relay`, or `X-Approval-Replay`): acts as that member for one request; `Set-Cookie` removed; an id
  with no mirror row is refused and logged `member.refused`; a known, active agent with no capability is admitted at first contact only when the
  kernel's run-facts call vouches for it (`mcp_admit_agent` on the MCP path). `SET app.member_id` before any query; `log_screen_view()` on every
  rendered GET.
- `/sso/logout` (POST from the kernel): `verify_sso_logout_notice` → every session of the member ended (`ended_by = 'kernel'`); 204 always.

## The shell
- Design-system skeleton, exactly. **Phone first**: a channel and a page are read at 375 px; the editor is designed at 1280 px (slice 3). The
  header: the menu button (375), the business name, the command bar, the bell with its unread count (`mcp_notifications`), my avatar with my
  status line; `#page-content` is the HTMX target; CSRF meta + `htmx:configRequest` listener; `htmx:afterSwap` re-init; the layout re-stamps
  `data-screen`/`data-entity`/`data-record-id` from `X-Screen`/`X-Entity`/`X-Record-Id` (app/http.php).
- **The sidebar** (from 992 px, and the offcanvas on a phone): Home · Activity · Saved · Search, then **Favorites**, then each **space** (its icon
  and name; its sections and root pages as a tree with lazy children — `sp_page_children()` on expand; its channels with unread badges from
  `sp_unread()`), then **Shared with me**, **Private**, **Direct messages** (`sp_my_dms()` with unread counts), then the **Admin** group for
  `settings.manage`; "+ New page" at the top of each space for members with edit. Items are links (`hx-push-url` explicit); the current one
  highlighted; every count from one call.
- **The tab bar** at 375: Home (`/`), Channels (`/channels/`), Pages (`/pages/`), Search (`/search`), Me (`/settings/`).
- **The command bar** (`#assistant-bar`): dictation-friendly input with Send shown only while in use; POST `/assistant/ask` → the kernel's chat
  endpoint as the person (`kernel_call('POST', '/api/v1/agents/chat.php?agent=expert', ['utterance', 'screen', 'entity', 'record_id',
  'conversation_id'])`), the reply rendered in the bar's reply partial (`assistant-reply.php`), a `navigate` answer followed, a 202 polled
  (`GET ?run=`), the kernel down said in words. The expert's grants (maludb-os.json) decide what it can do; nothing here calls a model.
- **Home** (`home_summary()` — Phase 0 fixed the null-keyed shape; this phase renders every region's empty state naming its slice): unread
  channels (4), mentions and replies (6), recently edited pages (2), favorites (2), pages needing verification (6), the Librarian's note (7); a
  space owner's pending joins (1) and unanswered questions (6); the admin's dispatches, published pages, trash size (7, 2, 9).
- **The bell**, **My settings** (prefs, status, time zone — `prefs_save`, `status_set`), **Tokens** (`token_mint`, `token_revoke`), **Trail**
  (`trail`: my own rows, or a record's by `space`/`channel`/`message`/`page`) are real in this phase.
- Every screen: the page-header partial with Back (`?back=`), the flash region `#flash`, no modal anywhere; forms are pages; `hx-confirm` only on
  destructive controls.

## Query functions (signatures fixed)
- `home_summary(PDO, int $memberId): array` (the keys of §9 Home, null until their slice)
- `sidebar(PDO): array` (`SELECT sp_sidebar()` decoded; cached per request) · `unread_counts(PDO): array` (`sp_unread()`) · `bell_count(PDO, int $memberId): int`
- `my_prefs(PDO, int $memberId): array` · `save_prefs(PDO, int $memberId, array $fields): void` · `set_status(PDO, int $memberId, ?string $text, ?string $emoji, ?string $until): void`
- `find_my_notifications(PDO, int $memberId, int $page, bool $unreadOnly): array` · `mark_notifications_read(PDO, int $memberId, ?int $id): int`
- `my_tokens(PDO, int $memberId): array` · `mint_token(PDO, int $memberId, string $label, string $scope): string` · `revoke_token(PDO, int $memberId, int $id): void`
- `find_my_activity(PDO, int $memberId, int $pageNo, array $filters): array` · `find_record_activity(PDO, string $type, int|string $id, int $limit = 30): array` · `activity_sentence(array $row): string`
- `ask_assistant(PDO, int $memberId, string $utterance, array $context): array` (the kernel call; `{reply, actions, navigate, run_id, status}`)

## Handlers (every one: `sp_handler_begin()`; the gate; `sp_guard()`; `log_activity`; `sp_done()`)
- `settings/prefs.php` (`prefs_save`): own; `prefs.save` (`after`: the fields changed). `settings/status.php` (`status_set`): own; `status.set`
  (`after.text` ≤ 100, `emoji`, `until`). `settings/notifications/read.php` (`notification_read`): own; `notification.read` (`count`).
- `settings/tokens/mint.php` (`token_mint`): own, `require_human()` (an agent never mints); `token.mint` (`label`, `scope` — never the value; the raw
  token shown once). `settings/tokens/revoke.php` (`token_revoke`): own; `token.revoke`.
- `logout.php`: POST + CSRF; `end_session('member')`; `member.sign_out`; 302 to the launcher. `assistant/ask.php`: `require_human()`; `assistant.ask`
  (`length`, `screen` — never the words beyond 200 characters).

## Action manifest entries
Screens: `home`, `activity` (placeholder — slice 6), `saved` (placeholder — slice 4/6), `notifications`, `trail`, `settings`, `tokens`.
Actions (5): `prefs_save`, `status_set`, `notification_read`, `token_mint`, `token_revoke`. No agent approvals.

## Activity log events
`member.sign_on`, `member.sign_on.refused`, `member.sign_out`, `member.refused`, `directory.sync` (the timer), `prefs.save`, `status.set`,
`notification.read`, `token.mint`, `token.revoke`, `assistant.ask`, `screen.view`.

## Status vocabulary
Role badge: Spaces admin `danger`, Space owner `primary`, Member `secondary`, Guest `warning`. Presence dot `success` when active in 5 minutes.
Ids: `shell-sidebar`, `shell-tabbar`, `right-pane`, `assistant-bar`, `bell`, `bell-count`, `sidebar-space-{id}`, `sidebar-page-{uuid}`,
`sidebar-channel-{id}`, `home-{region}`.

## Out of scope for this phase
Every space, page, channel, database, search and admin screen (slices 1–9): their menu items are placeholders naming the slice.

## Proof (`tests/phase2/run.sh`: the scratch database `sp_dev2`, the fake kernel on 8402, php -S on 8401 and a real Apache serving the rendered
vhost when `SP_APP=apache`, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`; `sync_approvals --check`)
- [ ] **sso**: the Phase 0 checks again under the shell (hand-off 302 to `/`, replay 403, audience 403, unknown 403, tampered 403, every refusal logged, the
  session listed); the home renders for each fixture member with their badge; a guest's sidebar holds Shared and DMs only; the Watcher (unadmitted agent)
  is refused on the action-token path and logged `member.refused`.
- [ ] **gates**: every screen of the manifest × the five roles (admin, owner, member, guest, agent) → 200, 403 in words, or the placeholder; the admin
  group hidden from a Member; `require_human()` keeps an agent off tokens and the command bar.
- [ ] **sync**: the fake kernel's feed revokes Priya's grant → her next request ends her session within a minute (`ended_by directory`); a department
  delivered → its space seeded (db/006); a new member admitted → in General; roles changed → the badge changes.
- [ ] **ingest**: `mcp/activity_ingest.py` ships rows to the fake MaluDB with `"application": "spaces"` first and advances the checkpoint; a rejected row stops it.
- [ ] **kernel_compat**: the run-facts gate, a signed run token on the action-token path, the approval replay header, `/sso/logout` 204.
- [ ] **vhost**: the rendered `deploy/apache-spaces.conf` serves `/`, `/sso`, `/api/v1/health`, the MCP proxies (502 until Phase 4), `/p/<48hex>` → 404 (no handler yet), `/files/1` → 401.
- [ ] **browser** (375 × 740 and 1280 × 800, JavaScript off too): the three panes at 1280 and the tab bar at 375; the sidebar's spaces, sections and channels from
  the fixture; the offcanvas; the bell count; the command bar answers the fake kernel's reply and follows a `navigate`; My settings saves prefs and a status;
  a token minted and shown once, then revoked; the manifest.webmanifest installable; every control ≥ 44 px; `scrollWidth` = viewport; no console errors.

## Open questions
