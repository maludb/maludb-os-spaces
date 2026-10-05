<?php
declare(strict_types=1);
/** Action `view_reorder` (log `view.reorder`: after): edit; the view goes right after `after` (empty: first) among the database's own views. */
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
$after = req_has('after') && (string) req_val('after') !== '' ? (string) req_val('after') : null;
if ($after !== null && !is_uuid($after)) { sp_refuse_fields(['after' => 'The view to follow is named by its id.']); }
sp_guard($pdo, static function () use ($pdo, $me, $d, $v, $after): void {
    $pdo->beginTransaction();
    reorder_view($pdo, $v['view_id'], $after, $me);
    database_log($pdo, 'view.reorder', 'view', $v['view_id'], $d['space_id'], ['after' => ['database_id' => $d['database_id'], 'after' => $after]]);
    $pdo->commit();
});
sp_done('Moved the view ' . $v['name'], $v['view_id'], sp_land(return_path('/databases/' . $d['database_id'] . '?view=' . $v['view_id']), 'reordered'), 'databaseChanged', ['view_id' => $v['view_id'], 'database_id' => $d['database_id']]);
