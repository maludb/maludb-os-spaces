<?php
declare(strict_types=1);

/**
 * Pages (slice 2): reads through mcp_pages and the read functions (the permission tree decides who sees what — sp_visible_page_ids(), one
 * query); writes in write.php. A page's text is read through sp_page_tree() and rendered by app/richtext/render.php.
 */

const PAGE_SELECT = 'SELECT p.page_id, p.space_id, s.name AS space_name, p.parent_page_id, p.parent_database_id, p.kind, p.plain_title, p.icon, p.cover_attachment_id, p.is_locked, p.is_template,
       p.owner_member_id, om.display_name AS owner_name, p.wiki_owner_member_id, wm.display_name AS wiki_owner_name, p.verification_state, p.verified_at, p.verify_until, p.permission_root_id,
       p.section_id, p.content_rev, p.version_no, p.created_by, p.created_at, p.last_edited_by, em.display_name AS editor_name, p.last_edited_at, p.archived_at, p.updated_at,
       p.my_level, p.is_published, p.is_favorite, p.child_count, p.open_comment_count
  FROM mcp_pages p LEFT JOIN mcp_spaces s ON s.space_id = p.space_id LEFT JOIN members om ON om.id = p.owner_member_id LEFT JOIN members wm ON wm.id = p.wiki_owner_member_id LEFT JOIN members em ON em.id = p.last_edited_by';

function page_cast(array $p): array
{
    foreach (['space_id', 'cover_attachment_id', 'owner_member_id', 'wiki_owner_member_id', 'section_id', 'content_rev', 'version_no', 'created_by', 'last_edited_by', 'child_count', 'open_comment_count'] as $k) {
        $p[$k] = $p[$k] === null ? null : (int) $p[$k];
    }
    foreach (['is_locked', 'is_template', 'is_published', 'is_favorite'] as $k) {
        $p[$k] = (bool) $p[$k];
    }
    $p['is_row'] = $p['parent_database_id'] !== null;
    $p['is_private'] = $p['space_id'] === null;
    return $p;
}

/** The pages the caller may see: $filters space, q (title), kind (page, database, row), changed (days), parent (uuid), private (bool), template (bool). Newest edited first. */
function find_pages(PDO $pdo, array $filters = [], int $limit = 100): array
{
    $where = ['p.archived_at IS NULL'];
    $args = [];
    $where[] = !empty($filters['template']) ? 'p.is_template' : 'NOT p.is_template';
    if (($filters['kind'] ?? '') === 'row') {
        $where[] = 'p.parent_database_id IS NOT NULL';
    } elseif (in_array($filters['kind'] ?? '', ['page', 'database'], true)) {
        $where[] = 'p.kind = :kind AND p.parent_database_id IS NULL';
        $args['kind'] = $filters['kind'];
    } else {
        $where[] = 'p.parent_database_id IS NULL';
    }
    if (($filters['space'] ?? null) !== null) {
        $where[] = 'p.space_id = :space';
        $args['space'] = (int) $filters['space'];
    }
    if (!empty($filters['private'])) {
        $where[] = 'p.space_id IS NULL';
    }
    if (($filters['parent'] ?? null) !== null && is_uuid($filters['parent'])) {
        $where[] = 'p.parent_page_id = CAST(:parent AS uuid)';
        $args['parent'] = (string) $filters['parent'];
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = 'p.plain_title ILIKE :q';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
    }
    if (($filters['changed'] ?? null) !== null) {
        $where[] = 'p.last_edited_at > now() - make_interval(days => :days)';
        $args['days'] = (int) $filters['changed'];
    }
    if (!empty($filters['editable'])) {
        $where[] = "p.my_level IN ('edit', 'full')";
    }
    $st = $pdo->prepare(PAGE_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY p.last_edited_at DESC, p.created_at DESC LIMIT ' . max(1, min(500, $limit)));
    $st->execute($args);
    return array_map('page_cast', $st->fetchAll());
}

/** One page the caller may see (in the trash too), with its breadcrumb. */
function find_page(PDO $pdo, string $uuid): ?array
{
    if (!is_uuid($uuid)) {
        return null;
    }
    $st = $pdo->prepare(PAGE_SELECT . ' WHERE p.page_id = CAST(:id AS uuid)');
    $st->execute(['id' => $uuid]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $p = page_cast($r);
    $st = $pdo->prepare('SELECT a.page_id, a.plain_title, a.depth, a.page_id IN (SELECT sp_visible_page_ids()) AS visible FROM sp_page_ancestors(CAST(:id AS uuid)) a');
    $st->execute(['id' => $uuid]);
    $p['breadcrumb'] = array_map(static fn (array $a): array => ['page_id' => $a['page_id'], 'title' => $a['plain_title'], 'visible' => (bool) $a['visible']], $st->fetchAll());
    return $p;
}

/** The base row's state (the handler's "a field left out stays"; the diff for the log), or null. */
function page_state(PDO $pdo, string $uuid): ?array
{
    $st = $pdo->prepare('SELECT plain_title, icon, cover_attachment_id, space_id, parent_page_id, parent_database_id, owner_member_id, is_locked, is_template, archived_at, kind, wiki_owner_member_id, verification_state, verify_until FROM pages WHERE id = CAST(:id AS uuid)');
    $st->execute(['id' => $uuid]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    foreach (['cover_attachment_id', 'space_id', 'owner_member_id', 'wiki_owner_member_id'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    $r['is_locked'] = (bool) $r['is_locked'];
    $r['is_template'] = (bool) $r['is_template'];
    return $r;
}

/** What a page's log payload may carry: the title, ids, flags — never its text. */
function page_loggable(array $s): array
{
    return ['title' => mb_substr((string) $s['plain_title'], 0, 120), 'icon' => $s['icon'], 'cover_attachment_id' => $s['cover_attachment_id']];
}

/** The block tree as sp_page_tree() answers it (decoded). */
function page_tree_json(PDO $pdo, string $uuid): array
{
    return json_decode((string) one_value($pdo, 'SELECT sp_page_tree(CAST(:id AS uuid))::text', ['id' => $uuid]), true) ?: [];
}

/** The ids of every page a tree refers to (links, child pages, mentions), with the titles the caller may see. */
function page_titles_in_tree(PDO $pdo, array $tree, array $acc = []): array
{
    $ids = [];
    $walk = static function (array $nodes) use (&$walk, &$ids): void {
        foreach ($nodes as $b) {
            $c = is_array($b['content'] ?? null) ? $b['content'] : [];
            foreach (['page_id', 'database_id'] as $k) {
                if (isset($c[$k]) && is_uuid($c[$k])) { $ids[$c[$k]] = true; }
            }
            foreach ($c['rich_text'] ?? [] as $r) {
                if (($r['type'] ?? '') === 'mention' && in_array($r['mention']['type'] ?? '', ['page', 'database'], true) && is_uuid($r['mention']['id'] ?? null)) { $ids[$r['mention']['id']] = true; }
            }
            if (!empty($b['children']) && is_array($b['children'])) { $walk($b['children']); }
        }
    };
    $walk($tree);
    if ($ids === []) {
        return $acc;
    }
    $st = $pdo->prepare('SELECT page_id::text AS id, plain_title FROM mcp_pages WHERE page_id = ANY (CAST(:ids AS uuid[]))');
    $st->execute(['ids' => '{' . implode(',', array_keys($ids)) . '}']);
    foreach ($st->fetchAll() as $r) {
        $acc[$r['id']] = $r['plain_title'] !== '' ? $r['plain_title'] : 'Untitled';
    }
    return $acc;
}

function page_children(PDO $pdo, string $uuid): array
{
    $st = $pdo->prepare('SELECT page_id, title, icon, kind, has_children FROM sp_page_children(CAST(:id AS uuid))');
    $st->execute(['id' => $uuid]);
    return $st->fetchAll();
}

/** Who may see a page and why (sp_page_permissions_explained). */
function page_permissions(PDO $pdo, string $uuid): array
{
    $st = $pdo->prepare('SELECT source, source_page_id, source_title, principal_kind, principal_id, principal_name, level FROM sp_page_permissions_explained(CAST(:id AS uuid))');
    $st->execute(['id' => $uuid]);
    return array_map(static function (array $r): array { $r['principal_id'] = $r['principal_id'] === null ? null : (int) $r['principal_id']; return $r; }, $st->fetchAll());
}

/** The page's live publication, or null. */
function publication(PDO $pdo, string $uuid): ?array
{
    $st = $pdo->prepare('SELECT publication_id, page_id, include_subpages, noindex, public_properties, layout, published_by, (SELECT display_name FROM members WHERE id = pb.published_by) AS published_by_name, published_at, views, last_viewed_at FROM mcp_page_publications pb WHERE page_id = CAST(:id AS uuid) AND revoked_at IS NULL');
    $st->execute(['id' => $uuid]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['publication_id'] = (int) $r['publication_id'];
    $r['include_subpages'] = (bool) $r['include_subpages'];
    $r['noindex'] = (bool) $r['noindex'];
    $r['views'] = (int) $r['views'];
    $r['public_properties'] = pg_text_array((string) $r['public_properties']);
    return $r;
}

function page_versions_list(PDO $pdo, string $uuid): array
{
    $st = $pdo->prepare('SELECT v.version_id, v.version_no, v.content_rev, v.reason, v.saved_by, (SELECT display_name FROM members WHERE id = v.saved_by) AS saved_by_name, v.created_at, v.text_length FROM mcp_page_versions v WHERE v.page_id = CAST(:id AS uuid) ORDER BY v.version_no DESC');
    $st->execute(['id' => $uuid]);
    return array_map(static function (array $v): array { $v['version_id'] = (int) $v['version_id']; $v['version_no'] = (int) $v['version_no']; $v['text_length'] = (int) ($v['text_length'] ?? 0); return $v; }, $st->fetchAll());
}

/** The trash the caller may restore (the admin: everyone's), newest first. */
function trash_list(PDO $pdo, ?int $spaceId = null): array
{
    $st = $pdo->prepare('SELECT t.page_id, t.plain_title, t.icon, t.kind, t.space_id, s.name AS space_name, t.archived_at, t.archived_by, (SELECT display_name FROM members WHERE id = t.archived_by) AS archived_by_name, t.purge_at
                           FROM mcp_trash t LEFT JOIN mcp_spaces s ON s.space_id = t.space_id' . ($spaceId === null ? '' : ' WHERE t.space_id = :s') . ' ORDER BY t.archived_at DESC');
    $st->execute($spaceId === null ? [] : ['s' => $spaceId]);
    return array_map(static function (array $t): array { $t['space_id'] = $t['space_id'] === null ? null : (int) $t['space_id']; return $t; }, $st->fetchAll());
}

/** The templates: the workspace's (General's) and a space's (or every space of mine when null). */
function find_templates(PDO $pdo, ?int $spaceId = null): array
{
    $st = $pdo->prepare('SELECT p.page_id, p.plain_title, p.icon, p.kind, p.space_id, s.name AS space_name, s.is_default, p.last_edited_at FROM mcp_pages p LEFT JOIN mcp_spaces s ON s.space_id = p.space_id
                          WHERE p.is_template AND p.archived_at IS NULL AND p.template_of_database_id IS NULL' . ($spaceId === null ? '' : ' AND (p.space_id = :s OR s.is_default)') . ' ORDER BY s.is_default DESC NULLS LAST, lower(p.plain_title)');
    $st->execute($spaceId === null ? [] : ['s' => $spaceId]);
    return array_map(static function (array $t): array { $t['space_id'] = $t['space_id'] === null ? null : (int) $t['space_id']; $t['is_default'] = (bool) $t['is_default']; return $t; }, $st->fetchAll());
}

/** A wiki's pages with their state, expired first (sp_wiki_status). */
function wiki_status(PDO $pdo, int $spaceId): array
{
    $st = $pdo->prepare("SELECT page_id, title, space_id, space_name, verification_state, verified_at, verify_until, wiki_owner_member_id, owner_name, last_edited_at, days_since_edit
                           FROM sp_wiki_status(:s) ORDER BY CASE verification_state WHEN 'expired' THEN 0 WHEN 'none' THEN 1 ELSE 2 END, verify_until NULLS FIRST, lower(title)");
    $st->execute(['s' => $spaceId]);
    return array_map(static function (array $w): array { $w['wiki_owner_member_id'] = $w['wiki_owner_member_id'] === null ? null : (int) $w['wiki_owner_member_id']; $w['days_since_edit'] = (int) $w['days_since_edit']; return $w; }, $st->fetchAll());
}

function page_timeline(PDO $pdo, string $uuid, int $limit = 50): array
{
    require_once dirname(__DIR__) . '/activity/queries.php';
    return find_record_activity($pdo, 'page', $uuid, $limit);
}

/** The spaces the caller may make a page in (edit or full on the space), live ones. */
function spaces_for_create(PDO $pdo): array
{
    return $pdo->query("SELECT s.space_id, s.name, s.icon, s.kind FROM mcp_spaces s WHERE s.archived_at IS NULL AND sp_level_rank(sp_space_level(s.space_id)) >= 4 ORDER BY s.is_default DESC, lower(s.name)")->fetchAll();
}

/** The pages the caller may make a page under, by title (edit or full), newest edited first. */
function pages_for_parent_pick(PDO $pdo, string $q = '', int $limit = 100): array
{
    return find_pages($pdo, ['q' => $q, 'editable' => true], $limit);
}

/** The guests the caller may share to (mcp_members: external, Guest). */
function guests_for_pick(PDO $pdo): array
{
    return $pdo->query('SELECT member_id, display_name FROM mcp_members WHERE is_guest ORDER BY display_name')->fetchAll();
}

const PAGE_LEVEL_WORDS = ['view' => 'View', 'comment' => 'Comment', 'edit_content' => 'Edit content', 'edit' => 'Edit', 'full' => 'Full'];
const GUEST_LEVELS = ['view', 'comment', 'edit'];
const VERIFY_MONTHS = [1, 3, 6, 12];
