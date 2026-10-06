<?php
declare(strict_types=1);
/** /admin/retention — every channel's retention by space (set one with slice 8's retention_set) and the version and trash retentions from the settings (screen `admin-retention`; right retention.manage or settings.manage). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/queries.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { header('Allow: GET'); http_response_code(405); exit('Method Not Allowed'); }
require_any_right('retention.manage|settings.manage');
$pdo = db();
$o = retention_overview($pdo);
$may = ['settings' => has_right('settings.manage')];
log_screen_view($pdo, 'admin-retention');
if (wants_json()) {
    respond_screen($o + ['may' => $may]);
}
$notices = ['retention' => ['success', 'Saved the retention.']];
render_screen('Retention', view('admin/retention.php', ['o' => $o, 'may' => $may, 'notice' => sp_notice($_GET['notice'] ?? null, $notices), 'here' => here_url()]), ['activeNav' => 'admin-retention', 'screen' => 'admin-retention', 'entity' => 'channel']);
