<?php
declare(strict_types=1);
/** /pages/{id} — the reader (screen `page-view`): breadcrumb, icon, cover, title, a row's properties, the block tree rendered, the page menu. A visit lands in recents and the trail. Slice 3 turns the body into the editor for someone with edit. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/blocks/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/comments/queries.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$id = (string) ($_GET['id'] ?? ($_GET['page'] ?? ''));
$p = find_page($pdo, $id) ?? refuse(404, 'Page not found.');
$tree = page_tree_json($pdo, $id);
$titles = page_titles_in_tree($pdo, $tree);
$embedHosts = pg_text_array((string) one_value($pdo, 'SELECT allowed_embed_hosts::text FROM sp_settings WHERE id = 1'));
$editLevel = $p['parent_database_id'] !== null ? 'edit_content' : 'edit';
$editor = PAGE_LEVELS[$p['my_level']] >= PAGE_LEVELS[$editLevel] && $p['archived_at'] === null && !$p['is_locked'] && !request_bool('reader');   // ?reader=1: the reader even for an editor (printing, proofs)
$html = render_blocks($tree, ['titles' => $titles, 'embed_hosts' => $embedHosts, 'editor' => $editor]);
$counts = comment_counts($pdo, $id);
$presence = $editor ? page_presence_touch($id, $me, (string) (current_member()['display_name'] ?? 'someone')) : [];
$pub = $p['is_published'] && in_array($p['my_level'], ['full'], true) ? publication($pdo, $id) : null;
$level = $p['my_level'];
$rank = PAGE_LEVELS[$level] ?? 0;
$may = ['edit' => $rank >= 4 && $p['archived_at'] === null, 'full' => $rank >= 5, 'comment' => $rank >= 2, 'publish' => has_right('publish.web') && $p['archived_at'] === null, 'export' => has_right('export.own'),
        'verify' => $p['archived_at'] === null && $p['space_id'] !== null && db_bool($pdo, 'SELECT is_wiki FROM spaces WHERE id = :s', ['s' => $p['space_id']]) && ($p['wiki_owner_member_id'] === $me || db_bool($pdo, 'SELECT sp_is_space_owner(:s)', ['s' => $p['space_id']])),
        'trash' => $rank >= 5 && $p['archived_at'] === null, 'restore' => $rank >= 5 && $p['archived_at'] !== null, 'purge' => ($rank >= 5 || has_right('trash.purge')) && $p['archived_at'] !== null, 'template' => $p['space_id'] !== null && db_bool($pdo, 'SELECT sp_is_space_owner(:s)', ['s' => $p['space_id']])];
if ($p['archived_at'] === null) {
    $pdo->prepare('SELECT sp_page_visited(CAST(:id AS uuid))')->execute(['id' => $id]);
}
page_log($pdo, 'page.view', $id, $p['space_id'], ['after' => ['title' => mb_substr($p['plain_title'], 0, 120)]]);
log_screen_view($pdo, 'page-view');
if (wants_json()) {
    respond_screen(['page' => present_page($p), 'blocks' => $tree, 'markdown' => (string) one_value($pdo, 'SELECT sp_page_markdown(CAST(:id AS uuid), false)', ['id' => $id]), 'publication' => present_publication($pub), 'editor' => $editor, 'comment_counts' => $counts, 'may' => $may]);
}
$bodyHtml = $editor ? view('pages/editor.php', ['p' => $p, 'tree' => $tree, 'bodyHtml' => $html, 'settings' => editor_settings($pdo), 'counts' => $counts, 'me' => $me, 'presence' => $presence]) : $html;
render_screen($p['plain_title'] !== '' ? $p['plain_title'] : 'Untitled', view('pages/view.php', ['p' => $p, 'bodyHtml' => $bodyHtml, 'editor' => $editor, 'counts' => $counts, 'pub' => $pub, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, page_notices($p))]),
    ['activeNav' => 'pages', 'screen' => 'page-view', 'entity' => 'page', 'recordId' => $id]);
