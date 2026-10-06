<?php
declare(strict_types=1);
/** /admin/spaces — every space, the private ones marked `feather-lock` (screen `admin-spaces`; right settings.manage; params kind, archived). Archive, restore and delete are slice 1's actions; opening a private space from here is logged `space.admin_view` by space-view. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/present.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { header('Allow: GET'); http_response_code(405); exit('Method Not Allowed'); }
require_right('settings.manage');
$pdo = db();
$filters = ['kind' => in_array(request_string('kind'), ['open', 'closed', 'private'], true) ? request_string('kind') : '', 'archived' => request_bool('archived')];
$rows = admin_spaces($pdo, $filters);
log_screen_view($pdo, 'admin-spaces');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'spaces' => array_map('present_admin_space', $rows)]);
}
$notices = ['archived' => ['success', 'Archived the space.'], 'restored' => ['success', 'Restored the space.'], 'deleted' => ['success', 'The space was deleted.']];
render_screen('Every space', view('admin/spaces.php', ['rows' => $rows, 'filters' => $filters, 'notice' => sp_notice($_GET['notice'] ?? null, $notices), 'here' => here_url()]), ['activeNav' => 'admin-spaces', 'screen' => 'admin-spaces', 'entity' => 'space']);
