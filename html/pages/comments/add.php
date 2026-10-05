<?php
declare(strict_types=1);
/** Action `comment_add` (log `comment.add`: comment_id, block_id, parent_comment_id, length — never the words): comment on the page; `block` for an inline one, `parent` for a reply. A mention tells (the trigger). */
require_once dirname(__DIR__, 3) . '/app/features/pages/handler.php';
require_once dirname(__DIR__, 3) . '/app/richtext/markdown.php';
require_once dirname(__DIR__, 3) . '/app/features/blocks/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/comments/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/comments/write.php';
sp_handler_begin();
$pdo = db();
$p = page_from_request($pdo);
require_page_level($p['page_id'], 'comment');
$md = trim((string) (req_val('markdown') ?? ''));
if ($md === '' || mb_strlen($md) > 10000) { sp_refuse_fields(['markdown' => 'Say something (up to 10,000 characters).']); }
$block = req_has('block') && (string) req_val('block') !== '' ? (string) req_val('block') : null;
$parent = req_has('parent') && (string) req_val('parent') !== '' ? (string) req_val('parent') : null;
if ($block !== null && (!is_uuid($block) || find_block($pdo, $block) === null)) { sp_refuse_fields(['block' => 'That block is not on a page you may see.']); }
if ($parent !== null && (!is_uuid($parent) || find_comment($pdo, $parent) === null)) { sp_refuse_fields(['parent' => 'That discussion is not one you may see.']); }
$body = markdown_inline_runs($md, markdown_context($pdo));
$id = sp_guard($pdo, static function () use ($pdo, $p, $block, $parent, $body, $md): string {
    $pdo->beginTransaction();
    $id = add_comment($pdo, $p['page_id'], $block, $parent, $body);
    log_activity($pdo, 'comment.add', 'comment', $id, ['space_id' => $p['space_id'], 'after' => ['page_id' => $p['page_id'], 'comment_id' => $id, 'block_id' => $block, 'parent_comment_id' => $parent, 'length' => mb_strlen($md)]]);
    $pdo->commit();
    return $id;
});
sp_done($parent === null ? 'Started a discussion' : 'Replied', $id, '/pages/' . $p['page_id'] . '#comment-' . $id, 'commentChanged', ['page_id' => $p['page_id'], 'block_id' => $block, 'parent_comment_id' => $parent]);
