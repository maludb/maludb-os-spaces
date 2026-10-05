<?php
declare(strict_types=1);

/**
 * The prelude of a block handler (slice 3, THE FIRST EXEMPLAR): the feature's files, the page a block belongs to (through mcp_blocks — 404 when
 * unseen), the gate (`edit` on the page; `edit_content` on a database row's blocks), the one logger stamping entity_uuid (the block) and space_id,
 * and the stale refusal: a save carrying an old version answers 409 {error: {code: stale, version, html}} so the editor reloads the block.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/richtext/render.php';
require_once dirname(__DIR__, 2) . '/richtext/markdown.php';
require_once dirname(__DIR__) . '/pages/queries.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/write.php';

class StaleBlock extends RuntimeException
{
    public function __construct(public readonly string $blockId, public readonly int $version)
    {
        parent::__construct('stale');
    }
}

/** The level a page's blocks need: edit — or edit_content when the page is a database row. */
function block_edit_level(array $page): string
{
    return $page['parent_database_id'] !== null ? 'edit_content' : 'edit';
}

/** The page a request names (`page`), gated for block writes. */
function page_for_blocks(PDO $pdo, ?string $pageId = null): array
{
    $pageId ??= (string) (req_val('page') ?? ($_GET['page'] ?? ''));
    if (!is_uuid($pageId)) {
        refuse(422, 'Say which page.');
    }
    $p = find_page($pdo, $pageId) ?? refuse(404, 'Page not found.');
    require_page_level($pageId, block_edit_level($p));
    if ($p['archived_at'] !== null) {
        refuse(422, 'Page "' . $p['plain_title'] . '" is in the trash: restore it to edit');
    }
    if ($p['is_locked']) {
        refuse(422, 'Page "' . $p['plain_title'] . '" is locked: unlock it to edit');
    }
    return $p;
}

/** The block a request names (`block`), through the view, with its page gated for writes. Returns [block, page]. */
function block_for_write(PDO $pdo, ?string $blockId = null): array
{
    $blockId ??= (string) (req_val('block') ?? ($_GET['block'] ?? ''));
    if (!is_uuid($blockId)) {
        refuse(422, 'Say which block.');
    }
    $b = find_block($pdo, $blockId) ?? refuse(404, 'Block not found.');
    $p = page_for_blocks($pdo, (string) $b['page_id']);
    return [$b, $p];
}

/** A row of the trail about a block: entity_uuid = the block, space_id, the page in after.page_id. Never the block's text. */
function block_log(PDO $pdo, string $action, string $blockId, array $page, array $after = [], array $before = []): void
{
    $opts = ['space_id' => $page['space_id'], 'after' => ['page_id' => $page['page_id']] + $after];
    if ($before !== []) { $opts['before'] = $before; }
    log_activity($pdo, $action, 'block', $blockId, $opts);
}

/** Run a block write: a stale save answers 409 with the block as it is now; the rest is sp_guard()'s. */
function block_guard(PDO $pdo, callable $step): mixed
{
    try {
        return sp_guard($pdo, $step);
    } catch (StaleBlock $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $now = find_block($pdo, $e->blockId);
        $html = $now === null ? '' : block_html($pdo, $now, ['editor' => true]);
        emit_action_status(false, ['error' => 'Someone changed this block while you typed.', 'code' => 'stale', 'version' => $now['version'] ?? null]);
        if (wants_json()) {
            json_error('stale', 'Someone changed this block while you typed: it was reloaded.', 409, ['version' => $now['version'] ?? null, 'html' => $html, 'block_id' => $e->blockId]);
        }
        refuse(409, 'Someone changed this block while you typed: reload the page.');
    }
}

/** The JSON a block write answers the editor with: the block's version and HTML, the page's content_rev. */
function block_reply(PDO $pdo, string $blockId, array $page, string $did, array $extra = []): never
{
    $b = find_block($pdo, $blockId);
    $rev = (int) one_value($pdo, 'SELECT content_rev FROM pages WHERE id = CAST(:p AS uuid)', ['p' => $page['page_id']]);
    $payload = ['version' => $b['version'] ?? null, 'html' => $b === null ? '' : block_html($pdo, $b, ['editor' => true]), 'content_rev' => $rev, 'type' => $b['type'] ?? null] + $extra;
    sp_done($did, $blockId, '/pages/' . $page['page_id'] . '#block-' . $blockId, 'blockChanged', $payload);
}
