<?php
declare(strict_types=1);
/** /admin/published — every published page: who published it and when, subpages, noindex, views, last opened; Rotate and Unpublish are slice 2's actions (screen `admin-published`; right settings.manage). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/present.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { header('Allow: GET'); http_response_code(405); exit('Method Not Allowed'); }
require_right('settings.manage');
$pdo = db();
$rows = published_pages($pdo);
log_screen_view($pdo, 'admin-published');
if (wants_json()) {
    respond_screen(['published' => array_map('present_publication', $rows), 'count' => count($rows)]);
}
$notices = ['rotated' => ['success', 'A new link was made. The old one stopped.'], 'unpublished' => ['success', 'The page is off the web.']];
render_screen('Published pages', view('admin/published.php', ['rows' => $rows, 'notice' => sp_notice($_GET['notice'] ?? null, $notices), 'here' => here_url(), 'tz' => member_timezone()]), ['activeNav' => 'admin-published', 'screen' => 'admin-published', 'entity' => 'page']);
