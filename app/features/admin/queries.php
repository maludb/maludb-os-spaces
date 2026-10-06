<?php
declare(strict_types=1);
/**
 * The admin pages' reads (slice 9): the workspace settings and the emoji list, every space, the published pages, the retention overview, everyone's trash. Through the mcp_* views — for the admin they answer for everyone —
 * except the settings row itself, read from sp_settings because the view leaves out the embed hosts and the public base URL (the form needs both); every screen that calls it has passed `settings.manage` first.
 */
require_once dirname(__DIR__) . '/spaces/queries.php';
require_once dirname(__DIR__) . '/pages/queries.php';

/** The settings' columns with their kinds, in the form's order: key => [label, kind, options|bounds]. The bounds are the table's CHECKs (db/005). */
const SETTINGS_FIELDS = [
    'business_name' => ['Business name', 'text'],
    'default_space_kind' => ['New spaces are', 'enum', ['open' => 'Open to everyone', 'closed' => 'Closed (members ask to join)', 'private' => 'Private']],
    'default_member_level' => ['What members of a space may do', 'enum', ['view' => 'View', 'comment' => 'Comment', 'edit_content' => 'Edit content', 'edit' => 'Edit', 'full' => 'Full access']],
    'default_everyone_level' => ['What everyone may do in an open space', 'enum', ['none' => 'Nothing', 'view' => 'View', 'comment' => 'Comment', 'edit_content' => 'Edit content', 'edit' => 'Edit']],
    'version_snapshot_minutes' => ['Save a version after minutes of editing', 'int', [1, 1440]],
    'version_retention_days' => ['Keep versions for days', 'int', [1, 3650]],
    'trash_retention_days' => ['Keep the trash for days', 'int', [1, 365]],
    'stale_page_days' => ['A page is stale after days', 'int', [7, 3650]],
    'unanswered_hours' => ['A question is unanswered after hours', 'int', [1, 720]],
    'wiki_default_verify_months' => ['Verification lasts months', 'enum', ['1' => '1 month', '3' => '3 months', '6' => '6 months', '12' => '12 months']],
    'max_attachment_mb' => ['Largest attachment in MB', 'int', [1, 1024]],
    'allowed_embed_hosts' => ['Hosts an embed may come from (one per line)', 'lines'],
    'public_pages_noindex' => ['Ask search engines not to index published pages', 'bool'],
    'public_base_url' => ['Public base URL', 'url'],
    'digest_hour' => ['Morning digest hour (0 to 23)', 'int', [0, 23]],
    'week_start_dow' => ['The week starts on', 'enum', ['0' => 'Sunday', '1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday']],
    'timezone' => ['Time zone', 'timezone'],
    'group_dm_max_members' => ['Largest group message', 'int', [3, 50]],
    'away_minutes' => ['Away after minutes', 'int', [1, 1440]],
    'admin_channel_name' => ['The admin channel (where the Librarian reports)', 'slug'],
    'agents_join_default_space' => ['Agents join General when hired', 'bool'],
];

/** The settings row as the form shows it: every column but id and updated_at; the embed hosts as a list, the attachment limit in MB as well. */
function find_settings(PDO $pdo): array
{
    $r = $pdo->query('SELECT business_name, default_space_kind, default_member_level, default_everyone_level, version_snapshot_minutes, version_retention_days, trash_retention_days, stale_page_days, unanswered_hours,
                             wiki_default_verify_months, max_attachment_bytes, allowed_embed_hosts, public_pages_noindex, public_base_url, digest_hour, week_start_dow, timezone, group_dm_max_members, away_minutes,
                             admin_channel_name, agents_join_default_space, updated_at FROM sp_settings WHERE id = 1')->fetch();
    foreach (['version_snapshot_minutes', 'version_retention_days', 'trash_retention_days', 'stale_page_days', 'unanswered_hours', 'wiki_default_verify_months', 'digest_hour', 'week_start_dow', 'group_dm_max_members', 'away_minutes'] as $k) {
        $r[$k] = (int) $r[$k];
    }
    $r['max_attachment_bytes'] = (int) $r['max_attachment_bytes'];
    $r['max_attachment_mb'] = (int) round($r['max_attachment_bytes'] / 1048576);
    $r['allowed_embed_hosts'] = pg_text_array((string) $r['allowed_embed_hosts']);
    foreach (['public_pages_noindex', 'agents_join_default_space'] as $k) { $r[$k] = (bool) $r[$k]; }
    return $r;
}

/** The comparable state of the settings (what a diff is made of): the columns, the embed hosts a list. */
function settings_state(array $s): array
{
    unset($s['updated_at'], $s['max_attachment_mb']);
    return $s;
}

/** Save the settings: $fields are column => value (already checked; only the keys to change). Returns ['changed' => [key => [before, after]]] and nothing is written when nothing differs. The database's CHECKs stand behind every number. */
function save_settings(PDO $pdo, array $fields, int $by): array
{
    $before = settings_state(find_settings($pdo));
    $after = $fields + $before;
    $diff = sp_diff($before, array_intersect_key($after, $before));
    if ($diff['after'] === []) { return ['changed' => [], 'before' => [], 'after' => []]; }
    $sets = [];
    $args = [];
    foreach ($diff['after'] as $k => $v) {
        $sets[] = $k . ' = :' . $k;
        $args[$k] = $k === 'allowed_embed_hosts' ? pg_array_literal($v) : (is_bool($v) ? ($v ? 't' : 'f') : $v);
    }
    $st = $pdo->prepare('UPDATE sp_settings SET ' . implode(', ', $sets) . ' WHERE id = 1');
    $st->execute($args);
    return ['changed' => array_keys($diff['after']), 'before' => $diff['before'], 'after' => $diff['after']];
}

/** Every emoji of the picker, in order. [{shortcode, emoji, keywords[]}] */
function find_emoji(PDO $pdo): array
{
    return array_map(static function (array $e): array { $e['keywords'] = pg_text_array((string) $e['keywords']); return $e; },
        $pdo->query('SELECT shortcode, emoji, keywords FROM emoji_shortcodes ORDER BY sort_order, shortcode')->fetchAll());
}

/** Add an emoji or replace the one with this shortcode (a seeded one may be replaced). */
function save_emoji(PDO $pdo, string $shortcode, string $emoji, array $keywords): void
{
    $st = $pdo->prepare('INSERT INTO emoji_shortcodes (shortcode, emoji, keywords, sort_order) VALUES (:s, :e, CAST(:k AS text[]), COALESCE((SELECT max(sort_order) FROM emoji_shortcodes), 0) + 1)
                         ON CONFLICT (shortcode) DO UPDATE SET emoji = EXCLUDED.emoji, keywords = EXCLUDED.keywords');
    $st->execute(['s' => $shortcode, 'e' => $emoji, 'k' => pg_array_literal($keywords)]);
}

/** Remove an emoji; refuses (Not found.) one that is not there. */
function delete_emoji(PDO $pdo, string $shortcode): void
{
    $st = $pdo->prepare('DELETE FROM emoji_shortcodes WHERE shortcode = :s');
    $st->execute(['s' => $shortcode]);
    if ($st->rowCount() === 0) { throw new DomainException('Not found.'); }
}

/** Every space, archived ones last (the admin sees all). $f: kind, archived (bool: include them). With the counts the view gives. */
function admin_spaces(PDO $pdo, array $f): array
{
    return find_spaces($pdo, ['kind' => $f['kind'] ?? '', 'include_archived' => !empty($f['archived'])], 500);
}

/** Every published page: title, space, who and when, subpages, noindex, views, last opened. Newest published first. */
function published_pages(PDO $pdo): array
{
    $rows = $pdo->query("SELECT pb.publication_id, pb.page_id::text AS page_id, p.plain_title AS title, p.icon, p.space_id, (SELECT s.name FROM mcp_spaces s WHERE s.space_id = p.space_id) AS space_name,
                                pb.published_by, (SELECT m.display_name FROM members m WHERE m.id = pb.published_by) AS published_by_name, pb.published_at, pb.include_subpages, pb.noindex, pb.views, pb.last_viewed_at
                           FROM mcp_page_publications pb LEFT JOIN mcp_pages p ON p.page_id = pb.page_id WHERE pb.revoked_at IS NULL ORDER BY pb.published_at DESC")->fetchAll();
    return array_map(static function (array $r): array {
        $r['publication_id'] = (int) $r['publication_id']; $r['space_id'] = $r['space_id'] === null ? null : (int) $r['space_id'];
        $r['include_subpages'] = (bool) $r['include_subpages']; $r['noindex'] = (bool) $r['noindex']; $r['views'] = (int) $r['views'];
        return $r;
    }, $rows);
}

/**
 * Retention at a glance: the channels of every space (public and private, not archived) with their retention, grouped by space — those with one and those without — and the two retentions the settings hold.
 * ['spaces' => [{space_id, name, with: [channel…], without: [channel…]}], 'with' => n, 'without' => n, 'versions_days', 'trash_days'] — a channel {channel_id, name, kind, retention_days, message_count}.
 */
function retention_overview(PDO $pdo): array
{
    $rows = $pdo->query("SELECT c.channel_id, c.name, c.kind, c.space_id, s.name AS space_name, c.retention_days, c.message_count FROM mcp_channels c LEFT JOIN mcp_spaces s ON s.space_id = c.space_id
                          WHERE c.kind IN ('public', 'private') AND c.archived_at IS NULL ORDER BY lower(COALESCE(s.name, '')), lower(c.name)")->fetchAll();
    $spaces = [];
    $with = 0;
    foreach ($rows as $r) {
        $sid = (int) $r['space_id'];
        $spaces[$sid] ??= ['space_id' => $sid, 'name' => (string) $r['space_name'], 'with' => [], 'without' => []];
        $c = ['channel_id' => (int) $r['channel_id'], 'name' => $r['name'], 'kind' => $r['kind'], 'retention_days' => $r['retention_days'] === null ? null : (int) $r['retention_days'], 'message_count' => (int) $r['message_count']];
        $spaces[$sid][$c['retention_days'] === null ? 'without' : 'with'][] = $c;
        if ($c['retention_days'] !== null) { $with++; }
    }
    $s = $pdo->query('SELECT version_retention_days, trash_retention_days FROM mcp_settings')->fetch();
    return ['spaces' => array_values($spaces), 'with' => $with, 'without' => count($rows) - $with, 'versions_days' => (int) $s['version_retention_days'], 'trash_days' => (int) $s['trash_retention_days']];
}

/** Everyone's trash (the admin's mcp_trash), newest first: the pages, with a `soon` flag (purged within 3 days) and the total Purge all would remove. ['rows' => [...], 'count' => n, 'spaces' => [id => name]] */
function admin_trash(PDO $pdo, ?int $spaceId): array
{
    $rows = array_map(static function (array $t): array {
        $t['soon'] = $t['purge_at'] !== null && strtotime((string) $t['purge_at']) <= time() + 3 * 86400;
        $t['page_id'] = (string) $t['page_id'];
        return $t;
    }, trash_list($pdo, $spaceId));
    $names = [];
    foreach ($pdo->query('SELECT DISTINCT t.space_id, s.name FROM mcp_trash t JOIN mcp_spaces s ON s.space_id = t.space_id ORDER BY s.name')->fetchAll() as $r) { $names[(int) $r['space_id']] = $r['name']; }
    return ['rows' => $rows, 'count' => count($rows), 'spaces' => $names];
}
