<?php
declare(strict_types=1);
/** Action `page_unpublish` (log `page.unpublish`; confirm; agent approval `external_send`): publish.web. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
sp_handler_begin();
require_right('publish.web');
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo, false);
require_page_level($p['page_id'], 'view');
sp_guard($pdo, static function () use ($pdo, $me, $p): void {
    $pdo->beginTransaction();
    unpublish_page($pdo, $p['page_id'], $me);
    page_log($pdo, 'page.unpublish', $p['page_id'], $p['space_id'], ['after' => ['published' => false]]);
    $pdo->commit();
});
sp_done(($p['plain_title'] ?: 'The page') . ' is off the web', $p['page_id'], sp_land(return_path('/pages/' . $p['page_id'] . '/publish'), 'unpublished'), 'pageChanged', ['page_id' => $p['page_id']]);
