<?php
declare(strict_types=1);
/** /templates/ — the templates gallery (screen `template-list`): the workspace's (General's) and my spaces'; Use one (→ page-add); a space owner's Stop. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
require_login();
require_human();
$pdo = db();
$rows = find_templates($pdo);
$owned = [];
foreach ($rows as $t) {
    if ($t['space_id'] !== null && !isset($owned[$t['space_id']])) { $owned[$t['space_id']] = db_bool($pdo, 'SELECT sp_is_space_owner(:s)', ['s' => $t['space_id']]); }
}
log_screen_view($pdo, 'template-list');
if (wants_json()) {
    respond_screen(['templates' => array_map('present_template', $rows)]);
}
render_screen('Templates', view('templates/index.php', ['rows' => $rows, 'owned' => $owned, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, ['template' => ['success', 'Saved.']])]), ['activeNav' => 'pages', 'screen' => 'template-list', 'entity' => 'page']);
