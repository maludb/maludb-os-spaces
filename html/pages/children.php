<?php
declare(strict_types=1);
/** GET /pages/children.php?page=<uuid> — a page's children for the sidebar's lazy tree (sp_page_children, db/015), as the tree's list items. Pattern A, no log. A page the caller may not see: 404. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
$page = (string) ($_GET['page'] ?? '');
if (!is_uuid($page) || !can_see_page($page)) {
    respond_not_found('Page not found.');
}
$st = db()->prepare('SELECT page_id, title, icon, kind, has_children FROM sp_page_children(CAST(:p AS uuid))');
$st->execute(['p' => $page]);
$rows = $st->fetchAll();
if (wants_json()) {
    respond_screen(['page_id' => $page, 'children' => array_map(static fn (array $r): array => ['page_id' => $r['page_id'], 'title' => $r['title'], 'icon' => $r['icon'], 'kind' => $r['kind'], 'has_children' => (bool) $r['has_children']], $rows)]);
}
header('Vary: HX-Request');
header('Cache-Control: no-store');
echo $rows === [] ? '<li class="sp-tree-empty">No pages inside.</li>' : view('shared/sidebar-pages.php', ['pages' => $rows, 'here' => current_path()]);
