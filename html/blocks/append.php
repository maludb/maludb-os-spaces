<?php
declare(strict_types=1);
/** Action `block_append` (log `block.append`: count, parent_block_id): edit (edit_content on a row); Markdown → blocks after the named block (or last), under a parent. The agents' way of writing. */
require_once dirname(__DIR__, 2) . '/app/features/blocks/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$p = page_for_blocks($pdo);
$md = (string) (req_val('markdown') ?? '');
if (trim($md) === '' || mb_strlen($md) > 200000) { sp_refuse_fields(['markdown' => 'Give the Markdown to add (up to 200,000 characters).']); }
$parent = req_has('parent') && (string) req_val('parent') !== '' ? (string) req_val('parent') : null;
$after = req_has('after') && (string) req_val('after') !== '' ? (string) req_val('after') : null;
foreach (['parent' => $parent, 'after' => $after] as $k => $v) {
    if ($v !== null && (!is_uuid($v) || !db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM mcp_blocks WHERE block_id = CAST(:b AS uuid) AND page_id = CAST(:p AS uuid))', ['b' => $v, 'p' => $p['page_id']]))) { sp_refuse_fields([$k => 'That block is not on this page.']); }
}
$ids = block_guard($pdo, static function () use ($pdo, $p, $md, $parent, $after): array {
    $pdo->beginTransaction();
    $ids = append_markdown($pdo, $p['page_id'], $md, $parent, $after, markdown_context($pdo));
    foreach ($ids as $id) { block_log($pdo, 'block.append', $id, $p, ['count' => count($ids), 'parent_block_id' => $parent, 'after' => $after]); }
    $pdo->commit();
    return $ids;
});
$n = count($ids);
if (wants_json()) {
    respond_saved(['did' => 'Added ' . $n . ' block' . ($n === 1 ? '' : 's'), 'record_id' => $ids[0] ?? null, 'location' => '/pages/' . $p['page_id'], 'refresh' => 'blockChanged', 'block_ids' => $ids, 'count' => $n, 'page_id' => $p['page_id']]);
}
emit_action_status(true, ['did' => 'Added ' . $n . ' blocks', 'record_id' => $ids[0] ?? null, 'refresh' => 'blockChanged']);
saved_go('/pages/' . $p['page_id'], 'blockChanged');
