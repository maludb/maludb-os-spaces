<?php
declare(strict_types=1);
/** /databases/{id}/views/new (screen `view-add`) and /databases/{id}/views/{view}/edit (screen `view-edit`): the form — name, layout, what the layout needs, the filter editor, sort, group, visible properties, cards, a page to embed on. */
require_once dirname(__DIR__, 3) . '/app/features/databases/handler.php';
require_login();
require_human();
$pdo = db();
$d = find_database($pdo, (string) ($_GET['id'] ?? ($_GET['database'] ?? ''))) ?? refuse(404, 'Database not found.');
require_page_level($d['database_id'], 'edit', 'Database');
if ($d['archived_at'] !== null) { refuse(422, 'Database "' . $d['plain_title'] . '" is in the trash: restore it first.'); }
$cur = null;
if (isset($_GET['view']) && (string) $_GET['view'] !== '') {
    $cur = null;
    foreach ($d['views'] as $v) { if ($v['view_id'] === (string) $_GET['view']) { $cur = $v; } }
    $cur ?? refuse(404, 'View not found.');
}
$screen = $cur === null ? 'view-add' : 'view-edit';
$ff = filter_to_form($cur['filter'] ?? null, $d['properties']);
$raw = ($cur['filter'] ?? null) === null ? '' : json_encode($cur['filter'], JSON_UNESCAPED_UNICODE);
$homes = database_homes($pdo)['pages'];
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['database' => present_database($d), 'view' => $cur === null ? null : present_view($cur), 'layouts' => ['table', 'board', 'gallery', 'list', 'calendar', 'timeline'],
        'conditions' => array_map(static fn (array $def): array => array_keys(property_conditions((string) $def['type'])), $d['properties'])]);
}
render_screen($cur === null ? 'New view' : 'Change ' . $cur['name'], view('databases/views/form.php', ['d' => $d, 'schema' => $d['properties'], 'cur' => $cur, 'homes' => $homes, 'ff' => $ff, 'raw' => $raw, 'here' => here_url()]),
    ['activeNav' => 'databases', 'screen' => $screen, 'entity' => 'view', 'recordId' => $cur['view_id'] ?? '']);
