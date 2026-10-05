<?php
declare(strict_types=1);

/**
 * Spaces, their members, requests and sections (slice 1): reads through the mcp_* views (the views decide who sees a space —
 * open and closed to everyone in the business, private to its members and the admin, a guest only where added), writes to the
 * base tables (write.php). The database is the referee (db/006).
 */

const SPACE_SELECT = 'SELECT s.space_id, s.name, s.slug, s.icon, s.description, s.kind, s.is_default, s.department_id, d.name AS department_name, s.member_level, s.everyone_level,
       s.is_wiki, s.wiki_default_verify_months, s.default_channel_id, s.created_by, s.archived_at, s.created_at, s.updated_at, s.i_am_member, s.i_am_owner,
       s.member_count, s.page_count, s.channel_count,
       (SELECT string_agg(m.display_name, \', \' ORDER BY m.display_name) FROM space_members sm JOIN members m ON m.id = sm.member_id WHERE sm.space_id = s.space_id AND sm.role = \'owner\') AS owner_names
  FROM mcp_spaces s LEFT JOIN mcp_departments d ON d.department_id = s.department_id';

function space_cast(array $s): array
{
    foreach (['space_id', 'department_id', 'wiki_default_verify_months', 'default_channel_id', 'created_by', 'member_count', 'page_count', 'channel_count'] as $k) {
        $s[$k] = $s[$k] === null ? null : (int) $s[$k];
    }
    foreach (['is_default', 'is_wiki', 'i_am_member', 'i_am_owner'] as $k) {
        $s[$k] = (bool) $s[$k];
    }
    return $s;
}

/** The spaces the caller may see. $filters: kind (open, closed, private), q (name), mine (true: only the caller's), include_archived. Archived ones last. */
function find_spaces(PDO $pdo, array $filters = [], int $limit = 100): array
{
    $where = [];
    $args = [];
    if (empty($filters['include_archived'])) {
        $where[] = 's.archived_at IS NULL';
    }
    if (in_array($filters['kind'] ?? '', ['open', 'closed', 'private'], true)) {
        $where[] = 's.kind = :kind';
        $args['kind'] = $filters['kind'];
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = '(s.name ILIKE :q OR s.slug ILIKE :q)';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
    }
    if (!empty($filters['mine'])) {
        $where[] = 's.i_am_member';
    }
    $st = $pdo->prepare(SPACE_SELECT . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY s.archived_at IS NOT NULL, s.is_default DESC, lower(s.name) LIMIT ' . max(1, min(500, $limit)));
    $st->execute($args);
    return array_map('space_cast', $st->fetchAll());
}

/** One space the caller may see (archived or not), with the owner ids and the caller's level on its root pages. */
function find_space(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(SPACE_SELECT . ' WHERE s.space_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $s = space_cast($r);
    $st = $pdo->prepare("SELECT member_id FROM space_members WHERE space_id = :id AND role = 'owner' ORDER BY member_id");
    $st->execute(['id' => $id]);
    $s['owner_ids'] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $s['my_level'] = (string) one_value($pdo, 'SELECT sp_space_level(:id)', ['id' => $id]);
    return $s;
}

function find_space_by_slug(PDO $pdo, string $slug): ?array
{
    $id = one_value($pdo, 'SELECT space_id FROM mcp_spaces WHERE slug = :s', ['s' => $slug]);
    return $id === null ? null : find_space($pdo, (int) $id);
}

/** The base row's state (the handler's "a field left out stays"; the diff for the log), or null. */
function space_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT name, icon, description, kind, member_level, everyone_level, is_wiki, wiki_default_verify_months, archived_at, is_default, department_id FROM spaces WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['is_wiki'] = (bool) $r['is_wiki'];
    $r['is_default'] = (bool) $r['is_default'];
    $r['wiki_default_verify_months'] = $r['wiki_default_verify_months'] === null ? null : (int) $r['wiki_default_verify_months'];
    $r['department_id'] = $r['department_id'] === null ? null : (int) $r['department_id'];
    return $r;
}

/** What a space's log payload may carry: never the description's words (its length is). */
function space_loggable(array $s): array
{
    return ['name' => $s['name'], 'icon' => $s['icon'], 'kind' => $s['kind'], 'member_level' => $s['member_level'], 'everyone_level' => $s['everyone_level'], 'is_wiki' => $s['is_wiki'],
            'verify_months' => $s['wiki_default_verify_months'], 'description_length' => mb_strlen((string) ($s['description'] ?? ''))];
}

/** The space's home: its sections with their root pages, the pages with no section, the channels I may see, the members, the wiki's counts. */
function space_home(PDO $pdo, int $spaceId): array
{
    $pages = $pdo->prepare('SELECT page_id, plain_title AS title, icon, kind, section_id, position, last_edited_at, child_count, my_level FROM mcp_pages
                             WHERE space_id = :s AND parent_page_id IS NULL AND parent_database_id IS NULL AND archived_at IS NULL AND NOT is_template ORDER BY position, created_at');
    $pages->execute(['s' => $spaceId]);
    $bySection = [];
    $loose = [];
    foreach ($pages->fetchAll() as $p) {
        $p['child_count'] = (int) $p['child_count'];
        if ($p['section_id'] === null) {
            $loose[] = $p;
        } else {
            $bySection[(int) $p['section_id']][] = $p;
        }
    }
    $sections = [];
    foreach (space_sections($pdo, $spaceId) as $sec) {
        $sec['pages'] = $bySection[$sec['section_id']] ?? [];
        $sections[] = $sec;
    }
    $ch = $pdo->prepare('SELECT channel_id, name, kind, topic, is_default, i_am_member, i_follow, member_count, last_message_at FROM mcp_channels WHERE space_id = :s AND archived_at IS NULL ORDER BY is_default DESC, name');
    $ch->execute(['s' => $spaceId]);
    $channels = array_map(static function (array $c): array { $c['channel_id'] = (int) $c['channel_id']; $c['is_default'] = (bool) $c['is_default']; $c['i_am_member'] = (bool) $c['i_am_member']; $c['member_count'] = (int) $c['member_count']; return $c; }, $ch->fetchAll());
    $members = space_members($pdo, $spaceId);
    $wiki = null;
    if (db_bool($pdo, 'SELECT is_wiki FROM spaces WHERE id = :s', ['s' => $spaceId])) {
        $w = $pdo->prepare("SELECT count(*) FILTER (WHERE verification_state = 'verified') AS verified, count(*) FILTER (WHERE verification_state = 'expired') AS expired, count(*) FILTER (WHERE verification_state = 'none') AS never FROM sp_wiki_status(:s)");
        $w->execute(['s' => $spaceId]);
        $wiki = array_map('intval', $w->fetch() ?: ['verified' => 0, 'expired' => 0, 'never' => 0]);
    }
    return ['sections' => $sections, 'pages' => $loose, 'channels' => $channels, 'members' => $members, 'wiki' => $wiki];
}

/** Who is in a space (mcp_space_members): owners first, then people, then agents; derived = through the department, no row. */
function space_members(PDO $pdo, int $spaceId): array
{
    $st = $pdo->prepare("SELECT member_id, display_name, is_agent, is_guest, role, joined_at, derived FROM mcp_space_members WHERE space_id = :s ORDER BY role = 'owner' DESC, is_agent, lower(display_name)");
    $st->execute(['s' => $spaceId]);
    return array_map(static function (array $m): array {
        $m['member_id'] = (int) $m['member_id'];
        foreach (['is_agent', 'is_guest', 'derived'] as $k) { $m[$k] = (bool) $m[$k]; }
        return $m;
    }, $st->fetchAll());
}

/** The sections of a space in order, each with its page count. */
function space_sections(PDO $pdo, int $spaceId): array
{
    $st = $pdo->prepare('SELECT x.section_id, x.name, x.position, (SELECT count(*) FROM mcp_pages p WHERE p.section_id = x.section_id AND p.archived_at IS NULL AND NOT p.is_template) AS page_count
                           FROM mcp_space_sections x WHERE x.space_id = :s ORDER BY x.position, x.section_id');
    $st->execute(['s' => $spaceId]);
    return array_map(static function (array $x): array { $x['section_id'] = (int) $x['section_id']; $x['page_count'] = (int) $x['page_count']; return $x; }, $st->fetchAll());
}

/** The requests to join a space the caller may see (an owner: all; else their own): pending first, newest first. */
function space_requests(PDO $pdo, int $spaceId, bool $pendingOnly = false): array
{
    $st = $pdo->prepare("SELECT join_request_id, space_id, member_id, display_name, message, status, decided_by, (SELECT display_name FROM members WHERE id = r.decided_by) AS decided_by_name, decided_at, created_at
                           FROM mcp_space_join_requests r WHERE space_id = :s" . ($pendingOnly ? " AND status = 'pending'" : '') . " ORDER BY status = 'pending' DESC, created_at DESC");
    $st->execute(['s' => $spaceId]);
    return array_map(static function (array $r): array { $r['join_request_id'] = (int) $r['join_request_id']; $r['space_id'] = (int) $r['space_id']; $r['member_id'] = (int) $r['member_id']; $r['decided_by'] = $r['decided_by'] === null ? null : (int) $r['decided_by']; return $r; }, $st->fetchAll());
}

/** The member's own request on a space, pending or the latest decided one, or null. */
function my_request(PDO $pdo, int $spaceId, int $memberId): ?array
{
    $st = $pdo->prepare("SELECT join_request_id, status, message, created_at, decided_at FROM mcp_space_join_requests WHERE space_id = :s AND member_id = :m ORDER BY status = 'pending' DESC, created_at DESC LIMIT 1");
    $st->execute(['s' => $spaceId, 'm' => $memberId]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['join_request_id'] = (int) $r['join_request_id'];
    return $r;
}

/** The templates a space may apply: its own and the workspace's (mcp_pages is_template; slice 2 builds apply and publish). */
function space_templates(PDO $pdo, int $spaceId): array
{
    $st = $pdo->prepare('SELECT page_id, plain_title AS title, icon, kind, space_id, last_edited_at FROM mcp_pages WHERE is_template AND archived_at IS NULL AND template_of_database_id IS NULL AND (space_id = :s OR space_id IS NULL) ORDER BY space_id IS NULL, lower(plain_title)');
    $st->execute(['s' => $spaceId]);
    return $st->fetchAll();
}

/** The space's trail, in words (mcp_activity_log by space_id). */
function space_timeline(PDO $pdo, int $spaceId, int $limit = 50): array
{
    require_once dirname(__DIR__) . '/activity/queries.php';
    return find_record_activity($pdo, 'space', $spaceId, $limit);
}

/** The fixed vocabularies the form offers. */
const SPACE_KINDS = ['open' => 'Open — everyone in the business sees it and may join', 'closed' => 'Closed — everyone sees it; joining is on request, an owner approves', 'private' => 'Private — invisible but to its members (and the admin, logged)'];
const SPACE_LEVELS = ['view' => 'View', 'comment' => 'Comment', 'edit_content' => 'Edit content (rows and properties, not the structure)', 'edit' => 'Edit', 'full' => 'Full (share, lock, move, delete)'];
const SPACE_EVERYONE_LEVELS = ['none' => 'Nothing', 'view' => 'View', 'comment' => 'Comment', 'edit_content' => 'Edit content', 'edit' => 'Edit'];
const WIKI_MONTHS = [1, 3, 6, 12];
