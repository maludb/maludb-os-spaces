<?php
declare(strict_types=1);
/** "+ column" on a table (a screen helper, logged block.update per row): a cell at `at` (or the end) in every row. Answers the table's HTML. */
require_once dirname(__DIR__, 2) . '/app/features/blocks/handler.php';
sp_handler_begin();
$pdo = db();
[$b, $p] = block_for_write($pdo);
$at = request_integer('at');
$n = block_guard($pdo, static function () use ($pdo, $b, $p, $at): int {
    $pdo->beginTransaction();
    $n = table_add_column($pdo, $b['block_id'], $at);
    block_log($pdo, 'block.update', $b['block_id'], $p, ['type' => 'table', 'column_added' => true, 'rows' => $n]);
    $pdo->commit();
    return $n;
});
block_reply($pdo, $b['block_id'], $p, 'Added a column to ' . $n . ' row' . ($n === 1 ? '' : 's'), ['rows' => $n]);
