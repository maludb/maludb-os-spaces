<?php
declare(strict_types=1);
/**
 * Action `export_delete` (log `export.delete`: export_id, kind, via = person): the maker deletes their own export — the file is unlinked, its attachment goes, the row stays as expired. Params: export.
 */
require_once dirname(__DIR__, 2) . '/app/features/exports/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/channels/retention.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('export') ?? sp_refuse_fields(['export' => 'Say which export.']);
$row = null;
foreach (find_my_exports($pdo, $me, false) as $e) { if ((int) $e['export_id'] === $id) { $row = $e; } }
$row ?? refuse(404, 'Export not found.');
if (in_array($row['status'], ['queued', 'running'], true)) { refuse(422, 'That export is still being made.'); }
sp_guard($pdo, static function () use ($pdo, $id, $row): void {
    $pdo->beginTransaction();
    $path = one_value($pdo, 'SELECT storage_path FROM exports WHERE id = :i', ['i' => $id]);
    $pdo->prepare("UPDATE exports SET storage_path = NULL, expires_at = LEAST(expires_at, now()) WHERE id = :i")->execute(['i' => $id]);
    $st = $pdo->prepare("SELECT id, storage_path, thumbnail_path FROM attachments WHERE record_type = 'export' AND record_id = :i");
    $st->execute(['i' => $id]);
    drop_attachments($pdo, $st->fetchAll());
    if ($path !== null) { remove_attachment_files([$path]); }
    log_activity($pdo, 'export.delete', 'export', $id, ['after' => ['export_id' => $id, 'kind' => $row['kind'], 'via' => 'person']]);
    $pdo->commit();
});
sp_done('Deleted the export', $id, sp_land('/exports/', 'deleted'), 'exportChanged');
