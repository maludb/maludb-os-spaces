<?php
declare(strict_types=1);

/**
 * The bell and the people's feeds (slice 6): the caller's notices through mcp_notifications (their own rows only — the view), the Activity feed through
 * sp_activity_feed(), saved messages and reminders through slice 4's readers. Nothing here decides who is told: sp_notify() and the db/012 triggers did.
 */
require_once dirname(__DIR__) . '/messages/queries.php';
require_once dirname(__DIR__) . '/reminders/queries.php';

const NOTIFICATIONS_PAGE = 50;

/** My notices, newest first (the unread ones first on the "everything" list), 50 a page, with the channel's kind and the actor's name beside them. */
function find_my_notifications(PDO $pdo, int $memberId, bool $unreadOnly = false, int $page = 1): array
{
    $st = $pdo->prepare('SELECT n.notification_id, n.kind, n.record_type, n.record_id, n.record_uuid::text AS record_uuid, n.channel_id, n.message_id, n.title, n.body, n.read_at, n.created_at, n.actor_member_id,
                                (SELECT c.kind FROM mcp_channels c WHERE c.channel_id = n.channel_id) AS channel_kind,
                                (SELECT m.display_name FROM mcp_members m WHERE m.member_id = n.actor_member_id) AS actor_name,
                                (SELECT m.is_agent FROM mcp_members m WHERE m.member_id = n.actor_member_id) AS actor_is_agent
                           FROM mcp_notifications n' . ($unreadOnly ? ' WHERE n.read_at IS NULL' : '') . '
                          ORDER BY (n.read_at IS NULL) DESC, n.created_at DESC, n.notification_id DESC LIMIT ' . (NOTIFICATIONS_PAGE + 1) . ' OFFSET ' . (max(1, $page) - 1) * NOTIFICATIONS_PAGE);
    $st->execute();
    return array_map(static function (array $n): array {
        $n['notification_id'] = (int) $n['notification_id'];
        $n['record_id'] = $n['record_id'] === null ? null : (int) $n['record_id'];
        $n['channel_id'] = $n['channel_id'] === null ? null : (int) $n['channel_id'];
        $n['message_id'] = $n['message_id'] === null ? null : (int) $n['message_id'];
        $n['actor_is_agent'] = (bool) $n['actor_is_agent'];
        return $n;
    }, $st->fetchAll());
}

/** How many of my notices are unread (the bell). */
function unread_notification_count(PDO $pdo, int $memberId): int
{
    return (int) one_value($pdo, 'SELECT count(*) FROM mcp_notifications WHERE read_at IS NULL');
}

/** Mark one of my own notices read, or every unread one (null). Returns how many changed. */
function mark_notifications_read(PDO $pdo, int $memberId, ?int $id): int
{
    if ($id === null) {
        $st = $pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND read_at IS NULL');
        $st->execute(['m' => $memberId]);
    } else {
        $st = $pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND id = :id AND read_at IS NULL');
        $st->execute(['m' => $memberId, 'id' => $id]);
    }
    return $st->rowCount();
}

/** The Activity feed (sp_activity_feed): $since is a timestamp, $kind one of mention|reply|reaction|comment or null; the SQL answers as the caller. */
function activity_feed(PDO $pdo, string $since, ?string $kind, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT kind, occurred_at, actor_member_id, actor_name, actor_is_agent, channel_id, message_id, thread_root_id, page_id::text AS page_id, excerpt,
                                (SELECT c.kind FROM mcp_channels c WHERE c.channel_id = f.channel_id) AS channel_kind,
                                (SELECT c.name FROM mcp_channels c WHERE c.channel_id = f.channel_id) AS channel_name,
                                (SELECT p.plain_title FROM mcp_pages p WHERE p.page_id = f.page_id) AS page_title
                           FROM sp_activity_feed(CAST(:since AS timestamptz), 200) f' . ($kind === null ? '' : ' WHERE kind = :kind') . ' ORDER BY occurred_at DESC LIMIT ' . max(1, min(200, $limit)));
    $args = ['since' => $since] + ($kind === null ? [] : ['kind' => $kind]);
    $st->execute($args);
    return array_map(static function (array $r): array {
        foreach (['actor_member_id', 'channel_id', 'message_id', 'thread_root_id'] as $k) { $r[$k] = $r[$k] === null ? null : (int) $r[$k]; }
        $r['actor_is_agent'] = (bool) $r['actor_is_agent'];
        return $r;
    }, $st->fetchAll());
}

/** My saved messages, newest saved first (slice 4's reader). */
function find_saved(PDO $pdo, int $memberId): array
{
    return my_saved($pdo, $memberId);
}

/** My reminders: due and coming, or with the done ones too. */
function find_my_reminders(PDO $pdo, int $memberId, bool $includeDone = false): array
{
    return my_reminders($pdo, $memberId, $includeDone);
}
