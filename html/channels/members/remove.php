<?php
declare(strict_types=1);
/** Action `channel_member_remove` (log `channel.member_remove`; confirm): owner or channel.manage. */
require_once dirname(__DIR__, 3) . '/app/features/channels/handler.php';
sp_handler_begin();
$pdo = db();
$c = channel_from_request($pdo, false);
require_channel_owner($c);
if ($c['kind'] === 'dm') { refuse(422, 'A direct message has its two people.'); }
$memberId = request_integer('member') ?? refuse(422, 'Say whom to remove.');
sp_guard($pdo, static function () use ($pdo, $c, $memberId): void {
    $pdo->beginTransaction();
    remove_channel_member($pdo, $c['channel_id'], $memberId);
    channel_log($pdo, 'channel.member_remove', $c, ['after' => ['member_id' => $memberId]]);
    $pdo->commit();
});
sp_done('Removed from ' . $c['label'], $c['channel_id'], sp_land(return_path('/channels/' . $c['channel_id'] . '/members'), 'removed'), 'channelChanged', ['member_id' => $memberId]);
