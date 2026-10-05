<?php
declare(strict_types=1);
/** Action `channel_leave` (log `channel.leave`): own; never a space's default (the guard's words). */
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$c = channel_from_request($pdo, false);
if ($c['kind'] === 'dm') { refuse(422, 'A direct message has its two people.'); }
sp_guard($pdo, static function () use ($pdo, $c, $me): void {
    $pdo->beginTransaction();
    leave_channel($pdo, $c['channel_id'], $me);
    channel_log($pdo, 'channel.leave', $c, ['after' => ['member_id' => $me]]);
    $pdo->commit();
});
sp_done('Left ' . $c['label'], $c['channel_id'], sp_land(return_path($c['kind'] === 'group_dm' ? '/dm/' : '/channels/'), 'left'), 'channelChanged');
