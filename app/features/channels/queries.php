<?php
declare(strict_types=1);

/** Channels (slice 4, THE SECOND EXEMPLAR): reads through mcp_channels and the SQL read functions; nothing here re-decides what the database decides. */

const CHANNEL_SELECT = 'SELECT c.channel_id, c.space_id, s.name AS space_name, s.icon AS space_icon, c.kind, c.name, c.topic, c.purpose, c.is_default, c.created_by, c.archived_at, c.retention_days, c.last_message_at, c.message_count,
       c.created_at, c.updated_at, c.i_am_member, c.i_follow, c.starred, c.notify, c.muted_until, c.member_count,
       (SELECT cm.section FROM channel_members cm WHERE cm.channel_id = c.channel_id AND cm.member_id = app_current_member_id()) AS section,
       (SELECT cm.last_read_message_id FROM channel_members cm WHERE cm.channel_id = c.channel_id AND cm.member_id = app_current_member_id()) AS last_read_message_id,
       (SELECT count(*) FROM channel_pins p WHERE p.channel_id = c.channel_id) AS pin_count,
       (SELECT string_agg(m.display_name, \', \' ORDER BY m.display_name) FROM channel_members cm JOIN members m ON m.id = cm.member_id WHERE cm.channel_id = c.channel_id AND cm.member_id <> app_current_member_id()) AS other_names,
       (SELECT bool_or(m.member_kind = \'agent\') FROM channel_members cm JOIN members m ON m.id = cm.member_id WHERE cm.channel_id = c.channel_id) AS has_agent,
       s.kind AS space_kind, (SELECT sp_is_space_owner(c.space_id)) AS i_own_space
  FROM mcp_channels c LEFT JOIN mcp_spaces s ON s.space_id = c.space_id';

function channel_cast(array $c): array
{
    foreach (['channel_id', 'space_id', 'created_by', 'retention_days', 'message_count', 'member_count', 'last_read_message_id', 'pin_count'] as $k) {
        $c[$k] = $c[$k] === null ? null : (int) $c[$k];
    }
    foreach (['is_default', 'i_am_member', 'i_follow', 'starred', 'has_agent', 'i_own_space'] as $k) {
        $c[$k] = (bool) $c[$k];
    }
    $c['label'] = $c['name'] !== null ? '#' . $c['name'] : (string) ($c['other_names'] ?? 'Conversation');
    return $c;
}

/** Channels the caller may see: filters space, q, archived (include), kinds (default: public and private). */
function find_channels(PDO $pdo, array $filters = [], int $limit = 100): array
{
    $where = ["c.kind IN ('public', 'private')"];
    $args = [];
    if (empty($filters['include_archived'])) { $where[] = 'c.archived_at IS NULL'; }
    if (!empty($filters['space'])) { $where[] = 'c.space_id = :space'; $args['space'] = (int) $filters['space']; }
    if (($filters['q'] ?? '') !== '') { $where[] = '(c.name ILIKE :q OR c.topic ILIKE :q OR c.purpose ILIKE :q)'; $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%'; }
    $st = $pdo->prepare(CHANNEL_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY c.archived_at IS NOT NULL, lower(s.name), c.is_default DESC, c.name LIMIT ' . max(1, min(500, $limit)));
    $st->execute($args);
    return array_map('channel_cast', $st->fetchAll());
}

/** One channel (any kind) the caller may see, or null. */
function find_channel(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(CHANNEL_SELECT . ' WHERE c.channel_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : channel_cast($r);
}

/** The base-table facts a writer needs (not through the view: the writer may be an admin acting on an archived one). */
function channel_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, space_id, kind, name, topic, purpose, is_default, archived_at, retention_days FROM channels WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The channel's people (mcp_channel_members), agents marked, guests marked. */
function channel_members(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT cm.channel_id, cm.member_id, cm.display_name, cm.is_agent, cm.joined_at, cm.added_by, m.is_guest, m.is_active_now, m.job_title FROM mcp_channel_members cm JOIN mcp_members m ON m.member_id = cm.member_id WHERE cm.channel_id = :c ORDER BY cm.is_agent, lower(cm.display_name)');
    $st->execute(['c' => $id]);
    return array_map(static function (array $m): array { $m['member_id'] = (int) $m['member_id']; $m['is_agent'] = (bool) $m['is_agent']; $m['is_guest'] = (bool) $m['is_guest']; $m['is_active_now'] = (bool) $m['is_active_now']; return $m; }, $st->fetchAll());
}

/** The people one may add: admitted members of the space (a public/private channel) not yet in, agents too; guests apart. */
function channel_candidates(PDO $pdo, array $c): array
{
    if ($c['space_id'] === null) { return ['members' => [], 'guests' => []]; }
    $st = $pdo->prepare('SELECT m.member_id, m.display_name, m.is_agent, m.is_guest FROM mcp_members m WHERE m.status = \'active\' AND m.capability IS NOT NULL AND NOT m.is_guest
                           AND (m.member_id IN (SELECT sp_space_member_ids(:s)) OR m.is_agent) AND m.member_id NOT IN (SELECT member_id FROM channel_members WHERE channel_id = :c) ORDER BY m.is_agent, lower(m.display_name)');
    $st->execute(['s' => $c['space_id'], 'c' => $c['channel_id']]);
    $members = $st->fetchAll();
    $st = $pdo->prepare('SELECT m.member_id, m.display_name FROM mcp_members m WHERE m.is_guest AND m.status = \'active\' AND m.member_id NOT IN (SELECT member_id FROM channel_members WHERE channel_id = :c) ORDER BY lower(m.display_name)');
    $st->execute(['c' => $c['channel_id']]);
    return ['members' => $members, 'guests' => $st->fetchAll()];
}

function channel_pins(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT p.pin_id, p.channel_id, p.message_id, p.page_id::text AS page_id, p.pinned_by, p.created_at, (SELECT display_name FROM members WHERE id = p.pinned_by) AS pinned_by_name,
                                (SELECT plain_title FROM mcp_pages WHERE page_id = p.page_id) AS page_title, (SELECT left(plain_text, 200) FROM mcp_messages WHERE message_id = p.message_id) AS message_text,
                                (SELECT author_name FROM mcp_messages WHERE message_id = p.message_id) AS message_author
                           FROM mcp_channel_pins p WHERE p.channel_id = :c ORDER BY p.created_at DESC');
    $st->execute(['c' => $id]);
    return $st->fetchAll();
}

function channel_bookmarks(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT b.bookmark_id, b.channel_id, b.title, b.url, b.page_id::text AS page_id, b.emoji, b.position, b.created_by, b.created_at, (SELECT plain_title FROM mcp_pages WHERE page_id = b.page_id) AS page_title FROM mcp_channel_bookmarks b WHERE b.channel_id = :c ORDER BY b.position, b.bookmark_id');
    $st->execute(['c' => $id]);
    return $st->fetchAll();
}

/** The spaces the caller may make a channel in (member + channels.create), for the form. */
function spaces_for_channel_create(PDO $pdo): array
{
    return $pdo->query('SELECT space_id, name, icon FROM mcp_spaces WHERE i_am_member AND archived_at IS NULL ORDER BY is_default DESC, lower(name)')->fetchAll();
}

/** My DMs and group DMs (sp_my_dms()), with the agent flag per conversation. */
function my_dms(PDO $pdo): array
{
    $rows = $pdo->query('SELECT d.channel_id, d.kind, d.member_ids, d.names, d.last_message_at, d.last_line, d.unread_count,
                                EXISTS (SELECT 1 FROM channel_members cm JOIN members m ON m.id = cm.member_id AND m.member_kind = \'agent\' WHERE cm.channel_id = d.channel_id) AS has_agent
                           FROM sp_my_dms() d')->fetchAll();
    return array_map(static function (array $d): array { $d['channel_id'] = (int) $d['channel_id']; $d['unread_count'] = (int) $d['unread_count']; $d['has_agent'] = (bool) $d['has_agent']; $d['member_ids'] = array_map('intval', pg_text_array((string) $d['member_ids'])); return $d; }, $rows);
}

/** The people a DM may go to: everyone the caller may see but themselves (a guest: only those around them). */
function dm_candidates(PDO $pdo, int $me): array
{
    $st = $pdo->prepare('SELECT member_id, display_name, is_agent, is_guest, is_active_now FROM mcp_members WHERE member_id <> :me AND status = \'active\' AND capability IS NOT NULL AND member_id IN (SELECT sp_visible_member_ids()) ORDER BY is_agent, lower(display_name)');
    $st->execute(['me' => $me]);
    return array_map(static function (array $m): array { $m['member_id'] = (int) $m['member_id']; $m['is_agent'] = (bool) $m['is_agent']; $m['is_guest'] = (bool) $m['is_guest']; return $m; }, $st->fetchAll());
}

/** A dispatch running in this channel (the "thinking" state): [{agent_name, thread_root_id, pending_message_id}]. */
function running_dispatches(PDO $pdo, int $channelId): array
{
    $st = $pdo->prepare("SELECT d.dispatch_id, d.agent_name, d.conversation_id, d.status, d.run_id FROM mcp_agent_dispatches d WHERE d.channel_id = :c AND d.status = 'sent' AND d.run_id IS NOT NULL ORDER BY d.created_at DESC LIMIT 10");
    $st->execute(['c' => $channelId]);
    return $st->fetchAll();
}
