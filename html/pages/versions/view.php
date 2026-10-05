<?php
declare(strict_types=1);
/** /pages/{id}/versions/{version} — one version rendered as it was, with a line diff against the present or ?against= another version (screen `page-version`). */
require_once dirname(__DIR__, 3) . '/app/features/pages/handler.php';
require_once dirname(__DIR__, 3) . '/app/richtext/markdown.php';
require_once dirname(__DIR__, 3) . '/app/richtext/diff.php';
require_once dirname(__DIR__, 3) . '/app/features/blocks/queries.php';
require_login();
require_human();
$pdo = db();
$id = (string) ($_GET['id'] ?? ($_GET['page'] ?? ''));
$p = find_page($pdo, $id) ?? refuse(404, 'Page not found.');
require_page_level($id, 'view');
$vn = request_integer('version') ?? refuse(404, 'Version not found.');
$st = $pdo->prepare('SELECT version_id, version_no, reason, saved_by, (SELECT display_name FROM members WHERE id = v.saved_by) AS saved_by_name, created_at FROM mcp_page_versions v WHERE page_id = CAST(:p AS uuid) AND version_no = :n');
$st->execute(['p' => $id, 'n' => $vn]);
$v = $st->fetch() ?: refuse(404, 'Version not found.');
$md = (string) (one_value($pdo, 'SELECT sp_version_markdown(:v)', ['v' => (int) $v['version_id']]) ?? '');
$against = request_integer('against');
$otherLabel = 'the present';
if ($against !== null) {
    $o = $pdo->prepare('SELECT version_id FROM mcp_page_versions WHERE page_id = CAST(:p AS uuid) AND version_no = :n');
    $o->execute(['p' => $id, 'n' => $against]);
    $ov = $o->fetchColumn() ?: refuse(404, 'Version not found.');
    $other = (string) (one_value($pdo, 'SELECT sp_version_markdown(:v)', ['v' => (int) $ov]) ?? '');
    $otherLabel = 'version ' . $against;
} else {
    $other = (string) one_value($pdo, 'SELECT sp_page_markdown(CAST(:p AS uuid), true)', ['p' => $id]);
}
$diff = line_diff($md, $other);
$tree = markdown_to_blocks(preg_replace('/^# .*\n+/', '', $md, 1) ?? $md, markdown_context($pdo));
$html = render_blocks($tree, ['titles' => page_titles_in_tree($pdo, $tree), 'embed_hosts' => pg_text_array((string) one_value($pdo, 'SELECT allowed_embed_hosts::text FROM sp_settings WHERE id = 1'))]);
$may = ['restore' => has_full_page($id) && $p['archived_at'] === null && !$p['is_locked']];
log_screen_view($pdo, 'page-version');
if (wants_json()) {
    respond_screen(['page' => present_page($p), 'version' => ['version_id' => (int) $v['version_id'], 'version_no' => (int) $v['version_no'], 'reason' => $v['reason'], 'saved_by_name' => $v['saved_by_name'], 'created_at' => json_ts($v['created_at'])], 'markdown' => $md, 'against' => $otherLabel, 'diff' => $diff, 'may' => $may]);
}
$changed = count(array_filter($diff, static fn (array $d): bool => $d['op'] !== ' '));
render_screen('Version ' . $vn . ' of ' . ($p['plain_title'] ?: 'the page'), view('pages/version.php', ['p' => $p, 'v' => $v, 'bodyHtml' => $html, 'diff' => $diff, 'changed' => $changed, 'otherLabel' => $otherLabel, 'against' => $against, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone()]),
    ['activeNav' => 'pages', 'screen' => 'page-version', 'entity' => 'page', 'recordId' => $id]);
