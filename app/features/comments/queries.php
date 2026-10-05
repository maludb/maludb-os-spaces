<?php
declare(strict_types=1);

/** Comments (slice 3): reads through mcp_comments — a page's discussions, each with its replies; the open count per block for the margin. */

/** The page's discussions (first comments) with their replies, open ones first then resolved; $openOnly drops the resolved. */
function page_comments(PDO $pdo, string $pageId, bool $openOnly = false): array
{
    $st = $pdo->prepare('SELECT comment_id::text AS comment_id, page_id::text AS page_id, block_id::text AS block_id, parent_comment_id::text AS parent_comment_id, author_member_id, author_name, author_is_agent, body, plain_text, resolved_at, resolved_by, edited_at, deleted_at, agent_run_id, created_at
                           FROM mcp_comments WHERE page_id = CAST(:p AS uuid) ORDER BY created_at');
    $st->execute(['p' => $pageId]);
    $roots = [];
    $replies = [];
    foreach ($st->fetchAll() as $c) {
        $c['body'] = json_decode((string) $c['body'], true) ?: [];
        $c['author_is_agent'] = (bool) $c['author_is_agent'];
        $c['author_member_id'] = $c['author_member_id'] === null ? null : (int) $c['author_member_id'];
        if ($c['parent_comment_id'] === null) { $c['replies'] = []; $roots[$c['comment_id']] = $c; } else { $replies[] = $c; }
    }
    foreach ($replies as $r) {
        if (isset($roots[$r['parent_comment_id']])) { $roots[$r['parent_comment_id']]['replies'][] = $r; }
    }
    $out = array_values(array_filter($roots, static fn (array $c): bool => (!$openOnly || $c['resolved_at'] === null) && $c['deleted_at'] === null));
    usort($out, static fn (array $a, array $b): int => (($a['resolved_at'] === null ? 0 : 1) <=> ($b['resolved_at'] === null ? 0 : 1)) ?: strcmp($a['created_at'], $b['created_at']));
    return $out;
}

/** block_id => open discussions on it (the margin's markers); '' for the page's own. */
function comment_counts(PDO $pdo, string $pageId): array
{
    $st = $pdo->prepare("SELECT COALESCE(block_id::text, '') AS b, count(*) AS n FROM mcp_comments WHERE page_id = CAST(:p AS uuid) AND parent_comment_id IS NULL AND resolved_at IS NULL AND deleted_at IS NULL GROUP BY 1");
    $st->execute(['p' => $pageId]);
    $out = [];
    foreach ($st->fetchAll() as $r) { $out[$r['b']] = (int) $r['n']; }
    return $out;
}

function find_comment(PDO $pdo, string $id): ?array
{
    if (!is_uuid($id)) {
        return null;
    }
    $st = $pdo->prepare('SELECT comment_id::text AS comment_id, page_id::text AS page_id, block_id::text AS block_id, parent_comment_id::text AS parent_comment_id, author_member_id, author_name, body, plain_text, resolved_at, edited_at, deleted_at, created_at FROM mcp_comments WHERE comment_id = CAST(:id AS uuid)');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['body'] = json_decode((string) $r['body'], true) ?: [];
    $r['author_member_id'] = $r['author_member_id'] === null ? null : (int) $r['author_member_id'];
    return $r;
}

function present_comment(array $c): array
{
    return ['comment_id' => $c['comment_id'], 'page_id' => $c['page_id'], 'block_id' => $c['block_id'], 'parent_comment_id' => $c['parent_comment_id'],
            'author' => ['member_id' => $c['author_member_id'], 'display_name' => $c['author_name'] ?? null, 'is_agent' => (bool) ($c['author_is_agent'] ?? false)],
            'markdown' => rt_runs_markdown($c['body']), 'resolved' => $c['resolved_at'] !== null, 'resolved_at' => json_ts($c['resolved_at']), 'edited_at' => json_ts($c['edited_at']), 'deleted' => $c['deleted_at'] !== null,
            'created_at' => json_ts($c['created_at']), 'replies' => array_map('present_comment', $c['replies'] ?? [])];
}

/** Runs → a Markdown line (for JSON and excerpts); the SQL converter is the canonical one for pages. */
function rt_runs_markdown(array $runs): string
{
    $out = '';
    foreach ($runs as $r) {
        $t = (string) ($r['plain_text'] ?? ($r['text']['content'] ?? ''));
        if (($r['type'] ?? '') === 'mention') { $t = ($r['mention']['type'] ?? '') === 'page' ? '[[' . ($r['mention']['name'] ?? $t) . ']]' : '@' . ltrim((string) ($r['mention']['name'] ?? $t), '@'); }
        $a = $r['annotations'] ?? [];
        if (!empty($a['code'])) { $t = '`' . $t . '`'; }
        if (!empty($a['bold'])) { $t = '**' . $t . '**'; }
        if (!empty($a['italic'])) { $t = '*' . $t . '*'; }
        if (!empty($a['strikethrough'])) { $t = '~~' . $t . '~~'; }
        if (!empty($r['text']['link']['url'])) { $t = '[' . $t . '](' . $r['text']['link']['url'] . ')'; }
        $out .= $t;
    }
    return $out;
}
