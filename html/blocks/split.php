<?php
declare(strict_types=1);
/** Enter in the editor (a screen helper, logged block.update + block.insert): the left half stays (its version checked), the right half is a new block after it. Answers {left: {version, html}, right: {block_id, html}}. */
require_once dirname(__DIR__, 2) . '/app/features/blocks/handler.php';
sp_handler_begin();
$pdo = db();
[$b, $p] = block_for_write($pdo);
$version = request_integer('version') ?? sp_refuse_fields(['version' => 'Send the version you read.']);
$left = json_decode((string) (req_val('left') ?? ''), true);
$right = json_decode((string) (req_val('right') ?? ''), true);
$rightType = (string) (req_val('right_type') ?? '');
if (!is_array($left) || !is_array($right)) { sp_refuse_fields(['left' => 'Send the two halves as the type\'s JSON objects.']); }
if (!in_array($rightType, pg_text_array((string) one_value($pdo, 'SELECT sp_block_types()::text')), true)) { sp_refuse_fields(['right_type' => 'That is not a block type.']); }
[$v, $rightId] = block_guard($pdo, static function () use ($pdo, $b, $p, $version, $left, $right, $rightType): array {
    $pdo->beginTransaction();
    [$v, $rightId] = split_block($pdo, $b['block_id'], $version, $left, $right, $rightType);
    block_log($pdo, 'block.update', $b['block_id'], $p, ['type' => $b['type'], 'version' => $v, 'split' => true]);
    block_log($pdo, 'block.insert', $rightId, $p, ['type' => $rightType, 'parent_block_id' => $b['parent_block_id'], 'after' => $b['block_id'], 'split' => true]);
    $pdo->commit();
    return [$v, $rightId];
});
$rev = (int) one_value($pdo, 'SELECT content_rev FROM pages WHERE id = CAST(:p AS uuid)', ['p' => $p['page_id']]);
$lb = find_block($pdo, $b['block_id']);
$rb = find_block($pdo, $rightId);
sp_done('Split the block', $rightId, '/pages/' . $p['page_id'] . '#block-' . $rightId, 'blockChanged', ['left' => ['block_id' => $b['block_id'], 'version' => $lb['version'] ?? null, 'html' => $lb === null ? '' : block_html($pdo, $lb, ['editor' => true])], 'right' => ['block_id' => $rightId, 'version' => $rb['version'] ?? 1, 'html' => $rb === null ? '' : block_html($pdo, $rb, ['editor' => true])], 'content_rev' => $rev]);
