<?php
declare(strict_types=1);

/** Reminders (slice 4): mine (mcp_reminders), due / coming / done, with what each is about. */
function my_reminders(PDO $pdo, int $memberId, bool $includeDone = false): array
{
    $st = $pdo->prepare('SELECT r.reminder_id, r.member_id, r.message_id, r.page_id::text AS page_id, r.text, r.remind_at, r.done_at, r.notified_at, r.created_at,
                                (SELECT plain_title FROM mcp_pages WHERE page_id = r.page_id) AS page_title, (SELECT left(plain_text, 160) FROM mcp_messages WHERE message_id = r.message_id) AS message_text,
                                (SELECT channel_id FROM mcp_messages WHERE message_id = r.message_id) AS channel_id
                           FROM mcp_reminders r WHERE r.member_id = :m' . ($includeDone ? '' : ' AND r.done_at IS NULL') . ' ORDER BY r.done_at IS NOT NULL, r.remind_at');
    $st->execute(['m' => $memberId]);
    return array_map(static function (array $r): array {
        $r['reminder_id'] = (int) $r['reminder_id']; $r['message_id'] = $r['message_id'] === null ? null : (int) $r['message_id']; $r['channel_id'] = $r['channel_id'] === null ? null : (int) $r['channel_id'];
        $r['state'] = $r['done_at'] !== null ? 'done' : (strtotime((string) $r['remind_at']) <= time() ? 'due' : 'coming');
        return $r;
    }, $st->fetchAll());
}

function present_reminder(array $r): array
{
    return ['reminder_id' => $r['reminder_id'], 'message_id' => $r['message_id'], 'page_id' => $r['page_id'], 'page_title' => $r['page_title'], 'message_text' => $r['message_text'], 'channel_id' => $r['channel_id'], 'text' => $r['text'],
            'remind_at' => json_ts($r['remind_at']), 'done_at' => json_ts($r['done_at']), 'notified_at' => json_ts($r['notified_at']), 'state' => $r['state'], 'created_at' => json_ts($r['created_at'])];
}
