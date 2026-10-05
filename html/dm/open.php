<?php
declare(strict_types=1);
/** Action `dm_open` (log `channel.create`: kind dm, member_ids): dm.write (a guest: spaces.guest); finds or makes the pair; location /dm/{id}. */
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
if (!has_right('dm.write') && !is_guest()) { require_right('dm.write'); }
$other = request_integer('member') ?? refuse(422, 'Say whom to message.');
$existing = (int) (one_value($pdo, 'SELECT channel_id FROM dm_pairs WHERE member_a = least(CAST(:a AS bigint), CAST(:b AS bigint)) AND member_b = greatest(CAST(:a AS bigint), CAST(:b AS bigint))', ['a' => $me, 'b' => $other]) ?: 0);
$id = sp_guard($pdo, static function () use ($pdo, $other, $me, $existing): int {
    $pdo->beginTransaction();
    $id = open_dm($pdo, $other);
    if ($existing === 0) { log_activity($pdo, 'channel.create', 'channel', $id, ['channel_id' => $id, 'after' => ['kind' => 'dm', 'member_ids' => [$me, $other]]]); }
    $pdo->commit();
    return $id;
});
$name = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $other]);
sp_done($existing === 0 ? 'Opened a conversation with ' . $name : 'Your conversation with ' . $name, $id, '/dm/' . $id, 'channelChanged', ['channel_id' => $id, 'existing' => $existing !== 0]);
