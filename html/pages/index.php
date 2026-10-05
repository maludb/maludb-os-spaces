<?php
declare(strict_types=1);
/** /pages/ — the pages I may see as cards, newest edited first (screen `page-list`; params space, q, kind, changed, private). */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
require_login();
require_human();
$pdo = db();
$filters = ['space' => request_integer('space'), 'q' => mb_substr(request_string('q'), 0, 80), 'kind' => in_array(request_string('kind'), ['page', 'database', 'row'], true) ? request_string('kind') : '',
            'changed' => in_array(request_integer('changed'), [1, 7, 30], true) ? request_integer('changed') : null, 'private' => request_bool('private')];
$rows = find_pages($pdo, $filters);
$spaces = $pdo->query('SELECT space_id, name FROM mcp_spaces WHERE archived_at IS NULL ORDER BY is_default DESC, lower(name)')->fetchAll();
$may = ['create' => has_right('pages.write') || has_right('pages.private')];
log_screen_view($pdo, 'page-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'pages' => array_map('present_page', $rows), 'may' => $may]);
}
$notices = ['trashed' => ['warning', 'The page is in the trash.'], 'purged' => ['success', 'Deleted for good.']];
render_screen('Pages', view('pages/index.php', ['rows' => $rows, 'filters' => $filters, 'spaces' => $spaces, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'pages', 'screen' => 'page-list', 'entity' => 'page']);
