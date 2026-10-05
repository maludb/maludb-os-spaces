<?php
declare(strict_types=1);

/**
 * Databases (slice 5): reads through mcp_databases, mcp_database_views, mcp_pages and the SQL read functions (sp_database_rows(), sp_row_resolved()). The
 * permission tree decides who sees what (the views test the caller once per statement); nothing here re-decides it. Writes are in write.php.
 */

const DATABASE_SELECT = 'SELECT d.database_id, d.title, d.icon, d.space_id, s.name AS space_name, d.parent_page_id, d.description, d.is_inline, d.properties, d.title_property_key, d.row_template_id,
       d.created_at, d.updated_at, d.archived_at, d.row_count, p.my_level, p.last_edited_at, p.last_edited_by, em.display_name AS editor_name, p.is_locked, p.is_template, p.permission_root_id,
       p.owner_member_id, p.is_favorite
  FROM mcp_databases d JOIN mcp_pages p ON p.page_id = d.database_id LEFT JOIN mcp_spaces s ON s.space_id = d.space_id LEFT JOIN members em ON em.id = p.last_edited_by';

/**
 * A schema in the order the person made it. jsonb keeps keys by length and name, not as written, so each property carries its own `order` (set when it is added); one without
 * (a seeded template's, a dual a relation wrote) follows in the database's own order.
 */
function schema_ordered(array $schema): array
{
    $keys = array_keys($schema);
    $pos = array_flip($keys);
    $ord = static fn (string $k): int => (int) ($schema[$k]['order'] ?? (($schema[$k]['type'] ?? '') === 'title' ? -1 : 100000 + $pos[$k]));
    usort($keys, static fn (string $a, string $b): int => [$ord($a), $pos[$a]] <=> [$ord($b), $pos[$b]]);
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = $schema[$k];
    }
    return $out;
}

function database_cast(array $d): array
{
    $d['properties'] = schema_ordered(json_decode((string) $d['properties'], true) ?: []);
    $d['description'] = json_decode((string) ($d['description'] ?? '[]'), true) ?: [];
    foreach (['space_id', 'last_edited_by', 'owner_member_id', 'row_count'] as $k) {
        $d[$k] = $d[$k] === null ? null : (int) $d[$k];
    }
    foreach (['is_inline', 'is_locked', 'is_template', 'is_favorite'] as $k) {
        $d[$k] = (bool) $d[$k];
    }
    $d['property_count'] = count($d['properties']);
    $d['plain_title'] = (string) $d['title'];
    return $d;
}

/** The databases the caller may see: $filters space, q (title). Newest edited first. Inline ones too (they show their page in the breadcrumb). */
function find_databases(PDO $pdo, array $filters = [], int $limit = 100): array
{
    $where = ['d.archived_at IS NULL', 'NOT p.is_template'];
    $args = [];
    if (($filters['space'] ?? null) !== null) {
        $where[] = 'd.space_id = :space';
        $args['space'] = (int) $filters['space'];
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = 'd.title ILIKE :q';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
    }
    $st = $pdo->prepare(DATABASE_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY p.last_edited_at DESC, d.created_at DESC LIMIT ' . max(1, min(500, $limit)));
    $st->execute($args);
    $rows = array_map('database_cast', $st->fetchAll());
    foreach ($rows as &$r) {
        $r['breadcrumb'] = $r['parent_page_id'] === null ? [] : database_breadcrumb($pdo, (string) $r['database_id']);
    }
    return $rows;
}

/** The pages above a database (not the database itself): [{page_id, title, visible}]. */
function database_breadcrumb(PDO $pdo, string $uuid): array
{
    $st = $pdo->prepare('SELECT a.page_id, a.plain_title, a.page_id IN (SELECT sp_visible_page_ids()) AS visible FROM sp_page_ancestors(CAST(:id AS uuid)) a');
    $st->execute(['id' => $uuid]);
    return array_map(static fn (array $a): array => ['page_id' => $a['page_id'], 'title' => $a['plain_title'], 'visible' => (bool) $a['visible']], $st->fetchAll());
}

/** One database the caller may see (in the trash too), with its views and breadcrumb; my_level is the permission tree's answer. */
function find_database(PDO $pdo, string $uuid): ?array
{
    if (!is_uuid($uuid)) {
        return null;
    }
    $st = $pdo->prepare(DATABASE_SELECT . ' WHERE d.database_id = CAST(:id AS uuid)');
    $st->execute(['id' => $uuid]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $d = database_cast($r);
    $d['breadcrumb'] = database_breadcrumb($pdo, $uuid);
    $d['views'] = database_views($pdo, $uuid);
    return $d;
}

function view_cast(array $v): array
{
    $v['filter'] = $v['filter'] === null ? null : (json_decode((string) $v['filter'], true) ?: null);
    $v['sort'] = json_decode((string) ($v['sort'] ?? '[]'), true) ?: [];
    $v['visible_properties'] = $v['visible_properties'] === null ? null : pg_text_array((string) $v['visible_properties']);
    $v['wrap'] = (bool) $v['wrap'];
    $v['created_by'] = $v['created_by'] === null ? null : (int) $v['created_by'];
    return $v;
}

const VIEW_SELECT = 'SELECT v.view_id, v.database_id, v.name, v.layout, v.filter, v.sort, v.group_by, v.sub_group_by, v.visible_properties, v.calendar_by, v.timeline_start, v.timeline_end,
       v.card_size, v.card_cover, v.wrap, v.linked_from_page_id, v.position, v.created_by, v.created_at, v.updated_at FROM mcp_database_views v';

/** A database's views in order: its own first, the linked ones (embedded on other pages) after. */
function database_views(PDO $pdo, string $uuid): array
{
    $st = $pdo->prepare(VIEW_SELECT . ' WHERE v.database_id = CAST(:d AS uuid) ORDER BY (v.linked_from_page_id IS NOT NULL), v.position, v.created_at');
    $st->execute(['d' => $uuid]);
    return array_map('view_cast', $st->fetchAll());
}

function find_view(PDO $pdo, string $viewUuid): ?array
{
    if (!is_uuid($viewUuid)) {
        return null;
    }
    $st = $pdo->prepare(VIEW_SELECT . ' WHERE v.view_id = CAST(:v AS uuid)');
    $st->execute(['v' => $viewUuid]);
    $r = $st->fetch();
    return $r === false ? null : view_cast($r);
}

/** A view's own base row (the handler's "a field left out stays", the log's diff), or null. */
function view_state(PDO $pdo, string $viewUuid): ?array
{
    if (!is_uuid($viewUuid)) {
        return null;
    }
    $st = $pdo->prepare('SELECT id::text AS view_id, database_id::text AS database_id, name, layout, filter, sort, group_by, sub_group_by, visible_properties, calendar_by, timeline_start, timeline_end, card_size, card_cover, wrap, linked_from_page_id::text AS linked_from_page_id, position FROM database_views WHERE id = CAST(:v AS uuid)');
    $st->execute(['v' => $viewUuid]);
    $r = $st->fetch();
    return $r === false ? null : view_cast($r + ['created_by' => null]);
}

/** The database's base state for the log's diff: title, description, inline. */
function database_state(PDO $pdo, string $uuid): ?array
{
    $st = $pdo->prepare('SELECT p.plain_title, d.is_inline, d.properties, sp_rich_text_plain(d.description) AS description_text, p.space_id, p.parent_page_id::text AS parent_page_id FROM databases d JOIN pages p ON p.id = d.id WHERE d.id = CAST(:id AS uuid)');
    $st->execute(['id' => $uuid]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['properties'] = schema_ordered(json_decode((string) $r['properties'], true) ?: []);
    $r['is_inline'] = (bool) $r['is_inline'];
    $r['space_id'] = $r['space_id'] === null ? null : (int) $r['space_id'];
    return $r;
}

/** What a database's log payload may carry: its title, flags, counts — never a row's text. */
function database_loggable(array $s): array
{
    return ['title' => mb_substr((string) $s['plain_title'], 0, 120), 'description_length' => mb_strlen((string) ($s['description_text'] ?? '')), 'inline' => $s['is_inline'], 'property_count' => count($s['properties'] ?? [])];
}

/**
 * The rows of a view (or of the database with an ad-hoc filter and sort), through the database's own sp_database_rows(): the filter, the sort and the group
 * are applied in SQL, the values resolved (relations, rollups, people, the computed four). A null filter or sort is the view's. total = the count before paging.
 */
function database_rows(PDO $pdo, string $uuid, ?string $viewUuid, ?array $filter, ?array $sort, int $limit, int $offset): array
{
    $st = $pdo->prepare('SELECT row_id::text AS row_id, title, icon, properties::text AS properties, group_value, sub_group_value, row_position, created_at, last_edited_at, last_edited_by, total
                           FROM sp_database_rows(CAST(:d AS uuid), CAST(:v AS uuid), CAST(:f AS jsonb), CAST(:s AS jsonb), :l, :o)');
    $st->execute(['d' => $uuid, 'v' => $viewUuid, 'f' => $filter === null ? null : json_encode($filter), 's' => $sort === null ? null : json_encode($sort), 'l' => max(1, min(500, $limit)), 'o' => max(0, $offset)]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['properties'] = json_decode((string) $r['properties'], true) ?: [];
        $r['total'] = (int) $r['total'];
        $r['last_edited_by'] = $r['last_edited_by'] === null ? null : (int) $r['last_edited_by'];
        $rows[] = $r;
    }
    return $rows;
}

/** The view's filter and an extra one (a quick title search) as one: and[…]. */
function combined_filter(?array $a, ?array $b): ?array
{
    if ($a === null || $a === []) {
        return $b;
    }
    if ($b === null || $b === []) {
        return $a;
    }
    return ['and' => [$a, $b]];
}

/** A row the caller may see, with its properties resolved and its database's schema; null when it is not a row or not seen. */
function find_row(PDO $pdo, string $rowUuid): ?array
{
    require_once dirname(__DIR__) . '/pages/queries.php';
    $p = find_page($pdo, $rowUuid);
    if ($p === null || $p['parent_database_id'] === null) {
        return null;
    }
    $p['resolved'] = json_decode((string) one_value($pdo, 'SELECT sp_row_resolved(CAST(:id AS uuid))::text', ['id' => $rowUuid]), true) ?: [];
    $p['database'] = find_database($pdo, (string) $p['parent_database_id']);
    return $p;
}

/** The raw stored properties of a row (pages.properties) — what update_row merges into. */
function row_stored(PDO $pdo, string $rowUuid): array
{
    return json_decode((string) one_value($pdo, 'SELECT properties::text FROM pages WHERE id = CAST(:id AS uuid)', ['id' => $rowUuid]), true) ?: [];
}

/** The rows a relation property links to, in order: [{row_id, title}] — only those the caller may see. */
function row_relations(PDO $pdo, string $rowUuid, string $key): array
{
    $st = $pdo->prepare('SELECT r.to_row_id::text AS row_id, p.plain_title AS title FROM mcp_row_relations r JOIN mcp_pages p ON p.page_id = r.to_row_id
                          WHERE r.from_row_id = CAST(:r AS uuid) AND r.property_key = :k AND p.archived_at IS NULL ORDER BY r.position');
    $st->execute(['r' => $rowUuid, 'k' => $key]);
    return $st->fetchAll();
}

/** The rows of a database that a relation picker may offer (title search): [{row_id, title}]. */
function relation_candidates(PDO $pdo, string $targetDatabase, string $q = '', int $limit = 30): array
{
    $st = $pdo->prepare('SELECT p.page_id::text AS row_id, p.plain_title AS title FROM mcp_pages p
                          WHERE p.parent_database_id = CAST(:d AS uuid) AND p.archived_at IS NULL AND NOT p.is_template' . ($q !== '' ? ' AND p.plain_title ILIKE :q' : '') . '
                          ORDER BY p.plain_title LIMIT ' . max(1, min(100, $limit)));
    $args = ['d' => $targetDatabase];
    if ($q !== '') {
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    }
    $st->execute($args);
    return $st->fetchAll();
}

/** row id → cover attachment id (a page cover), for the gallery. */
function row_covers(PDO $pdo, array $rowIds): array
{
    if ($rowIds === []) {
        return [];
    }
    $st = $pdo->prepare('SELECT page_id::text AS id, cover_attachment_id FROM mcp_pages WHERE page_id = ANY (CAST(:ids AS uuid[])) AND cover_attachment_id IS NOT NULL');
    $st->execute(['ids' => '{' . implode(',', $rowIds) . '}']);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['id']] = (int) $r['cover_attachment_id'];
    }
    return $out;
}

/** An attachment's id → [filename, mime] for the image files a cover can show. */
function attachment_mimes(PDO $pdo, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $st = $pdo->prepare('SELECT id, filename, mime_type FROM attachments WHERE id = ANY (CAST(:ids AS bigint[]))');
    $st->execute(['ids' => '{' . implode(',', array_map('intval', $ids)) . '}']);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int) $r['id']] = $r;
    }
    return $out;
}

/** The members a people property may name: the ones the caller sees. [{member_id, display_name, is_agent}] */
function member_choices(PDO $pdo): array
{
    $rows = $pdo->query('SELECT member_id, display_name, is_agent FROM mcp_members ORDER BY is_agent, display_name')->fetchAll();
    return array_map(static fn (array $m): array => ['member_id' => (int) $m['member_id'], 'display_name' => $m['display_name'], 'is_agent' => (bool) $m['is_agent']], $rows);
}

/** The properties a view shows, in schema order, honouring its visible list; the title property always first. [key => def] */
function visible_schema(array $schema, ?array $visible, string $titleKey): array
{
    $out = [];
    if (isset($schema[$titleKey])) {
        $out[$titleKey] = $schema[$titleKey];
    }
    foreach ($schema as $k => $def) {
        if ($k === $titleKey) {
            continue;
        }
        if ($visible === null || in_array($k, $visible, true)) {
            $out[$k] = $def;
        }
    }
    return $out;
}

/** The databases a relation may name (any the caller can see), for the schema form. [{database_id, title}] */
function relation_targets(PDO $pdo): array
{
    return $pdo->query('SELECT d.database_id::text AS database_id, d.title FROM mcp_databases d JOIN mcp_pages p ON p.page_id = d.database_id WHERE d.archived_at IS NULL AND NOT p.is_template ORDER BY d.title')->fetchAll();
}

/** The spaces and pages a database may be made in (the create form): spaces the caller may write in; pages they may edit. */
function database_homes(PDO $pdo): array
{
    $spaces = $pdo->query("SELECT s.space_id, s.name, s.icon FROM mcp_spaces s WHERE s.archived_at IS NULL AND sp_level_rank(sp_space_level(s.space_id)) >= 4 ORDER BY s.name")->fetchAll();
    $pages = $pdo->query("SELECT p.page_id::text AS page_id, p.plain_title FROM mcp_pages p WHERE p.archived_at IS NULL AND NOT p.is_template AND p.parent_database_id IS NULL AND p.kind = 'page' AND p.my_level IN ('edit', 'full') ORDER BY p.last_edited_at DESC LIMIT 150")->fetchAll();
    return ['spaces' => $spaces, 'pages' => $pages];
}

/** The database templates (is_template, kind database) the caller may copy. [{page_id, plain_title, icon}] */
function database_templates(PDO $pdo): array
{
    return $pdo->query("SELECT p.page_id::text AS page_id, p.plain_title, p.icon FROM mcp_pages p WHERE p.is_template AND p.kind = 'database' AND p.archived_at IS NULL ORDER BY p.plain_title")->fetchAll();
}
