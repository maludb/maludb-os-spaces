<?php
declare(strict_types=1);
/** Action `block_update` (log `block.update`: type, version): edit; the block, the version the editor read, the content (JSON) or markdown (one block's text), a type to turn it into. A stale version: 409 {error: {code: stale, version, html}}. */
require_once dirname(__DIR__, 2) . '/app/features/blocks/handler.php';
sp_handler_begin();
$pdo = db();
[$b, $p] = block_for_write($pdo);
$version = request_integer('version') ?? sp_refuse_fields(['version' => 'Send the version you read.']);
$content = null;
$type = null;
if (req_has('content') && (string) req_val('content') !== '') {
    $content = json_decode((string) req_val('content'), true);
    if (!is_array($content)) { sp_refuse_fields(['content' => 'The content is the type\'s JSON object.']); }
} elseif (req_has('markdown')) {
    $content = ['rich_text' => markdown_inline_runs((string) req_val('markdown'), markdown_context($pdo))] + array_diff_key($b['content'], ['rich_text' => 1]);
}
if (req_has('type') && (string) req_val('type') !== '' && (string) req_val('type') !== $b['type']) {
    $type = (string) req_val('type');
    if (!in_array($type, pg_text_array((string) one_value($pdo, 'SELECT sp_block_types()::text')), true)) { sp_refuse_fields(['type' => 'That is not a block type.']); }
    if ($type === 'code' && empty(($content ?? $b['content'])['language'])) { $content = ($content ?? $b['content']) + ['language' => 'plain']; }
}
if ($content === null && $type === null) { sp_refuse_fields(['content' => 'Say what changes: content, markdown or type.']); }
$before = ['type' => $b['type'], 'version' => $b['version']];
$v = block_guard($pdo, static function () use ($pdo, $b, $p, $version, $content, $type): int {
    $pdo->beginTransaction();
    $v = update_block($pdo, $b['block_id'], $version, $content, $type);
    block_log($pdo, 'block.update', $b['block_id'], $p, ['type' => $type ?? $b['type'], 'version' => $v], ['type' => $b['type'], 'version' => $b['version']]);
    $pdo->commit();
    return $v;
});
block_reply($pdo, $b['block_id'], $p, 'Saved the block');
