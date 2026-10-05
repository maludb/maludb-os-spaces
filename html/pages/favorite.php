<?php
declare(strict_types=1);
/** Action `page_favorite` (log `page.favorite`: favorite): view; favorite yes (default) or no. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo);
require_page_level($p['page_id'], 'view');
$on = sp_yes('favorite', true);
sp_guard($pdo, static function () use ($pdo, $me, $p, $on): void {
    $pdo->beginTransaction();
    set_favorite($pdo, $p['page_id'], $me, $on);
    page_log($pdo, 'page.favorite', $p['page_id'], $p['space_id'], ['after' => ['favorite' => $on]]);
    $pdo->commit();
});
sp_done($on ? 'Added to your favorites' : 'Removed from your favorites', $p['page_id'], sp_land(return_path('/pages/' . $p['page_id']), $on ? 'favorite' : 'unfavorite'), 'pageChanged', ['favorite' => $on]);
