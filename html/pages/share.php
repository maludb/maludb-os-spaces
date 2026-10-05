<?php
declare(strict_types=1);
/** GET /pages/{id}/share — who sees the page and why (screen `page-share`). POST — action `page_share_member` (log `page.share`: principal_kind, principal_id, level): full; a member (a person or an agent) or a department at a level. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    require_login();
    require_human();
    $pdo = db();
    $id = (string) ($_GET['id'] ?? ($_GET['page'] ?? ''));
    $p = find_page($pdo, $id) ?? refuse(404, 'Page not found.');
    require_page_level($id, 'full');
    $rows = page_permissions($pdo, $id);
    $pub = publication($pdo, $id);
    $picks = members_for_pick($pdo);
    $guests = has_right('share.guest') ? guests_for_pick($pdo) : [];
    $departments = find_live_departments($pdo);
    $restricted = $p['permission_root_id'] === $id && !db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM mcp_page_permissions WHERE page_id = CAST(:id AS uuid) AND principal_kind IN ('everyone_in_space', 'everyone'))", ['id' => $id]) && $p['space_id'] !== null;
    log_screen_view($pdo, 'page-share');
    if (wants_json()) {
        respond_screen(['page' => present_page($p), 'permissions' => array_map('present_permission', $rows), 'restricted' => $restricted, 'publication' => present_publication($pub), 'may' => ['guest' => has_right('share.guest'), 'publish' => has_right('publish.web')]]);
    }
    render_screen('Share ' . ($p['plain_title'] ?: 'the page'), view('pages/share.php', ['p' => $p, 'rows' => $rows, 'pub' => $pub, 'picks' => $picks, 'guests' => $guests, 'departments' => $departments, 'restricted' => $restricted,
        'may' => ['guest' => has_right('share.guest'), 'publish' => has_right('publish.web')], 'here' => here_url(), 'notice' => sp_notice($_GET['notice'] ?? null, page_notices($p))]),
        ['activeNav' => 'pages', 'screen' => 'page-share', 'entity' => 'page', 'recordId' => $id]);
    exit;
}
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo);
require_page_level($p['page_id'], 'full');
$errors = [];
$member = sp_ref($pdo, 'member', null, "SELECT 1 FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL", 'member', $errors);
$department = sp_ref($pdo, 'department', null, 'SELECT 1 FROM mcp_departments WHERE department_id = :id AND archived_at IS NULL', 'department', $errors);
$level = (string) (req_val('level') ?? '');
if (!isset(PAGE_LEVEL_WORDS[$level])) { $errors['level'] = 'The level is view, comment, edit_content, edit or full.'; }
if ($member === null && $department === null && $errors === []) { $errors['member'] = 'Say whom: a member or a department.'; }
if ($errors !== []) { sp_refuse_fields($errors); }
sp_guard($pdo, static function () use ($pdo, $me, $p, $member, $department, $level): void {
    $pdo->beginTransaction();
    share_page($pdo, $p['page_id'], $member !== null ? 'member' : 'department', $member ?? $department, $level, $me);
    $kind = $member !== null ? (string) one_value($pdo, 'SELECT member_kind FROM members WHERE id = :m', ['m' => $member]) : 'department';
    page_log($pdo, 'page.share', $p['page_id'], $p['space_id'], ['after' => ['principal_kind' => $kind, 'principal_id' => $member ?? $department, 'level' => $level]] + ($department !== null ? ['department_id' => $department] : []));
    $pdo->commit();
});
sp_done('Shared ' . ($p['plain_title'] ?: 'the page') . ' at ' . strtolower(PAGE_LEVEL_WORDS[$level]), $p['page_id'], sp_land(return_path('/pages/' . $p['page_id'] . '/share'), 'shared'), 'pageChanged', ['page_id' => $p['page_id']]);
