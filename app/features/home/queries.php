<?php
declare(strict_types=1);
/**
 * Home's regions (screen `home`, slice 9): each one is a function an earlier slice already owns — the unread counts (sp_unread, slice 4), the activity feed (slice 6), pages changed since (slice 2),
 * the wiki's status (slice 6), the Librarian's note (slice 7), the join requests (slice 1), the unanswered questions (slice 6), the admin's three counts. The SQL answers as the caller, so a region
 * shows what the database says the caller may see; PHP only narrows to "mine" and adds names. One query per region.
 *   unread           channels with unread messages, DMs first, the first unread line of each
 *   mentions         mentions and replies waiting since the member's previous visit (newest five)
 *   recent_pages     pages changed in the last 7 days in the member's spaces, their shared pages and their private ones (ten)
 *   favorites        the member's favorite pages
 *   verification_due wiki pages the member owns that expired or were never verified
 *   librarian_note   the Librarian's last message in the admin channel (null when the caller is not in that channel)
 *   pending_joins    (a space owner) requests to join their spaces
 *   unanswered       (a space owner) questions older than the setting with no reply in their spaces' channels
 *   admin            (the admin) dispatches pending or failed, published pages, the trash
 *   sidebar          sp_sidebar()
 */
require_once dirname(__DIR__) . '/activity/queries.php';
require_once dirname(__DIR__) . '/notify/queries.php';

/** The regions of the home for a member. $since = the previous visit's time (a timestamp), null the first time (then 7 days). */
function home_summary(PDO $pdo, int $memberId, ?string $since = null): array
{
    $sidebar = json_decode((string) one_value($pdo, 'SELECT sp_sidebar()::text'), true) ?: [];
    $mySpaces = array_map(static fn (array $s): int => (int) $s['space_id'], $sidebar['spaces'] ?? []);
    $ownedSpaces = array_map(static fn (array $s): int => (int) $s['space_id'], array_values(array_filter($sidebar['spaces'] ?? [], static fn (array $s): bool => !empty($s['is_owner']))));
    $isOwner = $ownedSpaces !== [] || has_right('space.manage');
    $isAdmin = has_right('settings.manage');
    $since ??= (new DateTimeImmutable('-7 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:sP');
    return [
        'unread' => home_unread($pdo),
        'mentions' => array_slice(activity_feed($pdo, $since, null, 5), 0, 5),
        'recent_pages' => home_recent_pages($pdo, $mySpaces, array_map(static fn (array $p): string => (string) $p['page_id'], array_merge($sidebar['shared'] ?? [], $sidebar['private'] ?? []))),
        'favorites' => find_favorites($pdo),
        'verification_due' => home_verification_due($pdo, $memberId),
        'librarian_note' => last_librarian_note($pdo),
        'pending_joins' => $isOwner ? pending_join_requests($pdo, $memberId) : null,
        'unanswered' => $isOwner ? home_unanswered($pdo, $ownedSpaces) : null,
        'admin' => $isAdmin ? admin_summary($pdo) : null,
        'since' => $since,
        'sidebar' => $sidebar,
        'may' => [
            'member' => has_right('spaces.join'), 'guest' => has_right('spaces.guest') && !has_right('spaces.join'),
            'owner' => $isOwner, 'admin' => $isAdmin,
        ],
    ];
}

/** Channels with unread messages (sp_unread ⨝ mcp_channels), DMs first, then the most recent; the first unread line from mcp_messages. [{channel_id, name, kind, space_id, space_name, unread, mentions, first_line, first_author, first_message_id, last_message_at}] */
function home_unread(PDO $pdo): array
{
    $rows = $pdo->query("SELECT u.channel_id, c.name, c.kind, c.space_id, (SELECT s.name FROM mcp_spaces s WHERE s.space_id = c.space_id) AS space_name, u.unread_count, u.mention_count, u.first_unread_id, u.last_message_at,
                                CASE WHEN c.kind IN ('dm', 'group_dm') THEN (SELECT string_agg(m.display_name, ', ' ORDER BY m.display_name) FROM mcp_channel_members cm JOIN mcp_members m ON m.member_id = cm.member_id WHERE cm.channel_id = c.channel_id AND cm.member_id <> app_current_member_id()) END AS dm_names,
                                (SELECT left(x.plain_text, 140) FROM mcp_messages x WHERE x.message_id = u.first_unread_id) AS first_line,
                                (SELECT x.author_name FROM mcp_messages x WHERE x.message_id = u.first_unread_id) AS first_author
                           FROM sp_unread() u JOIN mcp_channels c ON c.channel_id = u.channel_id
                          WHERE u.unread_count > 0 ORDER BY (c.kind IN ('dm', 'group_dm')) DESC, u.last_message_at DESC NULLS LAST LIMIT 12")->fetchAll();
    return array_map(static fn (array $r): array => [
        'channel_id' => (int) $r['channel_id'], 'name' => $r['dm_names'] ?? $r['name'], 'kind' => $r['kind'], 'space_id' => $r['space_id'] === null ? null : (int) $r['space_id'], 'space_name' => $r['space_name'],
        'unread' => (int) $r['unread_count'], 'mentions' => (int) $r['mention_count'], 'first_line' => $r['first_line'], 'first_author' => $r['first_author'],
        'first_message_id' => $r['first_unread_id'] === null ? null : (int) $r['first_unread_id'], 'last_message_at' => $r['last_message_at'],
    ], $rows);
}

/** Pages edited in the last 7 days that are in my spaces, shared with me or private to me (never a row of a database), ten. [{page_id, title, space_id, space_name, kind, last_edited_at, editor_name}] */
function home_recent_pages(PDO $pdo, array $mySpaceIds, array $sharedPageIds, int $limit = 10): array
{
    $st = $pdo->query("SELECT f.page_id::text AS page_id, f.title, f.space_id, (SELECT s.name FROM mcp_spaces s WHERE s.space_id = f.space_id) AS space_name, f.kind, f.last_edited_at, f.editor_name, f.is_row
                         FROM sp_pages_changed_since(now() - interval '7 days', NULL, 200) f WHERE NOT f.is_row");
    $mine = array_flip($mySpaceIds);
    $shared = array_flip($sharedPageIds);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        if (($r['space_id'] !== null && isset($mine[(int) $r['space_id']])) || $r['space_id'] === null || isset($shared[$r['page_id']])) {
            $out[] = ['page_id' => $r['page_id'], 'title' => $r['title'], 'space_id' => $r['space_id'] === null ? null : (int) $r['space_id'], 'space_name' => $r['space_name'], 'kind' => $r['kind'], 'last_edited_at' => $r['last_edited_at'], 'editor_name' => $r['editor_name']];
            if (count($out) >= $limit) { break; }
        }
    }
    return $out;
}

/** My favorites (mcp_page_favorites ⨝ mcp_pages), in my order. [{page_id, title, icon, kind, space_id, space_name}] */
function find_favorites(PDO $pdo): array
{
    $rows = $pdo->query('SELECT p.page_id::text AS page_id, p.plain_title AS title, p.icon, p.kind, p.space_id, (SELECT s.name FROM mcp_spaces s WHERE s.space_id = p.space_id) AS space_name
                           FROM mcp_page_favorites f JOIN mcp_pages p ON p.page_id = f.page_id WHERE p.archived_at IS NULL ORDER BY f.position, f.created_at LIMIT 30')->fetchAll();
    return array_map(static function (array $r): array { $r['space_id'] = $r['space_id'] === null ? null : (int) $r['space_id']; return $r; }, $rows);
}

/** Wiki pages I own whose verification expired or never happened (sp_wiki_status filtered to me). [{page_id, title, space_id, space_name, verification_state, verify_until}] */
function home_verification_due(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare("SELECT page_id::text AS page_id, title, space_id, space_name, verification_state, verify_until FROM sp_wiki_status(NULL)
                          WHERE wiki_owner_member_id = :m AND verification_state IN ('expired', 'none') ORDER BY CASE verification_state WHEN 'expired' THEN 0 ELSE 1 END, verify_until NULLS FIRST, lower(title) LIMIT 20");
    $st->execute(['m' => $memberId]);
    return array_map(static function (array $r): array { $r['space_id'] = (int) $r['space_id']; return $r; }, $st->fetchAll());
}

/**
 * The Librarian's last message in the admin channel (the channel named in the settings; an agent whose name or job title says Librarian) — through mcp_messages, so only a member of that channel
 * sees it. {message_id, channel_id, channel_name, author_name, excerpt, sent_at} or null.
 */
function last_librarian_note(PDO $pdo): ?array
{
    $r = $pdo->query("SELECT m.message_id, m.channel_id, c.name AS channel_name, m.author_name, left(m.plain_text, 400) AS excerpt, m.sent_at
                        FROM mcp_messages m JOIN mcp_channels c ON c.channel_id = m.channel_id
                        JOIN mcp_members a ON a.member_id = m.author_member_id
                       WHERE c.name = (SELECT admin_channel_name FROM mcp_settings) AND c.archived_at IS NULL AND c.i_follow AND m.author_is_agent AND m.thread_root_id IS NULL AND m.sent_at IS NOT NULL AND m.deleted_at IS NULL
                         AND (a.display_name ILIKE '%librarian%' OR COALESCE(a.job_title, '') ILIKE '%librarian%')
                       ORDER BY m.sent_at DESC, m.message_id DESC LIMIT 1")->fetch();
    if ($r === false) { return null; }
    $r['message_id'] = (int) $r['message_id'];
    $r['channel_id'] = (int) $r['channel_id'];
    return $r;
}

/** Pending requests to join the spaces I own (mcp_space_join_requests). [{join_request_id, space_id, space_name, member_id, display_name, message, created_at}] */
function pending_join_requests(PDO $pdo, int $ownerId): array
{
    $st = $pdo->prepare("SELECT r.join_request_id, r.space_id, (SELECT s.name FROM mcp_spaces s WHERE s.space_id = r.space_id) AS space_name, r.member_id, r.display_name, r.message, r.created_at
                           FROM mcp_space_join_requests r WHERE r.status = 'pending' AND r.member_id <> :me AND r.space_id IN (SELECT s.space_id FROM mcp_spaces s WHERE s.i_am_owner) ORDER BY r.created_at LIMIT 20");
    $st->execute(['me' => $ownerId]);
    return array_map(static function (array $r): array { foreach (['join_request_id', 'space_id', 'member_id'] as $k) { $r[$k] = (int) $r[$k]; } return $r; }, $st->fetchAll());
}

/** Unanswered questions in the channels of the spaces I own (sp_unanswered_questions). [{message_id, channel_id, channel_name, space_id, space_name, author_name, asked_at, excerpt, hours_open}] */
function home_unanswered(PDO $pdo, array $ownedSpaceIds): array
{
    if ($ownedSpaceIds === []) { return []; }
    $rows = $pdo->query('SELECT u.message_id, u.channel_id, u.channel_name, u.space_id, (SELECT s.name FROM mcp_spaces s WHERE s.space_id = u.space_id) AS space_name, u.author_name, u.asked_at, u.excerpt, u.hours_open
                           FROM sp_unanswered_questions() u WHERE u.space_id IN (' . implode(',', array_map('intval', $ownedSpaceIds)) . ') ORDER BY u.asked_at LIMIT 10')->fetchAll();
    return array_map(static function (array $r): array { foreach (['message_id', 'channel_id', 'space_id'] as $k) { $r[$k] = (int) $r[$k]; } $r['hours_open'] = (float) $r['hours_open']; return $r; }, $rows);
}

/** The admin's three cards: dispatches (pending, awaiting approval, failed), published pages (count, last opened), the trash (count, the next purge). */
function admin_summary(PDO $pdo): array
{
    $d = $pdo->query("SELECT count(*) FILTER (WHERE status = 'sent') AS pending, count(*) FILTER (WHERE status = 'awaiting_approval') AS awaiting, count(*) FILTER (WHERE status = 'failed') AS failed FROM mcp_agent_dispatches")->fetch();
    $p = $pdo->query('SELECT count(*) AS n, max(last_viewed_at) AS last_opened, COALESCE(sum(views), 0) AS views FROM mcp_page_publications WHERE revoked_at IS NULL')->fetch();
    $t = $pdo->query('SELECT count(*) AS n, min(purge_at) AS next_purge FROM mcp_trash')->fetch();
    return ['dispatches' => ['pending' => (int) $d['pending'], 'awaiting' => (int) $d['awaiting'], 'failed' => (int) $d['failed']],
            'published' => ['count' => (int) $p['n'], 'last_opened' => $p['last_opened'], 'views' => (int) $p['views']],
            'trash' => ['count' => (int) $t['n'], 'next_purge' => $t['next_purge']]];
}
