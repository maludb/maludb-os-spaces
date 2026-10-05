<?php
declare(strict_types=1);

/** Messages (slice 4): the stream, a thread, one row — the SQL read functions decode to arrays; the hash of a row's mutable state for the poll. */

function decode_message(string $json): array
{
    $m = json_decode($json, true) ?: [];
    foreach (['message_id', 'channel_id', 'thread_root_id', 'author_member_id', 'reply_count', 'agent_run_id', 'attachment_count'] as $k) {
        $m[$k] = isset($m[$k]) ? (int) $m[$k] : null;
    }
    $m['body'] = is_array($m['body'] ?? null) ? $m['body'] : [];
    $m['reactions'] = is_array($m['reactions'] ?? null) ? $m['reactions'] : [];
    $m['attachments'] = is_array($m['attachments'] ?? null) ? $m['attachments'] : [];
    $m['repliers'] = is_array($m['repliers'] ?? null) ? array_map('intval', $m['repliers']) : [];
    return $m;
}

/** The channel's stream: 50 rows before a message (paging back), or after one (the poll). */
function channel_history(PDO $pdo, int $channelId, ?int $before = null, ?int $since = null, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT sp_channel_history(:c, :b, :s, :l)::text');
    $st->execute(['c' => $channelId, 'b' => $before, 's' => $since, 'l' => $limit]);
    return array_map('decode_message', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** The thread: the first message and every reply, in order. */
function thread(PDO $pdo, int $rootId): array
{
    $st = $pdo->prepare('SELECT sp_thread(:r)::text');
    $st->execute(['r' => $rootId]);
    return array_map('decode_message', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** One message the caller may see (sent or their own scheduled one), as sp_message_row() shapes it. */
function message_row(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT sp_message_row(m)::text FROM messages m WHERE m.id = :id AND m.channel_id IN (SELECT sp_visible_channel_ids()) AND (m.sent_at IS NOT NULL OR m.author_member_id = app_current_member_id())');
    $st->execute(['id' => $id]);
    $v = $st->fetchColumn();
    return $v === false ? null : decode_message((string) $v);
}

/** The base-table facts a writer needs. */
function message_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, channel_id, thread_root_id, author_member_id, kind, reply_count, edited_at, deleted_at, scheduled_for, sent_at, also_to_channel FROM messages WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** id → a hash of what may change on a shown row (edit, delete, replies, reactions, attachments): the poll's re-fetch key. */
function row_hashes(array $rows): array
{
    $out = [];
    foreach ($rows as $m) {
        $out[(string) $m['message_id']] = substr(md5(($m['edited_at'] ?? '') . '|' . ($m['deleted_at'] ?? '') . '|' . $m['reply_count'] . '|' . json_encode($m['reactions']) . '|' . $m['attachment_count'] . '|' . ($m['kind'] ?? '')), 0, 12);
    }
    return $out;
}

/** The last 50 rows' hashes beside the poll's new rows. */
function stream_hashes(PDO $pdo, int $channelId, ?int $threadRoot = null): array
{
    return row_hashes($threadRoot === null ? channel_history($pdo, $channelId, null, null, 50) : thread($pdo, $threadRoot));
}

/** The caller's unread facts for a channel: [unread, first_unread_id, mentions]. */
function channel_unread(PDO $pdo, int $channelId): array
{
    $st = $pdo->prepare('SELECT unread_count, first_unread_id, mention_count FROM sp_unread() WHERE channel_id = :c');
    $st->execute(['c' => $channelId]);
    $r = $st->fetch();
    return ['unread' => (int) ($r['unread_count'] ?? 0), 'first_unread_id' => isset($r['first_unread_id']) ? (int) $r['first_unread_id'] : null, 'mentions' => (int) ($r['mention_count'] ?? 0)];
}

/** My scheduled, unsent messages with their channel. */
function my_scheduled(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT sp_message_row(m)::text AS row, c.name AS channel_name, c.kind AS channel_kind FROM messages m JOIN channels c ON c.id = m.channel_id WHERE m.author_member_id = :m AND m.sent_at IS NULL AND m.scheduled_for IS NOT NULL AND m.deleted_at IS NULL ORDER BY m.scheduled_for');
    $st->execute(['m' => $memberId]);
    $out = [];
    foreach ($st->fetchAll() as $r) { $m = decode_message((string) $r['row']); $m['channel_name'] = $r['channel_name']; $m['channel_kind'] = $r['channel_kind']; $out[] = $m; }
    return $out;
}

/** The messages I saved, newest first, with their channel. */
function my_saved(PDO $pdo, int $memberId, int $limit = 100): array
{
    $st = $pdo->prepare('SELECT sp_message_row(m)::text AS row, c.name AS channel_name, c.kind AS channel_kind, s.created_at AS saved_at FROM saved_messages s JOIN messages m ON m.id = s.message_id JOIN channels c ON c.id = m.channel_id
                          WHERE s.member_id = :m AND m.channel_id IN (SELECT sp_visible_channel_ids()) ORDER BY s.created_at DESC LIMIT ' . max(1, min(500, $limit)));
    $st->execute(['m' => $memberId]);
    $out = [];
    foreach ($st->fetchAll() as $r) { $m = decode_message((string) $r['row']); $m['channel_name'] = $r['channel_name']; $m['channel_kind'] = $r['channel_kind']; $m['saved_at'] = $r['saved_at']; $out[] = $m; }
    return $out;
}

/** The composer's @ candidates for a channel: its people (a public one: the space's members), agents, departments, and the three shouts for a person. */
function channel_mention_candidates(PDO $pdo, int $channelId, string $q, bool $shouts): array
{
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $st = $pdo->prepare("SELECT m.member_id, m.display_name, m.is_agent, m.is_guest FROM mcp_members m
                          WHERE m.display_name ILIKE :q AND m.status = 'active' AND m.capability IS NOT NULL AND (sp_in_channel(:c, m.member_id) OR m.member_id IN (SELECT member_id FROM channel_members WHERE channel_id = :c))
                          ORDER BY m.is_agent, lower(m.display_name) LIMIT 12");
    $st->execute(['q' => $like, 'c' => $channelId]);
    $out = [];
    foreach ($st->fetchAll() as $m) { $out[] = ['id' => (string) $m['member_id'], 'name' => $m['display_name'], 'kind' => $m['is_agent'] ? 'agent' : 'member', 'hint' => $m['is_agent'] ? 'agent' : ($m['is_guest'] ? 'guest' : 'member')]; }
    $st = $pdo->prepare('SELECT department_id, name FROM mcp_departments WHERE archived_at IS NULL AND name ILIKE :q ORDER BY name LIMIT 6');
    $st->execute(['q' => $like]);
    foreach ($st->fetchAll() as $d) { $out[] = ['id' => (string) $d['department_id'], 'name' => $d['name'], 'kind' => 'department', 'hint' => 'department']; }
    if ($shouts) {
        foreach (['channel' => 'everyone in the channel', 'here' => 'everyone here now', 'everyone' => 'everyone in the space'] as $k => $hint) {
            if ($q === '' || str_contains($k, strtolower($q))) { $out[] = ['id' => '', 'name' => $k, 'kind' => $k, 'hint' => $hint]; }
        }
    }
    return $out;
}

function emoji_lookup(PDO $pdo, string $shortcode): ?string
{
    $v = one_value($pdo, 'SELECT emoji FROM mcp_emoji WHERE shortcode = :s', ['s' => trim($shortcode, ':')]);
    return $v === null || $v === false ? null : (string) $v;
}
