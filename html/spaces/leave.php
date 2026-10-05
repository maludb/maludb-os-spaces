<?php
declare(strict_types=1);
/** Action `space_leave` (log `space.member_remove`; confirm): leave a space. General is refused by the guard; a department space's derived member in the handler's words; the last owner by the guard. */
require_once dirname(__DIR__, 2) . '/app/features/spaces/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$s = space_from_request($pdo, false);
sp_guard($pdo, static function () use ($pdo, $me, $s): void {
    $pdo->beginTransaction();
    leave_space($pdo, $s['space_id'], $me);
    space_log($pdo, 'space.member_remove', $s['space_id'], ['after' => ['member_id' => $me, 'via' => 'leave']]);
    $pdo->commit();
});
sp_done('You left ' . $s['name'], $s['space_id'], sp_land(return_path('/spaces/'), 'left'), 'spaceChanged');
