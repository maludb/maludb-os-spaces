<?php
declare(strict_types=1);
/** Action `trash_purge` (log `trash.purge`: count; confirm; agent approval `deletion`): trash.purge; empty the trash I may see (or a space's). */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
sp_handler_begin();
require_right('trash.purge');
$pdo = db();
$me = (int) current_member_id();
$space = req_has('space') && (string) req_val('space') !== '' ? request_integer('space') : null;
$n = sp_guard($pdo, static function () use ($pdo, $me, $space): int {
    $pdo->beginTransaction();
    $n = purge_trash($pdo, $space, $me);
    log_activity($pdo, 'trash.purge', $space === null ? null : 'space', $space, ['space_id' => $space, 'after' => ['count' => $n]]);
    $pdo->commit();
    return $n;
});
sp_done('Emptied the trash: ' . $n . ' page' . ($n === 1 ? '' : 's') . ' deleted for good', $space, sp_land(return_path('/pages/trash'), 'emptied'), 'pageChanged', ['count' => $n]);
