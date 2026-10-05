<?php
declare(strict_types=1);
/** Action `page_lock` (log `page.lock`: locked, version_no; agent approval `other`): full; a version is saved before locking. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo);
require_page_level($p['page_id'], 'full');
$locked = sp_yes('locked', !$p['is_locked']);
$vn = sp_guard($pdo, static function () use ($pdo, $me, $p, $locked): int {
    $pdo->beginTransaction();
    $vn = set_page_lock($pdo, $p['page_id'], $locked, $me);
    page_log($pdo, 'page.lock', $p['page_id'], $p['space_id'], ['after' => ['locked' => $locked, 'version_no' => $vn ?: null]]);
    $pdo->commit();
    return $vn;
});
sp_done(($locked ? 'Locked ' : 'Unlocked ') . ($p['plain_title'] ?: 'the page'), $p['page_id'], sp_land(return_path('/pages/' . $p['page_id']), $locked ? 'locked' : 'unlocked'), 'pageChanged', ['locked' => $locked, 'version_no' => $vn ?: null]);
