<?php
declare(strict_types=1);
/** Action `comment_resolve` (log `comment.resolve`: resolved): comment on the page; a discussion's first comment; resolved yes (default) or no reopens. */
require_once dirname(__DIR__, 3) . '/app/features/pages/handler.php';
require_once dirname(__DIR__, 3) . '/app/features/comments/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/comments/write.php';
sp_handler_begin();
$pdo = db();
$cid = (string) (req_val('comment') ?? '');
$c = is_uuid($cid) ? find_comment($pdo, $cid) : null;
if ($c === null) { refuse(404, 'Comment not found.'); }
$p = find_page($pdo, $c['page_id']) ?? refuse(404, 'Page not found.');
require_page_level($p['page_id'], 'comment');
$resolved = sp_yes('resolved', true);
sp_guard($pdo, static function () use ($pdo, $p, $cid, $resolved): void {
    $pdo->beginTransaction();
    resolve_comment($pdo, $cid, $resolved);
    log_activity($pdo, 'comment.resolve', 'comment', $cid, ['space_id' => $p['space_id'], 'after' => ['page_id' => $p['page_id'], 'resolved' => $resolved]]);
    $pdo->commit();
});
sp_done($resolved ? 'Resolved the discussion' : 'Reopened the discussion', $cid, '/pages/' . $p['page_id'] . '#comment-' . $cid, 'commentChanged', ['page_id' => $p['page_id'], 'resolved' => $resolved]);
