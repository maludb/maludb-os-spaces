<?php
declare(strict_types=1);

/**
 * Writes to blocks (slice 3): the verbs of db/008 and the UPDATE that carries the version the editor read — the guard refuses a stale one
 * ('stale: …' P0001), which surfaces here as StaleBlock. Every function runs inside the handler's transaction.
 */

/** Insert a block (sp_block_insert): under a parent (or the root), after a sibling (or last; $atStart first). Returns its id. */
function insert_block(PDO $pdo, string $pageId, string $type, array $content, ?string $parent, ?string $after, bool $atStart = false, ?string $syncedFrom = null): string
{
    $st = $pdo->prepare('SELECT sp_block_insert(CAST(:p AS uuid), CAST(:par AS uuid), CAST(:a AS uuid), :t, CAST(:c AS jsonb), NULL, :s)::text');
    $st->execute(['p' => $pageId, 'par' => $parent, 'a' => $after, 't' => $type, 'c' => json_encode((object) $content, JSON_UNESCAPED_UNICODE), 's' => $atStart ? 't' : 'f']);
    $id = (string) $st->fetchColumn();
    if ($syncedFrom !== null) {
        $pdo->prepare('UPDATE blocks SET synced_from = CAST(:s AS uuid), version = 1 WHERE id = CAST(:id AS uuid)')->execute(['s' => $syncedFrom, 'id' => $id]);
    }
    return $id;
}

/** Update a block's content and/or type with the version the editor read. Returns the new version. A stale version throws StaleBlock. */
function update_block(PDO $pdo, string $blockId, int $version, ?array $content, ?string $type): int
{
    try {
        $st = $pdo->prepare('UPDATE blocks SET content = COALESCE(CAST(:c AS jsonb), content), type = COALESCE(CAST(:t AS text), type), version = :v WHERE id = CAST(:id AS uuid) RETURNING version');
        $st->execute(['c' => $content === null ? null : json_encode((object) $content, JSON_UNESCAPED_UNICODE), 't' => $type, 'v' => $version, 'id' => $blockId]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === 'P0001' && str_contains($e->getMessage(), 'stale:')) {
            throw new StaleBlock($blockId, $version);
        }
        throw $e;
    }
    $v = $st->fetchColumn();
    if ($v === false) {
        throw new DomainException('Not found.');
    }
    return (int) $v;
}

function move_block(PDO $pdo, string $blockId, ?string $parent, ?string $after): void
{
    $pdo->prepare('SELECT sp_block_move(CAST(:b AS uuid), CAST(:p AS uuid), CAST(:a AS uuid))')->execute(['b' => $blockId, 'p' => $parent, 'a' => $after]);
}

/** Delete a block and its children. Returns how many went. */
function delete_block(PDO $pdo, string $blockId): int
{
    $n = (int) one_value($pdo, 'WITH RECURSIVE t AS (SELECT id FROM blocks WHERE id = CAST(:b AS uuid) UNION ALL SELECT c.id FROM blocks c JOIN t ON c.parent_block_id = t.id) SELECT count(*) FROM t', ['b' => $blockId]);
    $st = $pdo->prepare('DELETE FROM blocks WHERE id = CAST(:b AS uuid)');
    $st->execute(['b' => $blockId]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('Not found.');
    }
    return $n;
}

/** The converter + sp_block_insert per block, children recursively. Returns the ids made (root-level first). */
function append_markdown(PDO $pdo, string $pageId, string $markdown, ?string $parent, ?string $after, array $ctx): array
{
    $tree = markdown_to_blocks($markdown, $ctx);
    return insert_tree($pdo, $pageId, $tree, $parent, $after);
}

function insert_tree(PDO $pdo, string $pageId, array $nodes, ?string $parent, ?string $after): array
{
    $ids = [];
    $prev = $after;
    foreach ($nodes as $node) {
        $id = insert_block($pdo, $pageId, (string) $node['type'], is_array($node['content'] ?? null) ? $node['content'] : [], $parent, $prev, false);
        $ids[] = $id;
        if (!empty($node['children'])) {
            insert_tree($pdo, $pageId, $node['children'], $id, null);
        }
        $prev = $id;
    }
    return $ids;
}

/** Enter: the left half stays, the right half becomes a new block after it (the children stay with the left). Returns [left version, right id]. */
function split_block(PDO $pdo, string $blockId, int $version, array $leftContent, array $rightContent, string $rightType): array
{
    $b = find_block($pdo, $blockId) ?? throw new DomainException('Not found.');
    $v = update_block($pdo, $blockId, $version, $leftContent, null);
    $right = insert_block($pdo, (string) $b['page_id'], $rightType, $rightContent, $b['parent_block_id'], $blockId, false);
    return [$v, $right];
}

/** Backspace: the previous block takes the words; this one is deleted; its children re-parent to the previous. Returns the previous block's new version. */
function merge_block(PDO $pdo, string $blockId, int $version, string $intoBlockId, int $intoVersion, array $mergedContent): int
{
    $b = find_block($pdo, $blockId) ?? throw new DomainException('Not found.');
    $into = find_block($pdo, $intoBlockId) ?? throw new DomainException('Not found.');
    if ($into['page_id'] !== $b['page_id']) {
        throw new DomainException('A block merges into one on the same page.');
    }
    update_block($pdo, $blockId, $version, null, null);                       // the version check on the one being removed
    $v = update_block($pdo, $intoBlockId, $intoVersion, $mergedContent, null);
    // the children: under the block that took the words when it may hold them, else where the removed block stood (after it, at its level)
    $holds = db_bool($pdo, 'SELECT :t = ANY (sp_block_types_with_children())', ['t' => $into['type']])
        && !(in_array($into['type'], ['heading_1', 'heading_2', 'heading_3'], true) && empty($into['content']['is_toggleable']));
    $kids = $pdo->prepare('SELECT id::text AS id FROM blocks WHERE parent_block_id = CAST(:b AS uuid) ORDER BY position');
    $kids->execute(['b' => $blockId]);
    $prev = $holds ? null : $blockId;
    foreach ($kids->fetchAll() as $k) {
        if ($holds) {
            move_block($pdo, (string) $k['id'], $intoBlockId, $prev);
        } else {
            move_block($pdo, (string) $k['id'], $b['parent_block_id'], $prev);
        }
        $prev = (string) $k['id'];
    }
    delete_block($pdo, $blockId);
    return $v;
}

/** A column added to a table: a cell at $atIndex (or the end) in every row; table_width follows. Returns the rows touched. */
function table_add_column(PDO $pdo, string $tableId, ?int $atIndex): int
{
    $t = find_block($pdo, $tableId) ?? throw new DomainException('Not found.');
    if ($t['type'] !== 'table') {
        throw new DomainException('Only a table gains a column.');
    }
    $rows = $pdo->prepare("SELECT id::text AS id, content, version FROM blocks WHERE parent_block_id = CAST(:t AS uuid) AND type = 'table_row' ORDER BY position");
    $rows->execute(['t' => $tableId]);
    $n = 0;
    $width = (int) ($t['content']['table_width'] ?? 0);
    foreach ($rows->fetchAll() as $r) {
        $c = json_decode((string) $r['content'], true) ?: [];
        $cells = is_array($c['cells'] ?? null) ? $c['cells'] : [];
        $at = $atIndex === null || $atIndex > count($cells) ? count($cells) : max(0, $atIndex);
        array_splice($cells, $at, 0, [[]]);
        $c['cells'] = $cells;
        update_block($pdo, (string) $r['id'], (int) $r['version'], $c, null);
        $width = max($width, count($cells));
        $n++;
    }
    update_block($pdo, $tableId, $t['version'], ['table_width' => $width] + $t['content'], null);
    return $n;
}
