<?php
declare(strict_types=1);
/**
 * /pages/{id} — the reader (screen `page-view`): breadcrumb, icon, cover, title, the block tree rendered, the page menu. A visit lands in recents and the trail. Slice 3 turns the body into the editor for
 * someone with edit. A database is read at /databases/{id} and a row at /databases/{db}/rows/{id} (slice 5): a browser asked for either here is sent there; JSON still answers as a page.
 */
require_once dirname(__DIR__, 2) . '/app/features/pages/screen.php';
require_login();
require_human();
$pdo = db();
$id = (string) ($_GET['id'] ?? ($_GET['page'] ?? ''));
$p = find_page($pdo, $id) ?? refuse(404, 'Page not found.');
if (!wants_json() && ($p['kind'] === 'database' || $p['is_row'])) {
    $q = http_build_query(array_diff_key($_GET, ['id' => 1, 'page' => 1]));
    redirect(($p['is_row'] ? '/databases/' . $p['parent_database_id'] . '/rows/' . $p['page_id'] : '/databases/' . $p['page_id']) . ($q !== '' ? '?' . $q : ''));
}
page_screen($pdo, $id);
