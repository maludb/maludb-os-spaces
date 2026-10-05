<?php
declare(strict_types=1);
/** Action `page_duplicate` (log `page.duplicate`: source_page_id): view on the page, edit at the destination (beside the original by default). */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo);
$title = req_has('title') && trim((string) req_val('title')) !== '' ? mb_substr(trim((string) req_val('title')), 0, 300) : null;
$parent = req_has('parent') && (string) req_val('parent') !== '' ? (string) req_val('parent') : null;
$space = $p['space_id'];
$same = $parent === null;
if ($parent !== null) {
    if (!is_uuid($parent)) { sp_refuse_fields(['parent' => 'The destination is named by its id.']); }
    $pp = find_page($pdo, $parent) ?? refuse(404, 'Destination page not found.');
    require_page_level($parent, 'edit', 'Destination page');
    $space = $pp['space_id'];
} elseif ($p['parent_page_id'] !== null) {
    require_page_level($p['parent_page_id'], 'edit', 'Parent page');
} elseif ($p['space_id'] !== null) {
    if (!db_bool($pdo, 'SELECT sp_level_rank(sp_space_level(:s)) >= 4', ['s' => $p['space_id']])) { refuse(403, 'You may not create pages in ' . $p['space_name'] . '.'); }
} else {
    require_right('pages.private');
}
$newId = sp_guard($pdo, static function () use ($pdo, $me, $p, $title, $parent, $space, $same): string {
    $pdo->beginTransaction();
    $id = duplicate_page($pdo, $p['page_id'], $title, $parent, $space, $same, $me);
    page_log($pdo, 'page.duplicate', $id, $space, ['after' => ['source_page_id' => $p['page_id'], 'title' => mb_substr((string) page_state($pdo, $id)['plain_title'], 0, 120)]]);
    $pdo->commit();
    return $id;
});
sp_done('Duplicated ' . ($p['plain_title'] ?: 'the page'), $newId, sp_land(return_path('/pages/' . $newId), 'duplicated'), 'pageChanged', ['page_id' => $newId, 'source_page_id' => $p['page_id']]);
