<?php
declare(strict_types=1);
/** Action `page_unrestrict` (log `page.unrestrict`): full; the space's default comes back. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo);
require_page_level($p['page_id'], 'full');
sp_guard($pdo, static function () use ($pdo, $me, $p): void {
    $pdo->beginTransaction();
    restrict_page($pdo, $p['page_id'], false, $me);
    page_log($pdo, 'page.unrestrict', $p['page_id'], $p['space_id'], ['after' => ['restricted' => false]]);
    $pdo->commit();
});
sp_done('The space\'s default is back on ' . ($p['plain_title'] ?: 'the page'), $p['page_id'], sp_land(return_path('/pages/' . $p['page_id'] . '/share'), 'unrestricted'), 'pageChanged', ['page_id' => $p['page_id']]);
