<?php
declare(strict_types=1);
/** Action `space_member_remove` (log `space.member_remove`; confirm; agent approval `other`): an owner removes a member; the last owner and General are the guard's; a derived member the handler's words. */
require_once dirname(__DIR__, 3) . '/app/features/spaces/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$s = space_from_request($pdo);
require_space_owner($s);
$member = request_integer('member') ?? refuse(422, 'Say which member.');
sp_guard($pdo, static function () use ($pdo, $me, $s, $member): void {
    $pdo->beginTransaction();
    remove_space_member($pdo, $s['space_id'], $member, $me);
    space_log($pdo, 'space.member_remove', $s['space_id'], ['after' => ['member_id' => $member, 'via' => 'owner']]);
    $pdo->commit();
});
sp_done('Removed from ' . $s['name'], $member, sp_land(return_path('/spaces/' . $s['space_id'] . '/members'), 'removed'), 'spaceChanged', ['space_id' => $s['space_id']]);
