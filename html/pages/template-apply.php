<?php
declare(strict_types=1);
/** Action `template_apply` (log `page.template_apply`: template_page_id): edit at the destination (a space's root or under a page); the new page's title. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$template = (string) (req_val('template') ?? '');
if (!is_uuid($template)) { sp_refuse_fields(['template' => 'Say which template.']); }
$t = find_page($pdo, $template) ?? refuse(404, 'Template not found.');
$title = req_has('title') && trim((string) req_val('title')) !== '' ? mb_substr(trim((string) req_val('title')), 0, 300) : null;
$space = req_has('space') && (string) req_val('space') !== '' ? request_integer('space') : null;
$parent = req_has('parent') && (string) req_val('parent') !== '' ? (string) req_val('parent') : null;
if ($parent !== null) {
    if (!is_uuid($parent)) { sp_refuse_fields(['parent' => 'The destination is named by its id.']); }
    $pp = find_page($pdo, $parent) ?? refuse(404, 'Destination page not found.');
    require_page_level($parent, 'edit', 'Destination page');
    $space = $pp['space_id'];
} elseif ($space !== null) {
    $sn = one_value($pdo, 'SELECT name FROM mcp_spaces WHERE space_id = :s AND archived_at IS NULL', ['s' => $space]) ?? refuse(404, 'Space not found.');
    if (!db_bool($pdo, 'SELECT sp_level_rank(sp_space_level(:s)) >= 4', ['s' => $space])) { refuse(403, 'You may not create pages in ' . $sn . '.'); }
} else {
    require_right('pages.private');
}
$newId = sp_guard($pdo, static function () use ($pdo, $me, $template, $space, $parent, $title): string {
    $pdo->beginTransaction();
    $id = apply_template($pdo, $template, $space, $parent, $title, $me);
    page_log($pdo, 'page.template_apply', $id, $space, ['after' => ['template_page_id' => $template, 'title' => mb_substr((string) page_state($pdo, $id)['plain_title'], 0, 120)]]);
    $pdo->commit();
    return $id;
});
sp_done('Made a page from ' . ($t['plain_title'] ?: 'the template'), $newId, sp_land(return_path('/pages/' . $newId), 'created'), 'pageChanged', ['page_id' => $newId]);
