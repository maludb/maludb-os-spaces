<?php
declare(strict_types=1);
/** Action `space_join` (log `space.member_add`): a member joins an open space. A guest is refused in words; a closed or private space in the handler's. */
require_once dirname(__DIR__, 2) . '/app/features/spaces/handler.php';
sp_handler_begin();
require_right('spaces.join');
$pdo = db();
$me = (int) current_member_id();
$s = space_from_request($pdo);
$joined = sp_guard($pdo, static function () use ($pdo, $me, $s): bool {
    $pdo->beginTransaction();
    $joined = join_space($pdo, $s['space_id'], $me);
    if ($joined) {
        space_log($pdo, 'space.member_add', $s['space_id'], ['after' => ['member_id' => $me, 'role' => 'member', 'via' => 'join']]);
    }
    $pdo->commit();
    return $joined;
});
sp_done($joined ? 'You are in ' . $s['name'] . ' now' : 'You are already in ' . $s['name'], $s['space_id'], sp_land(return_path('/spaces/' . $s['space_id']), 'joined'), 'spaceChanged');
