<?php
declare(strict_types=1);
/** Action `block_move` (log `block.move`: parent_block_id, after): edit; a new parent (empty for the root) and/or a place among siblings (after; empty for first). The structural rules are the database's. */
require_once dirname(__DIR__, 2) . '/app/features/blocks/handler.php';
sp_handler_begin();
$pdo = db();
[$b, $p] = block_for_write($pdo);
$parent = req_has('parent') && (string) req_val('parent') !== '' ? (string) req_val('parent') : null;
$after = req_has('after') && (string) req_val('after') !== '' ? (string) req_val('after') : null;
foreach (['parent' => $parent, 'after' => $after] as $k => $v) {
    if ($v !== null && (!is_uuid($v) || !db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM mcp_blocks WHERE block_id = CAST(:b AS uuid) AND page_id = CAST(:p AS uuid))', ['b' => $v, 'p' => $p['page_id']]))) { sp_refuse_fields([$k => 'That block is not on this page.']); }
}
block_guard($pdo, static function () use ($pdo, $b, $p, $parent, $after): void {
    $pdo->beginTransaction();
    move_block($pdo, $b['block_id'], $parent, $after);
    block_log($pdo, 'block.move', $b['block_id'], $p, ['parent_block_id' => $parent, 'after' => $after], ['parent_block_id' => $b['parent_block_id']]);
    $pdo->commit();
});
block_reply($pdo, $b['block_id'], $p, 'Moved the block');
