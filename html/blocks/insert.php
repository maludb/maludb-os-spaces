<?php
declare(strict_types=1);
/** Action `block_insert` (log `block.insert`: type, parent_block_id): edit; a block of a type with its content (empty for a blank one), under a parent, after a sibling (or first with at_start). A synced copy by `synced_from`. */
require_once dirname(__DIR__, 2) . '/app/features/blocks/handler.php';
sp_handler_begin();
$pdo = db();
$p = page_for_blocks($pdo);
$type = (string) (req_val('type') ?? '');
$types = pg_text_array((string) one_value($pdo, 'SELECT sp_block_types()::text'));
if (!in_array($type, $types, true)) { sp_refuse_fields(['type' => 'The type is one of: ' . implode(', ', $types) . '.']); }
$content = [];
if (req_has('content') && (string) req_val('content') !== '') {
    $content = json_decode((string) req_val('content'), true);
    if (!is_array($content)) { sp_refuse_fields(['content' => 'The content is the type\'s JSON object.']); }
} elseif (req_has('markdown') && (string) req_val('markdown') !== '') {
    $content = ['rich_text' => markdown_inline_runs((string) req_val('markdown'), markdown_context($pdo))];
}
if ($type === 'code' && empty($content['language'])) { $content['language'] = 'plain'; }
$parent = req_has('parent') && (string) req_val('parent') !== '' ? (string) req_val('parent') : null;
$after = req_has('after') && (string) req_val('after') !== '' ? (string) req_val('after') : null;
$syncedFrom = req_has('synced_from') && (string) req_val('synced_from') !== '' ? (string) req_val('synced_from') : null;
foreach (['parent' => $parent, 'after' => $after] as $k => $v) {
    if ($v !== null && (!is_uuid($v) || !db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM mcp_blocks WHERE block_id = CAST(:b AS uuid) AND page_id = CAST(:p AS uuid))', ['b' => $v, 'p' => $p['page_id']]))) { sp_refuse_fields([$k => 'That block is not on this page.']); }
}
if ($syncedFrom !== null && (!is_uuid($syncedFrom) || find_block($pdo, $syncedFrom) === null)) { sp_refuse_fields(['synced_from' => 'That synced block is not one you may see.']); }
$id = block_guard($pdo, static function () use ($pdo, $p, $type, $content, $parent, $after, $syncedFrom): string {
    $pdo->beginTransaction();
    $id = insert_block($pdo, $p['page_id'], $type, $content, $parent, $after, sp_yes('at_start'), $syncedFrom);
    block_log($pdo, 'block.insert', $id, $p, ['type' => $type, 'parent_block_id' => $parent, 'after' => $after, 'synced_from' => $syncedFrom]);
    $pdo->commit();
    return $id;
});
block_reply($pdo, $id, $p, 'Added a ' . str_replace('_', ' ', $type) . ' block');
