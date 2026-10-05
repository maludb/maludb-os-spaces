<?php
declare(strict_types=1);
/** Action `view_delete` (log `view.delete`; confirm): edit; a database keeps one view of its own (the sentence says so). A linked view may always go (its pointer block shows "removed"). */
require_once dirname(__DIR__, 3) . '/app/features/databases/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$viewId = (string) (req_val('view') ?? '');
$v = is_uuid($viewId) ? find_view($pdo, $viewId) : null;
$v ?? refuse(404, 'View not found.');
$_POST['database'] = $v['database_id'];
$d = database_from_request($pdo);
require_page_level($d['database_id'], 'edit', 'Database');
sp_guard($pdo, static function () use ($pdo, $me, $d, $v): void {
    $pdo->beginTransaction();
    delete_view($pdo, $v['view_id'], $me);
    database_log($pdo, 'view.delete', 'view', $v['view_id'], $d['space_id'], ['after' => ['database_id' => $d['database_id'], 'name' => mb_substr((string) $v['name'], 0, 80), 'layout' => $v['layout']]]);
    $pdo->commit();
});
sp_done('Deleted the view ' . $v['name'], $v['view_id'], sp_land(return_path('/databases/' . $d['database_id']), 'viewdeleted'), 'databaseChanged', ['view_id' => $v['view_id'], 'database_id' => $d['database_id']]);
