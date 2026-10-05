<?php
declare(strict_types=1);
/** Action `channel_delete` (log `channel.delete`; confirm; agent approval `deletion`): the admin; an archived channel, for good. */
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
sp_handler_begin();
$pdo = db();
if (!is_sp_admin()) { refuse(403, 'Only the Spaces admin deletes a channel.'); }
$c = channel_from_request($pdo, false);
sp_guard($pdo, static function () use ($pdo, $c): void {
    $pdo->beginTransaction();
    channel_log($pdo, 'channel.delete', $c, ['after' => ['name' => $c['name'], 'kind' => $c['kind'], 'message_count' => $c['message_count']]]);
    delete_channel($pdo, $c['channel_id']);
    $pdo->commit();
});
sp_done('Deleted ' . $c['label'], $c['channel_id'], sp_land(return_path('/channels/'), 'deleted'), 'channelChanged');
