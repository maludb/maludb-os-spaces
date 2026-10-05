<?php
declare(strict_types=1);
/** Backspace at the start (a screen helper, logged block.update + block.delete): the previous block takes the words (`content` = the merged content), this one goes, its children re-parent. Answers {into: {version, html}}. */
require_once dirname(__DIR__, 2) . '/app/features/blocks/handler.php';
sp_handler_begin();
$pdo = db();
[$b, $p] = block_for_write($pdo);
$version = request_integer('version') ?? sp_refuse_fields(['version' => 'Send the version you read.']);
$into = (string) (req_val('into') ?? '');
$intoVersion = request_integer('into_version') ?? sp_refuse_fields(['into_version' => 'Send the version of the block to merge into.']);
$merged = json_decode((string) (req_val('content') ?? ''), true);
if (!is_uuid($into) || find_block($pdo, $into) === null) { sp_refuse_fields(['into' => 'That block is not one you may see.']); }
if (!is_array($merged)) { sp_refuse_fields(['content' => 'Send the merged content as the type\'s JSON object.']); }
$v = block_guard($pdo, static function () use ($pdo, $b, $p, $version, $into, $intoVersion, $merged): int {
    $pdo->beginTransaction();
    $v = merge_block($pdo, $b['block_id'], $version, $into, $intoVersion, $merged);
    block_log($pdo, 'block.update', $into, $p, ['version' => $v, 'merged_from' => $b['block_id']]);
    block_log($pdo, 'block.delete', $b['block_id'], $p, ['type' => $b['type'], 'had_children' => $b['has_children'], 'merged_into' => $into]);
    $pdo->commit();
    return $v;
});
$rev = (int) one_value($pdo, 'SELECT content_rev FROM pages WHERE id = CAST(:p AS uuid)', ['p' => $p['page_id']]);
$ib = find_block($pdo, $into);
sp_done('Merged the block', $into, '/pages/' . $p['page_id'] . '#block-' . $into, 'blockChanged', ['into' => ['block_id' => $into, 'version' => $ib['version'] ?? null, 'html' => $ib === null ? '' : block_html($pdo, $ib, ['editor' => true])], 'content_rev' => $rev]);
