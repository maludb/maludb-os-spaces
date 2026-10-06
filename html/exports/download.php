<?php
declare(strict_types=1);
/**
 * GET /exports/download.php?id= — the file of an export: the maker's own, or any for the Spaces admin (what mcp_exports shows the caller); a stranger's, an expired one, one still being made is a 404.
 * nosniff, an attachment download; logs `export.download` (export_id, kind, byte_size). Not an action of the manifest that writes anything else but the downloaded_at stamp.
 */
require_once dirname(__DIR__, 2) . '/app/features/exports/handler.php';
require_login();
$pdo = db();
$id = request_integer('id');
$f = $id === null ? null : export_file($pdo, $id, (int) current_member_id());
if ($f === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found.');
}
$pdo->prepare('UPDATE exports SET downloaded_at = now() WHERE id = :i')->execute(['i' => $f['export_id']]);
log_activity($pdo, 'export.download', 'export', $f['export_id'], ['after' => ['export_id' => $f['export_id'], 'kind' => $f['kind'], 'byte_size' => $f['byte_size']]]);
header('Content-Type: ' . $f['mime']);
header('Content-Length: ' . filesize($f['full']));
header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $f['file_name']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($f['full']);
exit;
