<?php
declare(strict_types=1);
/** /databases/ — the databases I may see as cards (screen `database-list`; params space, q). New database is a form below the cards (`database_create`). */
require_once dirname(__DIR__, 2) . '/app/features/databases/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/spaces/queries.php';
require_login();
require_human();
$pdo = db();
$filters = ['space' => request_integer('space'), 'q' => mb_substr(request_string('q'), 0, 80)];
$rows = find_databases($pdo, $filters, 200);
$spaces = $pdo->query('SELECT space_id, name FROM mcp_spaces WHERE archived_at IS NULL ORDER BY name')->fetchAll();
$homes = database_homes($pdo);
$templates = database_templates($pdo);
$may = ['create' => has_right('databases.write') || $homes['pages'] !== []];
log_screen_view($pdo, 'database-list');
if (wants_json()) {
    respond_screen(['databases' => array_map('present_database', $rows), 'filters' => $filters, 'may' => $may]);
}
$notices = ['trashed' => ['warning', 'The database is in the trash.']];
render_screen('Databases', view('databases/index.php', ['rows' => $rows, 'filters' => $filters, 'spaces' => $spaces, 'homes' => $homes, 'templates' => $templates, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'databases', 'screen' => 'database-list', 'entity' => 'database']);
