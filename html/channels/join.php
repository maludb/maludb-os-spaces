<?php
declare(strict_types=1);
/** Action `channel_join` (log `channel.join`): follow a public channel of a space I am in; a private one only once invited (added). */
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$c = channel_from_request($pdo);
if (in_array($c['kind'], ['dm', 'group_dm'], true)) { refuse(422, 'A conversation is opened, not joined.'); }
$joined = sp_guard($pdo, static function () use ($pdo, $c, $me): bool {
    $pdo->beginTransaction();
    $j = join_channel($pdo, $c['channel_id'], $me);
    if ($j) { channel_log($pdo, 'channel.join', $c, ['after' => ['member_id' => $me]]); }
    $pdo->commit();
    return $j;
});
sp_done($joined ? 'Joined ' . $c['label'] : 'You were in ' . $c['label'] . ' already', $c['channel_id'], sp_land(return_path('/channels/' . $c['channel_id']), 'joined'), 'channelChanged');
