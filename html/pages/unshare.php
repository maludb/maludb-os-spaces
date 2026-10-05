<?php
declare(strict_types=1);
/** Action `page_unshare` (log `page.unshare`): full; a member, a department or a guest loses their row. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo);
require_page_level($p['page_id'], 'full');
$principal = request_integer('member') ?? request_integer('department') ?? request_integer('guest') ?? request_integer('principal') ?? refuse(422, 'Say whom.');
sp_guard($pdo, static function () use ($pdo, $me, $p, $principal): void {
    $pdo->beginTransaction();
    unshare_page($pdo, $p['page_id'], $principal, $me);
    page_log($pdo, 'page.unshare', $p['page_id'], $p['space_id'], ['after' => ['principal_id' => $principal]]);
    $pdo->commit();
});
sp_done('No longer shared with them', $p['page_id'], sp_land(return_path('/pages/' . $p['page_id'] . '/share'), 'unshared'), 'pageChanged', ['page_id' => $p['page_id']]);
