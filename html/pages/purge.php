<?php
declare(strict_types=1);
/** Action `page_delete` (log `page.delete`: count; confirm; agent approval `deletion`): full on the page or trash.purge; a page in the trash, for good. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo, false);
if (!has_full_page($p['page_id']) && !has_right('trash.purge')) { refuse(403, 'You may not delete this page for good.'); }
if ($p['archived_at'] === null) { refuse(422, 'Page "' . $p['plain_title'] . '" is not in the trash: trash it first.'); }
$n = sp_guard($pdo, static function () use ($pdo, $me, $p): int {
    $pdo->beginTransaction();
    page_log($pdo, 'page.delete', $p['page_id'], $p['space_id'], ['after' => ['title' => mb_substr($p['plain_title'], 0, 120)]]);
    $n = purge_page($pdo, $p['page_id'], $me);
    $pdo->commit();
    return $n;
});
sp_done('Deleted ' . ($p['plain_title'] ?: 'the page') . ' for good' . ($n > 1 ? ' (' . $n . ' pages)' : ''), $p['page_id'], sp_land(return_path('/pages/trash'), 'purged'), 'pageChanged', ['count' => $n]);
