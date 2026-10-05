<?php
declare(strict_types=1);
/** Action `channel_mark_read` (log `channel.read` — not a row per heartbeat: only when the cursor moves): own; up to a message, the latest by default. */
require_once dirname(__DIR__, 2) . '/app/features/messages/handler.php';
sp_handler_begin();
$pdo = db();
$c = channel_from_request($pdo, false);
if (!$c['i_am_member']) { refuse(403, 'You are not in ' . $c['label'] . '.'); }
$mid = request_integer('message') ?? (int) one_value($pdo, 'SELECT COALESCE(max(id), 0) FROM messages WHERE channel_id = :c AND sent_at IS NOT NULL', ['c' => $c['channel_id']]);
$was = (int) ($c['last_read_message_id'] ?? 0);
sp_guard($pdo, static function () use ($pdo, $c, $mid, $was): void {
    $pdo->beginTransaction();
    mark_read($pdo, $c['channel_id'], $mid);
    if ($mid > $was) { channel_log($pdo, 'channel.read', $c, ['after' => ['message_id' => $mid]]); }
    $pdo->commit();
});
$u = channel_unread($pdo, $c['channel_id']);
sp_done('Read', $c['channel_id'], channel_path($c), '', ['message_id' => max($mid, $was), 'unread' => $u['unread']]);
