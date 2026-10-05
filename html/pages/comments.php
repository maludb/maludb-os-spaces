<?php
declare(strict_types=1);
/** GET /pages/comments.php?page=&block= — the comment pane (the right pane at 1280, a full page at 375): the page's discussions (or one block's) with their replies and the forms. A screen helper; polled every 20 s. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/comments/queries.php';
require_login();
$pdo = db();
$me = (int) current_member_id();
$id = (string) ($_GET['page'] ?? '');
$p = find_page($pdo, $id) ?? refuse(404, 'Page not found.');
require_page_level($id, 'view');
$block = is_uuid($_GET['block'] ?? null) ? (string) $_GET['block'] : null;
$all = page_comments($pdo, $id, request_bool('open'));
$rows = $block === null ? $all : array_values(array_filter($all, static fn (array $c): bool => $c['block_id'] === $block));
$may = ['comment' => can_comment_page($id) && $p['archived_at'] === null, 'full' => has_full_page($id)];
if (wants_json()) {
    respond_screen(['page_id' => $id, 'block_id' => $block, 'comments' => array_map('present_comment', $rows), 'may' => $may]);
}
header('Cache-Control: no-store');
header('X-Pane-Title: Comments');
echo view('pages/partials/comment-pane.php', ['p' => $p, 'rows' => $rows, 'block' => $block, 'me' => $me, 'may' => $may, 'tz' => member_timezone()]);
