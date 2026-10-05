<?php
declare(strict_types=1);
/** Action `attachment_delete` (log `attachment.delete`; confirm; agent approval `deletion`): edit on the page (a block's, a cover's, a comment's); the row and the files go. */
require_once dirname(__DIR__, 2) . '/app/features/blocks/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/files/store.php';
require_once dirname(__DIR__, 2) . '/app/features/files/queries.php';
sp_handler_begin();
$pdo = db();
$id = request_integer('attachment') ?? refuse(422, 'Say which attachment.');
$a = attachment_for($pdo, $id) ?? refuse(404, 'Attachment not found.');
$pageId = null;
if ($a['record_type'] === 'block') { $pageId = one_value($pdo, 'SELECT page_id::text FROM mcp_blocks WHERE block_id = CAST(:b AS uuid)', ['b' => $a['record_uuid']]); }
elseif (in_array($a['record_type'], ['page_cover', 'page_icon', 'row_files'], true)) { $pageId = $a['record_uuid']; }
elseif ($a['record_type'] === 'comment') { $pageId = one_value($pdo, 'SELECT page_id::text FROM mcp_comments WHERE comment_id = CAST(:c AS uuid)', ['c' => $a['record_uuid']]); }
else { refuse(422, 'That attachment belongs to a record another slice owns.'); }
if ($pageId === null) { refuse(404, 'Attachment not found.'); }
$p = page_for_blocks($pdo, (string) $pageId);
$facts = sp_guard($pdo, static function () use ($pdo, $id, $p, $a): array {
    $pdo->beginTransaction();
    if ($a['record_type'] === 'block') {
        $b = find_block($pdo, (string) $a['record_uuid']);
        if ($b !== null) {
            $c = $b['content'];
            unset($c['attachment_id'], $c['url'], $c['width'], $c['height'], $c['name']);
            update_block($pdo, $b['block_id'], $b['version'], $c, null);
        }
    } elseif ($a['record_type'] === 'page_cover') {
        $pdo->prepare('UPDATE pages SET cover_attachment_id = NULL WHERE id = CAST(:p AS uuid)')->execute(['p' => $p['page_id']]);
    } elseif ($a['record_type'] === 'page_icon') {
        $pdo->prepare('UPDATE pages SET icon = NULL WHERE id = CAST(:p AS uuid) AND icon = :f')->execute(['p' => $p['page_id'], 'f' => 'file:' . $id]);
    }
    $facts = delete_attachment($pdo, $id);
    log_activity($pdo, 'attachment.delete', 'attachment', $id, ['space_id' => $p['space_id'], 'entity_uuid' => $facts['record_uuid'], 'after' => $facts + ['page_id' => $p['page_id']]]);
    $pdo->commit();
    return $facts;
});
sp_done('Removed ' . $facts['filename'], $id, '/pages/' . $p['page_id'], 'blockChanged', ['page_id' => $p['page_id']]);
