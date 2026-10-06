<?php
declare(strict_types=1);
/**
 * Action `export_database` (log `page.export`: export_id, page_id, format, view_id): a database as CSV (every row — or the rows and columns of a `view` —, relations by title, rollups as displayed, people by name)
 * or JSON (the schema and the resolved rows), made at once. View level and export.own. Params: database, format (csv|json), view.
 */
require_once dirname(__DIR__, 2) . '/app/features/exports/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$db = (string) (req_val('database') ?? '');
if (!is_uuid($db)) { sp_refuse_fields(['database' => 'Choose the database from the list.']); }
require_page_level($db, 'view', 'Database');
require_right('export.own');
$d = find_database($pdo, $db) ?? refuse(404, 'Database not found.');
$view = (string) (req_val('view') ?? '');
if ($view !== '' && (!is_uuid($view) || (find_view($pdo, $view)['database_id'] ?? null) !== $db)) { sp_refuse_fields(['view' => 'That view is not one of this database\'s.']); }
$format = (string) (req_val('format') ?? 'csv');
$id = (int) sp_guard($pdo, static function () use ($pdo, $db, $format, $view, $me): int {
    $pdo->beginTransaction();
    $id = start_export($pdo, 'database', $format === '' ? 'csv' : $format, ['database' => $db, 'view' => $view], $me);
    $pdo->commit();
    return $id;
});
$r = run_export($pdo, $id);
log_activity($pdo, 'page.export', 'export', $id, ['entity_uuid' => $db, 'space_id' => $d['space_id'], 'after' => ['export_id' => $id, 'page_id' => $db, 'format' => $format === '' ? 'csv' : $format, 'view_id' => $view === '' ? null : $view, 'status' => $r['status']]]);
[$words, $notice] = export_outcome($r);
sp_done($words, $id, sp_land('/exports/', $notice), '', ['status' => $r['status'], 'export_id' => $id] + array_intersect_key($r, array_flip(['item_count', 'byte_size', 'error'])));
