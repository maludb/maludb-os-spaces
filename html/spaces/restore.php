<?php
declare(strict_types=1);
/** Action `space_restore` (log `space.restore`): an owner brings an archived space back. */
require_once dirname(__DIR__, 2) . '/app/features/spaces/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$s = space_from_request($pdo, false);
require_space_owner($s);
sp_guard($pdo, static function () use ($pdo, $me, $s): void {
    $pdo->beginTransaction();
    archive_space($pdo, $s['space_id'], false, $me);
    space_log($pdo, 'space.restore', $s['space_id'], ['after' => ['name' => $s['name']]]);
    $pdo->commit();
});
sp_done($s['name'] . ' is back', $s['space_id'], sp_land(return_path('/spaces/' . $s['space_id']), 'restored'), 'spaceChanged');
