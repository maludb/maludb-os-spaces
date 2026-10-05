<?php
declare(strict_types=1);
/** /spaces/{id}/sections — the sidebar's sections of the space in order with their root pages (screen `space-sections`): add, rename, drag to reorder, move a page between sections, delete. */
require_once dirname(__DIR__, 2) . '/app/features/spaces/handler.php';
require_login();
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('space') ?? refuse(404, 'Space not found.');
$s = find_space($pdo, $id) ?? refuse(404, 'Space not found.');
require_space_owner($s);
$home = space_home($pdo, $id);
log_screen_view($pdo, 'space-sections');
if (wants_json()) {
    respond_screen(['space' => present_space($s), 'sections' => array_map('present_section', $home['sections']), 'pages' => array_map('present_root_page', $home['pages'])]);
}
$html = view('spaces/sections.php', ['s' => $s, 'sections' => $home['sections'], 'loose' => $home['pages'], 'here' => here_url(), 'notice' => sp_notice($_GET['notice'] ?? null, space_notices($s))]);
if (is_htmx_request() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'section-list') {
    header('Vary: HX-Request');
    echo view('spaces/partials/section-list.php', ['s' => $s, 'sections' => $home['sections'], 'loose' => $home['pages'], 'here' => here_url()]);
    exit;
}
render_screen($s['name'] . ' · Sections', $html, ['activeNav' => 'spaces', 'screen' => 'space-sections', 'entity' => 'space', 'recordId' => (string) $id]);
