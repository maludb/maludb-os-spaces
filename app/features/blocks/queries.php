<?php
declare(strict_types=1);

/** Blocks (slice 3): reads through mcp_blocks and sp_page_tree(); the HTML of one block with its children by the one renderer. */

function page_tree(PDO $pdo, string $pageId): array
{
    return json_decode((string) one_value($pdo, 'SELECT sp_page_tree(CAST(:id AS uuid))::text', ['id' => $pageId]), true) ?: [];
}

/** One block the caller may see, with its page. */
function find_block(PDO $pdo, string $blockId): ?array
{
    if (!is_uuid($blockId)) {
        return null;
    }
    $st = $pdo->prepare('SELECT block_id::text AS block_id, page_id::text AS page_id, parent_block_id::text AS parent_block_id, type, position, content, plain_text, has_children, version, synced_from::text AS synced_from, created_by, created_at, last_edited_by, last_edited_at FROM mcp_blocks WHERE block_id = CAST(:id AS uuid)');
    $st->execute(['id' => $blockId]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['content'] = json_decode((string) $r['content'], true) ?: [];
    $r['has_children'] = (bool) $r['has_children'];
    $r['version'] = (int) $r['version'];
    return $r;
}

/** A block as a tree node (children nested, a synced copy's original's children), in sp_page_tree()'s shape. */
function block_node(PDO $pdo, array $b): array
{
    $kids = [];
    if ($b['has_children'] || ($b['type'] === 'synced_block' && $b['synced_from'] !== null)) {
        $src = $b['page_id'];
        $parent = $b['block_id'];
        if ($b['type'] === 'synced_block' && $b['synced_from'] !== null) {
            $src = one_value($pdo, 'SELECT page_id::text FROM blocks WHERE id = CAST(:o AS uuid)', ['o' => $b['synced_from']]);
            $parent = $b['synced_from'];
        }
        if ($src !== null && db_bool($pdo, 'SELECT sp_can_see_page(CAST(:p AS uuid))', ['p' => $src])) {
            $kids = json_decode((string) one_value($pdo, 'SELECT sp_block_children_json(CAST(:p AS uuid), CAST(:b AS uuid))::text', ['p' => $src, 'b' => $parent]), true) ?: [];
        }
    }
    return ['id' => $b['block_id'], 'type' => $b['type'], 'content' => $b['content'], 'has_children' => $b['has_children'], 'version' => $b['version'], 'position' => $b['position'],
            'synced_from' => $b['synced_from'], 'parent' => $b['parent_block_id'], 'children' => $kids];
}

/** One block rendered (with its children) by the one renderer; $opts as render_blocks(). */
function block_html(PDO $pdo, array $b, array $opts = []): string
{
    if ($b['type'] === 'table_row' && $b['parent_block_id'] !== null) {       // a row renders as its table (the editor finds its <tr> in it)
        $table = find_block($pdo, (string) $b['parent_block_id']);
        if ($table !== null) { return block_html($pdo, $table, $opts); }
    }
    $node = block_node($pdo, $b);
    $opts += ['titles' => page_titles_in_tree($pdo, [$node]), 'embed_hosts' => pg_text_array((string) one_value($pdo, 'SELECT allowed_embed_hosts::text FROM sp_settings WHERE id = 1')), 'parent' => $b['parent_block_id']];
    return render_blocks([$node], $opts);
}

/** The facts an editor needs beside the tree: the settings it obeys. */
function editor_settings(PDO $pdo): array
{
    $s = $pdo->query('SELECT allowed_embed_hosts::text AS hosts, max_attachment_bytes FROM sp_settings WHERE id = 1')->fetch() ?: [];
    return ['embed_hosts' => pg_text_array((string) ($s['hosts'] ?? '')), 'max_attachment_bytes' => (int) ($s['max_attachment_bytes'] ?? 26214400),
            'block_types' => pg_text_array((string) one_value($pdo, 'SELECT sp_block_types()::text')), 'colors' => pg_text_array((string) one_value($pdo, 'SELECT sp_text_colors()::text'))];
}

/** The pickers' candidates: members and agents (kind member), departments, pages, emoji. [{id, name, kind, hint}] */
function mention_candidates(PDO $pdo, string $q, string $kind, int $limit = 12): array
{
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $out = [];
    if ($kind === 'member' || $kind === 'any') {
        $st = $pdo->prepare('SELECT member_id, display_name, member_kind, is_guest FROM mcp_members WHERE display_name ILIKE :q ORDER BY member_kind, display_name LIMIT ' . $limit);
        $st->execute(['q' => $like]);
        foreach ($st->fetchAll() as $m) { $out[] = ['id' => (string) $m['member_id'], 'name' => $m['display_name'], 'kind' => $m['member_kind'] === 'agent' ? 'agent' : 'member', 'hint' => $m['member_kind'] === 'agent' ? 'agent' : ($m['is_guest'] ? 'guest' : 'member')]; }
    }
    if ($kind === 'department' || $kind === 'any') {
        $st = $pdo->prepare('SELECT department_id, name FROM mcp_departments WHERE archived_at IS NULL AND name ILIKE :q ORDER BY name LIMIT ' . $limit);
        $st->execute(['q' => $like]);
        foreach ($st->fetchAll() as $d) { $out[] = ['id' => (string) $d['department_id'], 'name' => $d['name'], 'kind' => 'department', 'hint' => 'department']; }
    }
    if ($kind === 'page') {
        $st = $pdo->prepare('SELECT p.page_id::text AS id, p.plain_title, p.kind, s.name AS space FROM mcp_pages p LEFT JOIN mcp_spaces s ON s.space_id = p.space_id WHERE p.archived_at IS NULL AND NOT p.is_template AND p.parent_database_id IS NULL AND p.plain_title ILIKE :q ORDER BY p.last_edited_at DESC LIMIT ' . $limit);
        $st->execute(['q' => $like]);
        foreach ($st->fetchAll() as $p) { $out[] = ['id' => $p['id'], 'name' => $p['plain_title'] !== '' ? $p['plain_title'] : 'Untitled', 'kind' => $p['kind'] === 'database' ? 'database' : 'page', 'hint' => $p['space'] ?? 'private']; }
    }
    if ($kind === 'emoji') {
        $st = $pdo->prepare('SELECT shortcode, emoji FROM mcp_emoji WHERE shortcode ILIKE :q OR :raw = ANY (keywords) ORDER BY shortcode LIMIT ' . $limit);
        $st->execute(['q' => $like, 'raw' => $q]);
        foreach ($st->fetchAll() as $e) { $out[] = ['id' => $e['shortcode'], 'name' => $e['emoji'], 'kind' => 'emoji', 'hint' => ':' . $e['shortcode'] . ':']; }
    }
    return $out;
}

/** The converter's resolvers, as the caller sees the world: a page by exact title, a member or department by exact name. */
function markdown_context(PDO $pdo): array
{
    return [
        'page_by_title' => static function (string $t) use ($pdo): ?string {
            $v = one_value($pdo, 'SELECT page_id::text FROM mcp_pages WHERE lower(plain_title) = lower(:t) AND archived_at IS NULL AND NOT is_template ORDER BY last_edited_at DESC LIMIT 1', ['t' => $t]);
            return $v === null ? null : (string) $v;
        },
        'member_by_name' => static function (string $n) use ($pdo): ?array {
            $st = $pdo->prepare('SELECT member_id, display_name, member_kind FROM mcp_members WHERE lower(display_name) = lower(:n) LIMIT 1');
            $st->execute(['n' => $n]);
            $m = $st->fetch();
            if ($m !== false) { return ['id' => (int) $m['member_id'], 'name' => $m['display_name'], 'kind' => $m['member_kind'] === 'agent' ? 'agent' : 'member']; }
            $st = $pdo->prepare('SELECT department_id, name FROM mcp_departments WHERE lower(name) = lower(:n) AND archived_at IS NULL LIMIT 1');
            $st->execute(['n' => $n]);
            $d = $st->fetch();
            return $d === false ? null : ['id' => (int) $d['department_id'], 'name' => $d['name'], 'kind' => 'department'];
        },
        'embed_hosts' => pg_text_array((string) one_value($pdo, 'SELECT allowed_embed_hosts::text FROM sp_settings WHERE id = 1')),
    ];
}

/** The presence cache (block-editor.md): storage/presence/<page>.json, never the database. Touch mine, drop the stale, answer the others. */
function page_presence_touch(string $pageId, int $memberId, string $name): array
{
    $dir = APP_ROOT . '/storage/presence';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $file = $dir . '/' . $pageId . '.json';
    $now = time();
    $fh = fopen($file, 'c+');
    if ($fh === false) { return []; }
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $all = json_decode((string) $raw, true) ?: [];
    $all[(string) $memberId] = ['name' => $name, 'seen' => $now];
    foreach ($all as $k => $v) { if (($v['seen'] ?? 0) < $now - 30) { unset($all[$k]); } }
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($all)); fflush($fh); flock($fh, LOCK_UN); fclose($fh);
    $others = [];
    foreach ($all as $k => $v) { if ((int) $k !== $memberId) { $others[] = ['member_id' => (int) $k, 'name' => $v['name']]; } }
    return $others;
}
