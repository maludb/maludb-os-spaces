<?php
declare(strict_types=1);
/** Action `space_archive` (log `space.archive`; confirm; agent approval `other`): an owner archives a space — everything stays readable, nothing changes in it. General is refused by the guard. */
require_once dirname(__DIR__, 2) . '/app/features/spaces/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$s = space_from_request($pdo, false);
require_space_owner($s);
sp_guard($pdo, static function () use ($pdo, $me, $s): void {
    $pdo->beginTransaction();
    archive_space($pdo, $s['space_id'], true, $me);
    space_log($pdo, 'space.archive', $s['space_id'], ['after' => ['name' => $s['name'], 'pages' => $s['page_count'], 'channels' => $s['channel_count']]]);
    $pdo->commit();
});
sp_done('Archived ' . $s['name'], $s['space_id'], sp_land(return_path('/spaces/' . $s['space_id']), 'archived'), 'spaceChanged');
