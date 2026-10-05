<?php
declare(strict_types=1);
/** Action `channel_unarchive` (log `channel.unarchive`): owner or channel.manage. */
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
sp_handler_begin();
$pdo = db();
$c = channel_from_request($pdo, false);
require_channel_owner($c);
if ($c['archived_at'] === null) { refuse(422, 'Channel ' . $c['label'] . ' is not archived.'); }
sp_guard($pdo, static function () use ($pdo, $c): void {
    $pdo->beginTransaction();
    archive_channel($pdo, $c['channel_id'], false, (int) current_member_id());
    channel_log($pdo, 'channel.unarchive', $c, ['after' => ['name' => $c['name']]]);
    $pdo->commit();
});
sp_done('Restored ' . $c['label'], $c['channel_id'], sp_land(return_path('/channels/' . $c['channel_id']), 'restored'), 'channelChanged');
