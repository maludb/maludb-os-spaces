<?php
declare(strict_types=1);
/**
 * Action `file_upload` (log `attachment.add`: attachment_id, record_type, filename, mime_type, byte_size): a multipart `file` on a record — `block` (edit on its page;
 * the block's content gains attachment_id, url, width, height), `page` (its cover, or icon with `as=icon`; edit), `comment` (its author). A message (slice 4)
 * and a row's files property (slice 5) are theirs. A refused type: 422 in words.
 */
require_once dirname(__DIR__, 2) . '/app/features/blocks/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/files/store.php';
require_once dirname(__DIR__, 2) . '/app/features/files/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/comments/queries.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$file = $_FILES['file'] ?? null;
if (!is_array($file)) { sp_refuse_fields(['file' => 'Send the file as multipart `file`.']); }
$block = req_has('block') && (string) req_val('block') !== '' ? (string) req_val('block') : null;
$page = req_has('page') && (string) req_val('page') !== '' ? (string) req_val('page') : null;
$comment = req_has('comment') && (string) req_val('comment') !== '' ? (string) req_val('comment') : null;
$message = req_has('message') && (string) req_val('message') !== '' ? request_integer('message') : null;
$rowId = req_has('row') && (string) req_val('row') !== '' ? (string) req_val('row') : null;
if ($message !== null) {
    // slice 4: a file for a message being composed in a channel — a pending slot (record_id 0) the post re-points to the message
    require_once dirname(__DIR__, 2) . '/app/features/channels/queries.php';
    $ch = find_channel($pdo, $message) ?? refuse(404, 'Channel not found.');
    if ($ch['archived_at'] !== null || !can_post($message)) { refuse(403, 'You may not post in ' . $ch['label'] . '.'); }
    $a = sp_guard($pdo, static function () use ($pdo, $me, $file, $ch): array {
        $pdo->beginTransaction();
        $a = store_attachment($pdo, $file, 'message', 0, $me);
        log_activity($pdo, 'attachment.add', 'attachment', $a['id'], ['channel_id' => $ch['channel_id'], 'space_id' => $ch['space_id'], 'after' => ['attachment_id' => $a['id'], 'record_type' => 'message', 'filename' => $a['filename'], 'mime_type' => $a['mime_type'], 'byte_size' => $a['byte_size'], 'channel_id' => $ch['channel_id']]]);
        $pdo->commit();
        return $a;
    });
    sp_done('Attached ' . $a['filename'], (int) $a['id'], '/channels/' . $ch['channel_id'], 'blockChanged', ['attachment' => ['attachment_id' => (int) $a['id'], 'filename' => $a['filename'], 'mime_type' => $a['mime_type'], 'byte_size' => $a['byte_size'], 'url' => '/files/' . $a['id']], 'channel_id' => $ch['channel_id']]);
}
if ($rowId !== null) {
    // slice 5: a file on a row's `files` property — an attachment of kind row_files, its id appended to the property
    require_once dirname(__DIR__, 2) . '/app/features/databases/handler.php';
    $row = is_uuid($rowId) ? find_row($pdo, $rowId) : null;
    $row ?? refuse(404, 'Row not found.');
    require_page_level($row['page_id'], 'edit_content', 'Row');
    $d = $row['database'] ?? refuse(404, 'Database not found.');
    $pk = resolve_property_key($pdo, $d['properties'], (string) (req_val('property') ?? ''));
    if ($pk === null || ($d['properties'][$pk]['type'] ?? '') !== 'files') { sp_refuse_fields(['property' => 'Name a files property of the database.']); }
    $a = sp_guard($pdo, static function () use ($pdo, $me, $file, $row, $d, $pk): array {
        $pdo->beginTransaction();
        $a = store_attachment($pdo, $file, 'row_files', $row['page_id'], $me);
        $have = array_map(static fn ($x): int => (int) (is_array($x) ? ($x['id'] ?? 0) : $x), (array) (row_stored($pdo, $row['page_id'])[$pk] ?? []));
        update_row($pdo, $row['page_id'], [$pk => array_values(array_unique(array_merge($have, [(int) $a['id']])))], $me);
        log_activity($pdo, 'attachment.add', 'attachment', $a['id'], ['space_id' => $row['space_id'], 'entity_uuid' => $row['page_id'], 'after' => ['attachment_id' => $a['id'], 'record_type' => 'row_files', 'filename' => $a['filename'], 'mime_type' => $a['mime_type'], 'byte_size' => $a['byte_size'], 'page_id' => $row['page_id'], 'key' => $pk]]);
        database_log($pdo, 'row.update', 'row', $row['page_id'], $row['space_id'], ['after' => ['database_id' => $d['database_id'], 'keys' => [$pk]]]);
        $pdo->commit();
        return $a;
    });
    $arow = attachment_for($pdo, (int) $a['id']);
    sp_done('Attached ' . $a['filename'], (int) $a['id'], sp_land('/databases/' . $d['database_id'] . '/rows/' . $row['page_id'], 'uploaded'), 'rowChanged', ['attachment' => $arow === null ? null : present_attachment($arow), 'row_id' => $row['page_id'], 'key' => $pk]);
}
if ($block !== null) {
    [$b, $p] = block_for_write($pdo, $block);
    $kind = 'block';
    $record = $block;
} elseif ($page !== null) {
    $p = page_for_blocks($pdo, $page);
    $kind = (string) (req_val('as') ?? 'cover') === 'icon' ? 'page_icon' : 'page_cover';
    $record = $page;
    $b = null;
} elseif ($comment !== null) {
    $c = is_uuid($comment) ? find_comment($pdo, $comment) : null;
    if ($c === null) { refuse(404, 'Comment not found.'); }
    if ($c['author_member_id'] !== $me) { refuse(403, 'Only the author attaches a file to a comment.'); }
    $p = find_page($pdo, $c['page_id']) ?? refuse(404, 'Page not found.');
    $kind = 'comment';
    $record = $comment;
    $b = null;
} else {
    sp_refuse_fields(['block' => 'Say which record the file belongs to: a block, a page or a comment.']);
}
$a = sp_guard($pdo, static function () use ($pdo, $me, $file, $kind, $record, $p, $b): array {
    $pdo->beginTransaction();
    $a = store_attachment($pdo, $file, $kind, $record, $me);
    if ($kind === 'block' && $b !== null) {
        $content = $b['content'];
        $content['attachment_id'] = $a['id'];
        $content['url'] = '/files/' . $a['id'];
        $content['name'] = $a['filename'];
        if ($a['width'] !== null) { $content['width'] = $a['width']; $content['height'] = $a['height']; }
        unset($content['external']);
        if (!in_array($b['type'], ['image', 'file', 'pdf', 'video', 'audio'], true)) {
            $t = str_starts_with($a['mime_type'], 'image/') ? 'image' : (str_starts_with($a['mime_type'], 'video/') ? 'video' : (str_starts_with($a['mime_type'], 'audio/') ? 'audio' : ($a['mime_type'] === 'application/pdf' ? 'pdf' : 'file')));
            update_block($pdo, $b['block_id'], $b['version'], $content, $t);
        } else {
            update_block($pdo, $b['block_id'], $b['version'], $content, null);
        }
    } elseif ($kind === 'page_cover') {
        $pdo->prepare('UPDATE pages SET cover_attachment_id = :a WHERE id = CAST(:p AS uuid)')->execute(['a' => $a['id'], 'p' => $p['page_id']]);
    } elseif ($kind === 'page_icon') {
        $pdo->prepare("UPDATE pages SET icon = 'file:' || :a WHERE id = CAST(:p AS uuid)")->execute(['a' => $a['id'], 'p' => $p['page_id']]);
    }
    log_activity($pdo, 'attachment.add', 'attachment', $a['id'], ['space_id' => $p['space_id'], 'entity_uuid' => is_uuid($record) ? $record : null, 'after' => ['attachment_id' => $a['id'], 'record_type' => $kind, 'filename' => $a['filename'], 'mime_type' => $a['mime_type'], 'byte_size' => $a['byte_size'], 'page_id' => $p['page_id']]]);
    $pdo->commit();
    return $a;
});
$row = attachment_for($pdo, (int) $a['id']);
$extra = ['attachment' => $row === null ? null : present_attachment($row), 'page_id' => $p['page_id']];
if ($kind === 'block' && $b !== null) {
    $nb = find_block($pdo, $b['block_id']);
    $extra += ['block_id' => $b['block_id'], 'version' => $nb['version'] ?? null, 'html' => $nb === null ? '' : block_html($pdo, $nb, ['editor' => true])];
}
sp_done('Attached ' . $a['filename'], (int) $a['id'], '/pages/' . $p['page_id'], 'blockChanged', $extra);
