<?php
declare(strict_types=1);
/** Action `comment_edit` (log `comment.edit`: length): own; the words change, the comment stays where and whose it is (the guard). */
require_once dirname(__DIR__, 3) . '/app/features/pages/handler.php';
require_once dirname(__DIR__, 3) . '/app/richtext/markdown.php';
require_once dirname(__DIR__, 3) . '/app/features/blocks/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/comments/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/comments/write.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$cid = (string) (req_val('comment') ?? '');
$c = is_uuid($cid) ? find_comment($pdo, $cid) : null;
if ($c === null) { refuse(404, 'Comment not found.'); }
if ($c['author_member_id'] !== $me) { refuse(403, 'Only the author edits a comment.'); }
$md = trim((string) (req_val('markdown') ?? ''));
if ($md === '' || mb_strlen($md) > 10000) { sp_refuse_fields(['markdown' => 'Say something (up to 10,000 characters).']); }
$p = find_page($pdo, $c['page_id']) ?? refuse(404, 'Page not found.');
$body = markdown_inline_runs($md, markdown_context($pdo));
sp_guard($pdo, static function () use ($pdo, $p, $cid, $body, $md): void {
    $pdo->beginTransaction();
    edit_comment($pdo, $cid, $body);
    log_activity($pdo, 'comment.edit', 'comment', $cid, ['space_id' => $p['space_id'], 'after' => ['page_id' => $p['page_id'], 'length' => mb_strlen($md)]]);
    $pdo->commit();
});
sp_done('Edited the comment', $cid, '/pages/' . $p['page_id'] . '#comment-' . $cid, 'commentChanged', ['page_id' => $p['page_id']]);
