<?php
declare(strict_types=1);
/** /pages/new (screen `page-add`; params space, parent, template, private) and /pages/{id}/edit (screen `page-edit`: title, icon, cover). */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
require_login();
require_human();
$pdo = db();
$id = (string) ($_GET['id'] ?? ($_GET['page'] ?? ''));
$cur = null;
if ($id !== '') {
    $cur = find_page($pdo, $id) ?? refuse(404, 'Page not found.');
    require_page_level($id, 'edit');
    if ($cur['archived_at'] !== null) { refuse(422, 'Page "' . $cur['plain_title'] . '" is in the trash: restore it first.'); }
}
$screen = $cur === null ? 'page-add' : 'page-edit';
$pre = ['space' => request_integer('space'), 'parent' => is_uuid($_GET['parent'] ?? null) ? (string) $_GET['parent'] : null, 'template' => is_uuid($_GET['template'] ?? null) ? (string) $_GET['template'] : null, 'private' => request_bool('private')];
$spaces = $cur === null ? spaces_for_create($pdo) : [];
$parents = $cur === null ? pages_for_parent_pick($pdo, '', 150) : [];
$templates = $cur === null ? find_templates($pdo, $pre['space']) : [];
$emoji = $pdo->query('SELECT shortcode, emoji FROM mcp_emoji ORDER BY shortcode')->fetchAll();
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['page' => $cur === null ? null : present_page($cur), 'spaces' => $spaces, 'parents' => array_map(static fn (array $p): array => ['page_id' => $p['page_id'], 'title' => $p['plain_title'], 'space' => $p['space_name']], $parents),
        'templates' => array_map('present_template', $templates), 'preset' => $pre, 'may' => ['private' => has_right('pages.private')]]);
}
render_screen($cur === null ? 'New page' : 'Change ' . ($cur['plain_title'] ?: 'the page'), view('pages/form.php', ['cur' => $cur, 'spaces' => $spaces, 'parents' => $parents, 'templates' => $templates, 'emoji' => $emoji, 'pre' => $pre, 'mayPrivate' => has_right('pages.private'), 'here' => here_url()]),
    ['activeNav' => 'pages', 'screen' => $screen, 'entity' => 'page', 'recordId' => $id]);
