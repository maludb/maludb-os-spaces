<?php
declare(strict_types=1);
/**
 * Action `export_all` (log `export.all`: export_id, format; confirm; agent approval `external_send`): every space and every channel the caller may read as one zip — never a direct message. The right export.all
 * (the Spaces admin). Queued for the worker.
 */
require_once dirname(__DIR__, 2) . '/app/features/exports/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
require_right('export.all');
$id = (int) sp_guard($pdo, static function () use ($pdo, $me): int {
    $pdo->beginTransaction();
    $id = start_export($pdo, 'all', 'zip', [], $me);
    $pdo->commit();
    return $id;
});
log_activity($pdo, 'export.all', 'export', $id, ['after' => ['export_id' => $id, 'format' => 'zip']]);
sp_done('Queued the export of everything', $id, sp_land('/exports/', 'queued'), '', ['status' => 'queued', 'export_id' => $id]);
