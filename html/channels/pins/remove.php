<?php
declare(strict_types=1);
/** Action `channel_unpin` (log `channel.unpin`): a member. */
require_once dirname(__DIR__, 3) . '/app/features/channels/handler.php';
sp_handler_begin();
$pdo = db();
$c = channel_from_request($pdo, false);
require_channel_member($c);
$mid = request_integer('message');
$pid = is_uuid($_POST['page'] ?? ($_GET['page'] ?? null)) ? (string) ($_POST['page'] ?? $_GET['page']) : null;
if (($mid === null) === ($pid === null)) { sp_refuse_fields(['message' => 'Unpin a message or a page.']); }
sp_guard($pdo, static function () use ($pdo, $c, $mid, $pid): void {
    $pdo->beginTransaction();
    unpin($pdo, $c['channel_id'], $mid, $pid);
    channel_log($pdo, 'channel.unpin', $c, ['after' => ['message_id' => $mid, 'page_id' => $pid]]);
    $pdo->commit();
});
sp_done('Unpinned', $c['channel_id'], sp_land(return_path('/channels/' . $c['channel_id'] . '/pins'), 'unpinned'), 'messageChanged', ['message_id' => $mid, 'page_id' => $pid]);
