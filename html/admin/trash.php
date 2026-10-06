<?php
declare(strict_types=1);
/** /admin/trash — everyone's trash (screen `admin-trash`; right trash.purge or settings.manage; param space): title, space, who trashed, when it is purged; Restore, Purge and Purge all are slice 2's actions. The page says what Purge all would remove. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/pages/present.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { header('Allow: GET'); http_response_code(405); exit('Method Not Allowed'); }
require_any_right('trash.purge|settings.manage');
$pdo = db();
$space = request_integer('space');
$t = admin_trash($pdo, $space);
$may = ['purge' => has_right('trash.purge')];
log_screen_view($pdo, 'admin-trash');
if (wants_json()) {
    respond_screen(['trash' => array_map('present_trash', $t['rows']), 'count' => $t['count'], 'space' => $space, 'may' => $may]);
}
$notices = ['restored' => ['success', 'Restored.'], 'purged' => ['success', 'Deleted for good.'], 'emptied' => ['success', 'Purged.']];
render_screen('Everyone\'s trash', view('admin/trash.php', ['t' => $t, 'space' => $space, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'admin-trash', 'screen' => 'admin-trash', 'entity' => 'page']);
