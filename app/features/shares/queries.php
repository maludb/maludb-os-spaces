<?php
declare(strict_types=1);

/**
 * The two shares[] of maludb-os.json (design §8, K7): pages_index (os.spaces-pages/1) and page_markdown (os.spaces-page/1). ONE truth, two doors: the records MCP server's tools of the same names do not rebuild the
 * documents, they ask the internal door (html/api/v1/shares/read.php) to call these functions — which read through the mcp_* views and sp_page_markdown() AS A MEMBER, exactly as a screen would.
 *
 * Who the member is. A person (or an agent) calling the tool with their own token reads as themselves. The KERNEL's token — a sibling application reading through K7 — has no member: the share runs as the
 * requesting application's expert agent, which the KERNEL names in the header X-OS-Consumer-Agent (K26, kernel db/173, 2026-10-06; the records server relays it to this door as `consumer_agent_id`) — never an argument of
 * the consumer's: it must be an active AGENT the directory admitted — never a person — and it sees only what an agent member sees (a space it is in, a
 * page shared with it). No agent named (the consumer has no expert) → the index is empty and a page is refused; nothing is guessed. The documents are people-free: no author, no id of a person but the acting agent's own.
 */

const SHARE_APPLICATION = 'spaces';
const SHARE_PAGE_MAX_BYTES = 200000;

class ShareRefused extends RuntimeException
{
}

/** The agent a kernel call acts as: its member id when the directory admitted it as an active agent, else a refusal in words. NULL when none was named. */
function share_agent(PDO $pdo, mixed $given): ?int
{
    if ($given === null || $given === '') {
        return null;
    }
    if (!is_int($given) && !(is_string($given) && ctype_digit($given))) {
        throw new DomainException('The consumer agent the kernel named is not a member id.');
    }
    $id = (int) $given;
    if (!db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM members WHERE id = :m AND member_kind = 'agent' AND status = 'active' AND capability IS NOT NULL)", ['m' => $id])) {
        throw new ShareRefused('That agent is not a member here (not hired, not admitted, or not an agent).');
    }
    return $id;
}

function share_as(PDO $pdo, int $member): void
{
    $pdo->prepare('SELECT set_config(?, ?, false)')->execute(['app.member_id', (string) $member]);
}

/** The head of a document. */
function share_document(string $schema, int $member, array $body): array
{
    return ['schema' => $schema, 'generated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'application' => SHARE_APPLICATION, 'as_member' => $member] + $body;
}

/** The kernel's call is logged `share.read` (source application, no actor): the tool, the argument KEYS that were given, the rows answered, the agent it ran as — never a title or a row. */
function share_log(PDO $pdo, string $caller, string $tool, array $given, int $rows, ?int $agent): void
{
    if ($caller === 'kernel') {
        log_activity($pdo, 'share.read', 'share', null, ['source' => 'application', 'actor_member_id' => null,
            'after' => ['direction' => 'in', 'application' => 'kernel', 'tool' => $tool, 'keys' => array_keys(array_filter($given, static fn ($v): bool => $v !== null)), 'rows' => $rows, 'agent_member_id' => $agent]]);
    }
}

/** os.spaces-pages/1 — the pages `$member` may see (not templates, not the trash), newest edit first; `q` words match titles; paged by `cursor` (an offset). */
function share_pages_index(PDO $pdo, int $member, ?string $q, int $limit, int $offset): array
{
    share_as($pdo, $member);
    $limit = max(1, min(100, $limit));
    $where = ['p.archived_at IS NULL', 'NOT p.is_template'];
    $args = [];
    $i = 0;
    foreach (array_slice(preg_split('/\s+/', trim((string) $q), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6) as $w) {
        $k = 'w' . $i++;
        $where[] = "p.plain_title ILIKE :$k";
        $args[$k] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $w) . '%';
    }
    $st = $pdo->prepare('SELECT p.page_id::text AS page_id, p.plain_title AS title, p.kind, (p.parent_database_id IS NOT NULL) AS is_row, p.space_id, s.name AS space_name, COALESCE(s.is_wiki, false) AS is_wiki,
                                p.last_edited_at, p.verification_state, count(*) OVER () AS total
                           FROM mcp_pages p LEFT JOIN mcp_spaces s ON s.space_id = p.space_id
                          WHERE ' . implode(' AND ', $where) . ' ORDER BY p.last_edited_at DESC, p.page_id LIMIT ' . $limit . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    $found = $st->fetchAll();
    $crumb = $pdo->prepare('SELECT plain_title FROM sp_page_ancestors(CAST(:p AS uuid)) ORDER BY depth DESC');
    $rows = [];
    foreach ($found as $r) {
        $crumb->execute(['p' => $r['page_id']]);
        $rows[] = ['page_id' => $r['page_id'], 'title' => $r['title'], 'kind' => $r['is_row'] ? 'row' : $r['kind'], 'space' => ['space_id' => $r['space_id'] === null ? null : (int) $r['space_id'], 'name' => $r['space_name']],
                   'path' => array_values(array_map('strval', $crumb->fetchAll(PDO::FETCH_COLUMN))), 'last_edited_at' => json_ts($r['last_edited_at']), 'verification_state' => $r['verification_state'], 'is_wiki' => (bool) $r['is_wiki']];
    }
    $total = $found === [] ? 0 : (int) $found[0]['total'];
    return share_document('os.spaces-pages/1', $member, ['rows' => $rows, 'total' => $total, 'next_cursor' => $offset + count($rows) < $total ? (string) ($offset + count($rows)) : null]);
}

/** os.spaces-page/1 — one page as Markdown with its properties (a row's, as text) and breadcrumb, as `$member` may see it. */
function share_page_markdown(PDO $pdo, int $member, string $page): array
{
    share_as($pdo, $member);
    if (!is_uuid($page)) {
        throw new DomainException('page is a page id (a UUID).');
    }
    $st = $pdo->prepare('SELECT p.page_id::text AS page_id, p.plain_title AS title, p.kind, p.parent_database_id, p.last_edited_at, p.verification_state, p.archived_at FROM mcp_pages p WHERE p.page_id = CAST(:p AS uuid)');
    $st->execute(['p' => $page]);
    $r = $st->fetch();
    if ($r === false || $r['archived_at'] !== null) {
        throw new ShareRefused('No page that agent can see has that id.');
    }
    $md = (string) one_value($pdo, 'SELECT sp_page_markdown(CAST(:p AS uuid), false)', ['p' => $page]);
    if (strlen($md) > SHARE_PAGE_MAX_BYTES) {
        $md = mb_strcut($md, 0, SHARE_PAGE_MAX_BYTES) . "\n\n[… cut at 200 kB]";
    }
    $crumb = $pdo->prepare('SELECT plain_title FROM sp_page_ancestors(CAST(:p AS uuid)) ORDER BY depth DESC');
    $crumb->execute(['p' => $page]);
    $props = null;
    if ($r['parent_database_id'] !== null) {
        $props = share_flat(json_decode((string) one_value($pdo, 'SELECT sp_row_resolved(CAST(:p AS uuid))::text', ['p' => $page]), true) ?: []);
    }
    return share_document('os.spaces-page/1', $member, ['page' => ['page_id' => $r['page_id'], 'title' => $r['title'], 'kind' => $r['parent_database_id'] !== null ? 'row' : $r['kind'],
        'path' => array_values(array_map('strval', $crumb->fetchAll(PDO::FETCH_COLUMN))), 'markdown' => $md, 'properties' => $props, 'last_edited_at' => json_ts($r['last_edited_at']), 'verification_state' => $r['verification_state']]]);
}

/** A resolved property value as plain data: a run array becomes its text; people become their NAMES (no ids cross); everything else as it is. */
function share_flat(mixed $v): mixed
{
    if (!is_array($v)) {
        return $v;
    }
    if ($v !== [] && array_is_list($v) && is_array($v[0] ?? null) && array_key_exists('plain_text', $v[0])) {
        return implode('', array_map(static fn ($x): string => (string) ($x['plain_text'] ?? ''), $v));
    }
    if (array_is_list($v)) {
        return array_map('share_flat', $v);
    }
    $out = [];
    foreach ($v as $k => $x) {
        if ($k === 'id' && isset($v['name'])) {
            continue;                                   // {id, name}: a person or a related row — the name only
        }
        $out[$k] = share_flat($x);
    }
    return $out;
}
