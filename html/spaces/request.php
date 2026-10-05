<?php
declare(strict_types=1);
/** Action `space_join_request` (log `space.join_request` — the message's length, never its words): ask to join a closed space; one pending request; the owners are told. */
require_once dirname(__DIR__, 2) . '/app/features/spaces/handler.php';
sp_handler_begin();
require_right('spaces.join');
$pdo = db();
$me = (int) current_member_id();
$s = space_from_request($pdo);
$message = req_has('message') ? trim((string) req_val('message')) : '';
if (mb_strlen($message) > 500) {
    sp_refuse_fields(['message' => 'A message is up to 500 characters.']);
}
$rid = sp_guard($pdo, static function () use ($pdo, $me, $s, $message): int {
    $pdo->beginTransaction();
    $rid = request_join($pdo, $s['space_id'], $me, $message === '' ? null : $message);
    space_log($pdo, 'space.join_request', $s['space_id'], ['after' => ['request_id' => $rid, 'member_id' => $me, 'message_length' => mb_strlen($message)]], 'space_join_request', $rid);
    $pdo->commit();
    return $rid;
});
sp_done('Asked to join ' . $s['name'], $rid, sp_land(return_path('/spaces/' . $s['space_id']), 'requested'), 'spaceChanged', ['space_id' => $s['space_id']]);
