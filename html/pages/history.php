<?php
declare(strict_types=1);
/** /pages/{id}/history — the versions (screen `page-history`): number, when, why, by whom. Slice 3 opens one, compares two and restores. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
require_login();
require_human();
$pdo = db();
$id = (string) ($_GET['id'] ?? ($_GET['page'] ?? ''));
$p = find_page($pdo, $id) ?? refuse(404, 'Page not found.');
require_page_level($id, 'view');
$rows = page_versions_list($pdo, $id);
log_screen_view($pdo, 'page-history');
if (wants_json()) {
    respond_screen(['page' => present_page($p), 'versions' => array_map('present_version', $rows)]);
}
render_screen('History of ' . ($p['plain_title'] ?: 'the page'), view('pages/history.php', ['p' => $p, 'rows' => $rows, 'here' => here_url(), 'tz' => member_timezone()]), ['activeNav' => 'pages', 'screen' => 'page-history', 'entity' => 'page', 'recordId' => $id]);
