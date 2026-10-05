<?php
declare(strict_types=1);
/** Action `channel_archive` (log `channel.archive`; confirm; agent approval `deletion`): owner or channel.manage; readable, never writable afterwards. */
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
sp_handler_begin();
$pdo = db();
$c = channel_from_request($pdo, false);
require_channel_owner($c);
if ($c['archived_at'] !== null) { refuse(422, 'Channel ' . $c['label'] . ' is archived already.'); }
sp_guard($pdo, static function () use ($pdo, $c): void {
    $pdo->beginTransaction();
    archive_channel($pdo, $c['channel_id'], true, (int) current_member_id());
    channel_log($pdo, 'channel.archive', $c, ['after' => ['name' => $c['name']]]);
    $pdo->commit();
});
sp_done('Archived ' . $c['label'], $c['channel_id'], sp_land(return_path('/channels/' . $c['channel_id']), 'archived'), 'channelChanged');
