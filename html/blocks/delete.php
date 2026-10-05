<?php
declare(strict_types=1);
/** Action `block_delete` (log `block.delete`: type, had_children, count; confirm; agent approval `deletion`): edit; the block and its children. */
require_once dirname(__DIR__, 2) . '/app/features/blocks/handler.php';
sp_handler_begin();
$pdo = db();
[$b, $p] = block_for_write($pdo);
$n = block_guard($pdo, static function () use ($pdo, $b, $p): int {
    $pdo->beginTransaction();
    block_log($pdo, 'block.delete', $b['block_id'], $p, ['type' => $b['type'], 'had_children' => $b['has_children']]);
    $n = delete_block($pdo, $b['block_id']);
    $pdo->commit();
    return $n;
});
$rev = (int) one_value($pdo, 'SELECT content_rev FROM pages WHERE id = CAST(:p AS uuid)', ['p' => $p['page_id']]);
sp_done('Removed the block' . ($n > 1 ? ' and ' . ($n - 1) . ' inside it' : ''), $b['block_id'], '/pages/' . $p['page_id'], 'blockChanged', ['count' => $n, 'content_rev' => $rev]);
