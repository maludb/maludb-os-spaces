<?php
declare(strict_types=1);
/**
 * /exports/ — my exports (the Spaces admin's: everyone's) and the forms that make one (screen `export-list`; params: page, database, space, channel preselect a form; row = one card, polled; JSON: the list).
 * A page and a database export are made at once; a space, a channel and everything are queued for the worker and the card polls itself. A form shows only what the caller may do (export.own, export.space, export.all).
 */
require_once dirname(__DIR__, 2) . '/app/features/exports/handler.php';
require_login();
require_human();
$pdo = db();
$may = ['own' => has_right('export.own'), 'space' => has_right('export.space') || is_sp_admin(), 'all' => has_right('export.all')];
if (!$may['own'] && !$may['space'] && !$may['all']) { refuse(403, 'You may not export.'); }
$me = (int) current_member_id();
$admin = is_sp_admin();
$all = $admin;
$rowId = request_integer('row');
if ($rowId !== null) {
    foreach (find_my_exports($pdo, $me, $all) as $e) { if ((int) $e['export_id'] === $rowId) { echo view('exports/partials/row.php', ['e' => $e, 'tz' => member_timezone(), 'all' => $all]); exit; } }
    refuse(404, 'Export not found.');
}
$rows = find_my_exports($pdo, $me, $all);
log_screen_view($pdo, 'export-list');
if (wants_json()) { respond_screen(['exports' => array_map('present_export', $rows), 'all' => $all]); }
$page = request_string('page');
$sel = ['page' => is_uuid($page) ? $page : null, 'database' => is_uuid(request_string('database')) ? request_string('database') : null, 'space' => request_integer('space'), 'channel' => request_integer('channel')];
$picks = ['pages' => $may['own'] ? export_pick_pages($pdo, $sel['page']) : [], 'databases' => $may['own'] ? export_pick_databases($pdo) : [], 'spaces' => $may['space'] ? export_pick_spaces($pdo, $admin) : [], 'channels' => $may['space'] ? export_pick_channels($pdo, $admin) : []];
render_screen('Exports', view('exports/index.php', ['rows' => $rows, 'tz' => member_timezone(), 'all' => $all, 'picks' => $picks, 'may' => $may, 'sel' => $sel, 'notice' => sp_notice($_GET['notice'] ?? null, export_notices())]),
    ['activeNav' => $admin && $may['all'] ? 'admin-exports' : 'exports', 'screen' => 'export-list']);
