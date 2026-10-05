<?php
declare(strict_types=1);
/** GET /blocks/get.php?block= — one block as the editor sees it now: {version, html, type, content_rev} (a screen helper; no log). */
require_once dirname(__DIR__, 2) . '/app/features/blocks/handler.php';
require_login();
$pdo = db();
$id = (string) ($_GET['block'] ?? '');
$b = is_uuid($id) ? find_block($pdo, $id) : null;
if ($b === null) { respond_not_found('Block not found.'); }
$p = find_page($pdo, (string) $b['page_id']) ?? respond_not_found('Page not found.');
$editor = PAGE_LEVELS[page_level($p['page_id'])] >= PAGE_LEVELS[block_edit_level($p)] && !$p['is_locked'] && $p['archived_at'] === null;
header('Cache-Control: no-store');
json_response(['data' => ['block_id' => $b['block_id'], 'type' => $b['type'], 'version' => $b['version'], 'content' => $b['content'], 'html' => block_html($pdo, $b, ['editor' => $editor]), 'content_rev' => (int) one_value($pdo, 'SELECT content_rev FROM pages WHERE id = CAST(:p AS uuid)', ['p' => $p['page_id']])]]);
