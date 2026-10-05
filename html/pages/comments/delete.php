<?php
declare(strict_types=1);
/** Action `comment_delete` (log `comment.delete`; confirm; agent approval `deletion`): own, or full on the page. The words go; the row stays as a tombstone. */
require_once dirname(__DIR__, 3) . '/app/features/pages/handler.php';
require_once dirname(__DIR__, 3) . '/app/features/comments/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/comments/write.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$cid = (string) (req_val('comment') ?? '');
$c = is_uuid($cid) ? find_comment($pdo, $cid) : null;
if ($c === null) { refuse(404, 'Comment not found.'); }
$p = find_page($pdo, $c['page_id']) ?? refuse(404, 'Page not found.');
if ($c['author_member_id'] !== $me && !has_full_page($p['page_id'])) { refuse(403, 'Only the author, or someone with full access to the page, deletes a comment.'); }
sp_guard($pdo, static function () use ($pdo, $p, $cid, $c): void {
    $pdo->beginTransaction();
    delete_comment($pdo, $cid);
    log_activity($pdo, 'comment.delete', 'comment', $cid, ['space_id' => $p['space_id'], 'after' => ['page_id' => $p['page_id'], 'block_id' => $c['block_id'], 'parent_comment_id' => $c['parent_comment_id']]]);
    $pdo->commit();
});
sp_done('Deleted the comment', $cid, '/pages/' . $p['page_id'], 'commentChanged', ['page_id' => $p['page_id']]);
