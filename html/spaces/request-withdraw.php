<?php
declare(strict_types=1);
/** Action `space_join_withdraw` (log `space.join_withdraw`): withdraw my pending request. */
require_once dirname(__DIR__, 2) . '/app/features/spaces/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$s = space_from_request($pdo, false);
$rid = sp_guard($pdo, static function () use ($pdo, $me, $s): int {
    $pdo->beginTransaction();
    $rid = withdraw_request($pdo, $s['space_id'], $me);
    space_log($pdo, 'space.join_withdraw', $s['space_id'], ['after' => ['request_id' => $rid, 'member_id' => $me]], 'space_join_request', $rid);
    $pdo->commit();
    return $rid;
});
sp_done('Withdrew your request to join ' . $s['name'], $rid, sp_land(return_path('/spaces/' . $s['space_id']), 'withdrawn'), 'spaceChanged', ['space_id' => $s['space_id']]);
