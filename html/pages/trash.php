<?php
declare(strict_types=1);
/** GET /pages/trash — my trash as cards, the admin's everyone's (screen `page-trash`; param space). POST — action `page_trash` (log `page.trash`: subtree_count; confirm; agent approval `deletion`): full. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    require_login();
    require_human();
    $pdo = db();
    $space = request_integer('space');
    $rows = trash_list($pdo, $space);
    $may = ['purge' => has_right('trash.purge')];
    log_screen_view($pdo, 'page-trash');
    if (wants_json()) {
        respond_screen(['trash' => array_map('present_trash', $rows), 'space' => $space, 'may' => $may]);
    }
    $notices = ['restored' => ['success', 'Restored.'], 'purged' => ['success', 'Deleted for good.'], 'emptied' => ['success', 'The trash is empty.']];
    render_screen('Trash', view('pages/trash.php', ['rows' => $rows, 'space' => $space, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, $notices)]),
        ['activeNav' => 'pages', 'screen' => 'page-trash', 'entity' => 'page']);
    exit;
}
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo);
require_page_level($p['page_id'], 'full');
$n = sp_guard($pdo, static function () use ($pdo, $me, $p): int {
    $pdo->beginTransaction();
    $n = trash_page($pdo, $p['page_id'], $me);
    page_log($pdo, 'page.trash', $p['page_id'], $p['space_id'], ['after' => ['title' => mb_substr($p['plain_title'], 0, 120), 'subtree_count' => $n]]);
    $pdo->commit();
    return $n;
});
sp_done('Moved ' . ($p['plain_title'] ?: 'the page') . ' to the trash' . ($n > 1 ? ' with ' . ($n - 1) . ' subpage' . ($n === 2 ? '' : 's') : ''), $p['page_id'], sp_land(return_path($p['space_id'] === null ? '/pages/' : '/spaces/' . $p['space_id']), 'trashed'), 'pageChanged', ['page_id' => $p['page_id'], 'subtree_count' => $n]);
