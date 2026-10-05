<?php
declare(strict_types=1);
/** Action `page_restore` (log `page.restore`): full; from the trash, to the root when its parent is still trashed. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo, false);
require_page_level($p['page_id'], 'full');
sp_guard($pdo, static function () use ($pdo, $me, $p): void {
    $pdo->beginTransaction();
    restore_page($pdo, $p['page_id'], $me);
    page_log($pdo, 'page.restore', $p['page_id'], $p['space_id'], ['after' => ['title' => mb_substr($p['plain_title'], 0, 120), 'parent_page_id' => page_state($pdo, $p['page_id'])['parent_page_id']]]);
    $pdo->commit();
});
sp_done('Restored ' . ($p['plain_title'] ?: 'the page'), $p['page_id'], sp_land(return_path('/pages/' . $p['page_id']), 'restored'), 'pageChanged', ['page_id' => $p['page_id']]);
