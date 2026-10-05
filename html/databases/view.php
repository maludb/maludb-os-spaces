<?php
declare(strict_types=1);
/**
 * /databases/{id}?view= — a database through its views (screen `database-view`): the tabs, the toolbar, and the active layout (table, board, gallery, list, calendar, timeline) over
 * sp_database_rows(database, view), paged 100 (Load more). The filter, the sort and the group are the database's own; this page only draws what it returns.
 */
require_once dirname(__DIR__, 2) . '/app/features/databases/handler.php';
require_login();
require_human();
$pdo = db();
$id = (string) ($_GET['id'] ?? ($_GET['database'] ?? ''));
$d = find_database($pdo, $id) ?? refuse(404, 'Database not found.');
$view = view_for($d, isset($_GET['view']) ? (string) $_GET['view'] : null) ?? refuse(404, 'View not found.');
$schema = $d['properties'];
$titleKey = (string) $d['title_property_key'];
$cols = visible_schema($schema, $view['visible_properties'], $titleKey);
$rank = PAGE_LEVELS[$d['my_level']] ?? 0;
$live = $d['archived_at'] === null;
$may = ['edit' => $rank >= 3 && $live, 'schema' => $rank >= 4 && $live, 'full' => $rank >= 5 && $live, 'comment' => $rank >= 2];
$layout = (string) $view['layout'];
$q = mb_substr(request_string('q'), 0, 80);
$extra = $q === '' ? null : ['property' => $titleKey, 'title' => ['contains' => $q]];
$filter = $extra === null ? null : combined_filter($view['filter'], $extra);
$limit = max(100, min(1000, (int) (request_integer('limit') ?? 100)));
$paged = in_array($layout, ['table', 'gallery', 'list', 'board'], true);
$rows = database_rows($pdo, $d['database_id'], $view['view_id'], $filter, null, $paged ? $limit : 500, 0);
$total = $rows === [] ? 0 : $rows[0]['total'];
$here = here_url();
$base = '/databases/' . $d['database_id'] . '?view=' . $view['view_id'] . ($q !== '' ? '&q=' . rawurlencode($q) : '');
$wk = (int) one_value($pdo, 'SELECT week_start_dow FROM sp_settings WHERE id = 1');
$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : (new DateTimeImmutable('today'))->format('Y-m');
$todayDow = (int) (new DateTimeImmutable('today'))->format('w');
$defaultWeek = (new DateTimeImmutable('today'))->modify('-' . (($todayDow - $wk + 7) % 7) . ' days')->format('Y-m-d');
$week = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['week'] ?? '')) && strtotime((string) $_GET['week']) !== false ? (string) $_GET['week'] : $defaultWeek;
$members = $may['edit'] ? member_choices($pdo) : [];
if ($live) {
    $pdo->prepare('SELECT sp_page_visited(CAST(:id AS uuid))')->execute(['id' => $d['database_id']]);
}
log_screen_view($pdo, 'database-view');
if (wants_json()) {
    respond_screen(['database' => present_database($d), 'views' => array_map('present_view', $d['views']), 'view' => present_view($view), 'rows' => array_map(static fn (array $r): array => present_row($r, $schema), $rows),
        'total' => $total, 'limit' => $limit, 'may' => $may]);
}
$body = render_layout($pdo, $d, $view, $rows, $total, $may, $here, ['limit' => $limit, 'month' => $month, 'wk' => $wk, 'week' => $week, 'weeks' => 8, 'base' => $base, 'members' => $members]);
render_screen($d['plain_title'] !== '' ? $d['plain_title'] : 'Untitled database', view('databases/view.php', ['d' => $d, 'view' => $view, 'schema' => $schema, 'body' => $body, 'may' => $may, 'q' => $q, 'here' => $here, 'base' => $base, 'members' => $members, 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, database_notices($d))]),
    ['activeNav' => 'databases', 'screen' => 'database-view', 'entity' => 'database', 'recordId' => $d['database_id']]);
