<?php
declare(strict_types=1);
/** GET /pages/{id}/move — the destination picker (screen `page-move`). POST — action `page_move` (log `page.move`): full on the page and edit at the destination; parent, or space (its root), or private; after (the sibling it follows). */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    require_login();
    require_human();
    $pdo = db();
    $id = (string) ($_GET['id'] ?? ($_GET['page'] ?? ''));
    $p = find_page($pdo, $id) ?? refuse(404, 'Page not found.');
    require_page_level($id, 'full');
    $spaces = spaces_for_create($pdo);
    $parents = array_values(array_filter(pages_for_parent_pick($pdo, '', 150), static fn (array $x): bool => $x['page_id'] !== $id));
    log_screen_view($pdo, 'page-move');
    if (wants_json()) {
        respond_screen(['page' => present_page($p), 'spaces' => $spaces, 'parents' => array_map(static fn (array $x): array => ['page_id' => $x['page_id'], 'title' => $x['plain_title'], 'space' => $x['space_name']], $parents), 'may' => ['private' => has_right('pages.private')]]);
    }
    render_screen('Move ' . ($p['plain_title'] ?: 'the page'), view('pages/move.php', ['p' => $p, 'spaces' => $spaces, 'parents' => $parents, 'mayPrivate' => has_right('pages.private'), 'here' => here_url()]),
        ['activeNav' => 'pages', 'screen' => 'page-move', 'entity' => 'page', 'recordId' => $id]);
    exit;
}
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo);
require_page_level($p['page_id'], 'full');
$parent = req_has('parent') && (string) req_val('parent') !== '' ? (string) req_val('parent') : null;
$space = req_has('space') && (string) req_val('space') !== '' ? request_integer('space') : null;
$private = sp_yes('private');
$after = req_has('after') && (string) req_val('after') !== '' ? (string) req_val('after') : null;
if ($parent !== null && !is_uuid($parent)) { sp_refuse_fields(['parent' => 'The parent is named by its id.']); }
if ($after !== null && !is_uuid($after)) { sp_refuse_fields(['after' => 'The page to follow is named by its id.']); }
if ($parent !== null) {
    $pp = find_page($pdo, $parent) ?? refuse(404, 'Parent page not found.');
    require_page_level($parent, 'edit', 'Destination page');
    $space = $pp['space_id'];
} elseif ($private) {
    require_right('pages.private');
} elseif ($space !== null) {
    $sn = one_value($pdo, 'SELECT name FROM mcp_spaces WHERE space_id = :s AND archived_at IS NULL', ['s' => $space]) ?? refuse(404, 'Space not found.');
    if (!db_bool($pdo, 'SELECT sp_level_rank(sp_space_level(:s)) >= 4', ['s' => $space])) { refuse(403, 'You may not create pages in ' . $sn . '.'); }
} elseif ($after === null) {
    sp_refuse_fields(['parent' => 'Say where: a parent page, a space, or private.']);
} else {
    $space = $p['space_id'];
    $parent = $p['parent_page_id'];
}
$before = ['parent_page_id' => $p['parent_page_id'], 'space_id' => $p['space_id']];
sp_guard($pdo, static function () use ($pdo, $me, $p, $parent, $space, $private, $after, $before): void {
    $pdo->beginTransaction();
    if ($parent !== $p['parent_page_id'] || $space !== $p['space_id'] || $private) {
        move_page($pdo, $p['page_id'], $parent, $space, $private, $after, $me);
    } elseif ($after !== null) {
        reorder_page($pdo, $p['page_id'], $after);
    }
    $now = page_state($pdo, $p['page_id']);
    page_log($pdo, 'page.move', $p['page_id'], $now['space_id'], ['before' => $before, 'after' => ['parent_page_id' => $now['parent_page_id'], 'space_id' => $now['space_id'], 'private' => $now['space_id'] === null, 'after' => $after]]);
    $pdo->commit();
});
sp_done('Moved ' . ($p['plain_title'] ?: 'the page'), $p['page_id'], sp_land(return_path('/pages/' . $p['page_id']), 'moved'), 'pageChanged', ['page_id' => $p['page_id']]);
