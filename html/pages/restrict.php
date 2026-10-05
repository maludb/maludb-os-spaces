<?php
declare(strict_types=1);
/** Action `page_restrict` (log `page.restrict`; confirm): full; only the principals named on the page reach it. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo);
require_page_level($p['page_id'], 'full');
sp_guard($pdo, static function () use ($pdo, $me, $p): void {
    $pdo->beginTransaction();
    restrict_page($pdo, $p['page_id'], true, $me);
    page_log($pdo, 'page.restrict', $p['page_id'], $p['space_id'], ['after' => ['restricted' => true]]);
    $pdo->commit();
});
sp_done('Restricted ' . ($p['plain_title'] ?: 'the page'), $p['page_id'], sp_land(return_path('/pages/' . $p['page_id'] . '/share'), 'restricted'), 'pageChanged', ['page_id' => $p['page_id']]);
