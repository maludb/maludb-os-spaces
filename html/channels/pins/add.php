<?php
declare(strict_types=1);
/** Action `channel_pin` (log `channel.pin`): a member pins a message of the channel or a page they may see. */
require_once dirname(__DIR__, 3) . '/app/features/channels/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$c = channel_from_request($pdo);
require_channel_member($c);
$mid = request_integer('message');
$pid = is_uuid($_POST['page'] ?? ($_GET['page'] ?? null)) ? (string) ($_POST['page'] ?? $_GET['page']) : null;
if (($mid === null) === ($pid === null)) { sp_refuse_fields(['message' => 'Pin a message or a page.']); }
if ($pid !== null && !db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM mcp_pages WHERE page_id = CAST(:p AS uuid))', ['p' => $pid])) { refuse(404, 'Page not found.'); }
$pinId = sp_guard($pdo, static function () use ($pdo, $c, $mid, $pid, $me): int {
    $pdo->beginTransaction();
    $id = pin($pdo, $c['channel_id'], $mid, $pid, $me);
    channel_log($pdo, 'channel.pin', $c, ['after' => ['pin_id' => $id, 'message_id' => $mid, 'page_id' => $pid]]);
    $pdo->commit();
    return $id;
});
sp_done('Pinned', $pinId, sp_land(return_path('/channels/' . $c['channel_id'] . '/pins'), 'pinned'), 'messageChanged', ['pin_id' => $pinId, 'message_id' => $mid, 'page_id' => $pid]);
