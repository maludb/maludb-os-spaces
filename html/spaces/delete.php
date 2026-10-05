<?php
declare(strict_types=1);
/** Action `space_delete` (log `space.delete`; confirm; agent approval `other`): the admin deletes an archived space that holds no live page (its channels and trashed pages go with it). */
require_once dirname(__DIR__, 2) . '/app/features/spaces/handler.php';
sp_handler_begin();
require_admin();
$pdo = db();
$me = (int) current_member_id();
$s = space_from_request($pdo, false);
$name = sp_guard($pdo, static function () use ($pdo, $me, $s): string {
    $pdo->beginTransaction();
    $name = delete_space($pdo, $s['space_id'], $me);
    log_activity($pdo, 'space.delete', 'space', $s['space_id'], ['space_id' => $s['space_id'], 'after' => ['name' => $name, 'pages' => 0]]);
    $pdo->commit();
    return $name;
});
sp_done('Deleted ' . $name, $s['space_id'], sp_land('/spaces/', 'deleted'), 'spaceChanged');
