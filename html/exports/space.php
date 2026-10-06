<?php
declare(strict_types=1);
/**
 * Action `export_space` (log `space.export`: export_id, space_id, format; confirm; agent approval `external_send`): a whole space — every page the caller may see as a folder tree, every database as CSV with its rows,
 * index.md with the sidebar — as a zip of Markdown or of HTML, or as JSON. The space's owner (or the admin) with export.space. Queued: the worker's `exports` step makes it and the list polls.
 * Params: space, format (md|html|json; zip = md).
 */
require_once dirname(__DIR__, 2) . '/app/features/exports/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$sid = request_integer('space') ?? sp_refuse_fields(['space' => 'Say which space.']);
$s = find_space($pdo, $sid) ?? refuse(404, 'Space not found.');
if (!is_sp_admin() && !($s['i_am_owner'] ?? false)) { refuse(403, 'Only an owner of ' . $s['name'] . ' exports it.'); }
if (!has_right('export.space') && !is_sp_admin()) { refuse(403, 'You may not export a whole space.'); }
$format = (string) (req_val('format') ?? 'md');
$id = (int) sp_guard($pdo, static function () use ($pdo, $sid, $format, $me): int {
    $pdo->beginTransaction();
    $id = start_export($pdo, 'space', $format === '' ? 'md' : $format, ['space' => $sid], $me);
    $pdo->commit();
    return $id;
});
log_activity($pdo, 'space.export', 'export', $id, ['space_id' => $sid, 'after' => ['export_id' => $id, 'space_id' => $sid, 'format' => $format === '' ? 'md' : $format]]);
sp_done('Queued the export of ' . $s['name'], $id, sp_land('/exports/', 'queued'), '', ['status' => 'queued', 'export_id' => $id]);
