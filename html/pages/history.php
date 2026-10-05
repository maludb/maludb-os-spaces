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
$maySave = PAGE_LEVELS[$p['my_level']] >= PAGE_LEVELS[$p['parent_database_id'] !== null ? 'edit_content' : 'edit'] && $p['archived_at'] === null;
render_screen('History of ' . ($p['plain_title'] ?: 'the page'), view('pages/history.php', ['p' => $p, 'rows' => $rows, 'maySave' => $maySave, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved a version.'], 'restored' => ['success', 'Restored.']])]), ['activeNav' => 'pages', 'screen' => 'page-history', 'entity' => 'page', 'recordId' => $id]);
