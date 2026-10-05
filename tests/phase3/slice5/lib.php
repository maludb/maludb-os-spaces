<?php
/**
 * Helpers for the slice 5 proofs (docs/build-specs/databases.md, "Proof"). Builds on slice 4's lib (its chain: slice 3 → 2 → 1 → Phase 2). databases_world() adds, in Product (closed; Marco
 * its owner, Priya and Dana members): "SMOKE Tasks" — Task (title), Notes, Points, Kind, Tags, Status, Due, Owner, Files, Urgent, Link, Mail, Phone, Created, Creator, Edited, Editor, Ref
 * (TSK-) — with three tasks; "SMOKE Epics" — a two-way relation Tasks and the rollups Points (sum), Done (percent checked), Count, Latest (latest date) — with one epic, "SMOKE Launch",
 * not yet related to any task (the relations proof does that). Everything a proof makes is named "SMOKE …".
 */
require dirname(__DIR__) . '/slice4/lib.php';

function db_schema(string $db): array { return json_decode((string) one('SELECT properties::text FROM databases WHERE id = CAST(:d AS uuid)', ['d' => $db]), true) ?: []; }
/** A schema's keys in the order the person made them (each property carries `order`; the rest follow). */
function db_keys(string $db): array { $s = db_schema($db); $k = array_keys($s); $pos = array_flip($k); usort($k, fn ($a, $b) => [$s[$a]['order'] ?? (($s[$a]['type'] ?? '') === 'title' ? -1 : 100000 + $pos[$a]), $pos[$a]] <=> [$s[$b]['order'] ?? (($s[$b]['type'] ?? '') === 'title' ? -1 : 100000 + $pos[$b]), $pos[$b]]); return $k; }
function row_props(string $row): array { return json_decode((string) one('SELECT properties::text FROM pages WHERE id = CAST(:r AS uuid)', ['r' => $row]), true) ?: []; }
function row_title(string $row): string { return (string) one('SELECT plain_title FROM pages WHERE id = CAST(:r AS uuid)', ['r' => $row]); }
function row_by_title(string $db, string $title): ?string { $v = one('SELECT id::text FROM pages WHERE parent_database_id = CAST(:d AS uuid) AND plain_title = :t AND archived_at IS NULL ORDER BY created_at LIMIT 1', ['d' => $db, 't' => $title]); return $v === false || $v === null ? null : (string) $v; }
function db_by_title(string $title): ?string { $v = one("SELECT p.id::text FROM pages p JOIN databases d ON d.id = p.id WHERE p.plain_title = :t AND p.archived_at IS NULL ORDER BY p.created_at LIMIT 1", ['t' => $title]); return $v === false || $v === null ? null : (string) $v; }
function view_row(string $view): array { return q('SELECT id::text AS id, database_id::text AS database_id, name, layout, filter::text AS filter, sort::text AS sort, group_by, sub_group_by, visible_properties::text AS visible_properties, calendar_by, timeline_start, timeline_end, card_size, card_cover, wrap, linked_from_page_id::text AS linked_from_page_id, position FROM database_views WHERE id = CAST(:v AS uuid)', ['v' => $view])[0] ?? []; }
function views_of(string $db): array { return q('SELECT id::text AS id, name, layout, position, linked_from_page_id::text AS linked_from_page_id FROM database_views WHERE database_id = CAST(:d AS uuid) ORDER BY (linked_from_page_id IS NOT NULL), position, created_at', ['d' => $db]); }
function relations_of(string $row, string $key): array { return array_column(q('SELECT to_row_id::text AS t FROM row_relations WHERE from_row_id = CAST(:r AS uuid) AND property_key = :k ORDER BY position', ['r' => $row, 'k' => $key]), 't'); }
/** The rows sp_database_rows() answers a member (the SQL does the work). */
function rows_as(int $member, string $db, ?string $view = null, ?array $filter = null, ?array $sort = null): array
{
    as_viewer($member);
    $r = q('SELECT row_id::text AS row_id, title, properties::text AS properties, group_value, total FROM sp_database_rows(CAST(:d AS uuid), CAST(:v AS uuid), CAST(:f AS jsonb), CAST(:s AS jsonb))', ['d' => $db, 'v' => $view, 'f' => $filter === null ? null : json_encode($filter), 's' => $sort === null ? null : json_encode($sort)]);
    foreach ($r as &$x) { $x['properties'] = json_decode($x['properties'], true); }
    return $r;
}
function titles(array $rows): array { return array_column($rows, 'title'); }
/** A row through the handler as Marco (a title and properties keyed by display name). */
function mkrow(string $jar, string $db, string $title, array $props = []): string
{
    [, $b] = act($jar, '/databases/rows/save.php', ['database' => $db, 'title' => $title, 'properties' => json_encode($props)]);
    return (string) ($b['record_id'] ?? '');
}
function databases_world(): array
{
    $w = pages_world();
    $marco = as_member(27); $owner = as_member(1);
    $tasks = db_by_title('SMOKE Tasks');
    if ($tasks === null) {
        act($marco, '/spaces/members/add.php', ['space' => $w['product'], 'member' => 30]);
        $props = ['Task' => 'title', 'Notes' => 'rich_text', 'Points' => 'number', 'Kind' => ['type' => 'select', 'options' => ['Bug', 'Feature']], 'Tags' => ['type' => 'multi_select', 'options' => ['ui', 'db']],
            'Status' => ['type' => 'status', 'options' => ['Todo | yellow', 'Doing | blue', 'Done | green']], 'Due' => 'date', 'Owner' => 'people', 'Files' => 'files', 'Urgent' => 'checkbox', 'Link' => 'url', 'Mail' => 'email',
            'Phone' => 'phone_number', 'Created' => 'created_time', 'Creator' => 'created_by', 'Edited' => 'last_edited_time', 'Editor' => 'last_edited_by', 'Ref' => ['type' => 'unique_id', 'prefix' => 'TSK']];
        [, $b] = act($marco, '/databases/save.php', ['title' => 'SMOKE Tasks', 'space' => $w['product'], 'properties' => json_encode($props)]);
        $tasks = (string) $b['record_id'];
        mkrow($marco, $tasks, 'SMOKE Fix the login', ['Points' => 3, 'Kind' => 'Bug', 'Tags' => ['ui'], 'Status' => 'Done', 'Due' => ['start' => '2026-10-01'], 'Owner' => ['SMOKE Priya'], 'Urgent' => true, 'Link' => 'https://example.com/login']);
        mkrow($marco, $tasks, 'SMOKE Build the board', ['Points' => 8, 'Kind' => 'Feature', 'Tags' => ['ui', 'db'], 'Status' => 'Todo', 'Due' => ['start' => '2026-10-20', 'end' => '2026-10-22'], 'Owner' => ['SMOKE Marco'], 'Urgent' => false]);
        mkrow($marco, $tasks, 'SMOKE Ship it', ['Points' => 2, 'Kind' => 'Feature', 'Status' => 'Todo', 'Urgent' => false]);
        [, $b] = act($marco, '/databases/save.php', ['title' => 'SMOKE Epics', 'space' => $w['product'], 'properties' => json_encode(['Epic' => 'title'])]);
        $epics = (string) $b['record_id'];
        act($marco, '/databases/properties/save.php', ['database' => $epics, 'key' => 'Tasks', 'type' => 'relation', 'relation_database' => $tasks, 'two_way' => 'yes']);
        act($marco, '/databases/properties/save.php', ['database' => $epics, 'key' => 'Points', 'type' => 'rollup', 'rollup_relation' => 'Tasks', 'rollup_property' => 'Points', 'rollup_function' => 'sum']);
        act($marco, '/databases/properties/save.php', ['database' => $epics, 'key' => 'Done', 'type' => 'rollup', 'rollup_relation' => 'Tasks', 'rollup_property' => 'Urgent', 'rollup_function' => 'percent_checked']);
        act($marco, '/databases/properties/save.php', ['database' => $epics, 'key' => 'Count', 'type' => 'rollup', 'rollup_relation' => 'Tasks', 'rollup_property' => 'Task', 'rollup_function' => 'count']);
        act($marco, '/databases/properties/save.php', ['database' => $epics, 'key' => 'Latest', 'type' => 'rollup', 'rollup_relation' => 'Tasks', 'rollup_property' => 'Due', 'rollup_function' => 'latest_date']);
        mkrow($marco, $epics, 'SMOKE Launch');
    }
    $epics = db_by_title('SMOKE Epics');
    return $w + ['tasks' => $tasks, 'epics' => $epics, 'fix' => row_by_title($tasks, 'SMOKE Fix the login'), 'build' => row_by_title($tasks, 'SMOKE Build the board'), 'ship' => row_by_title($tasks, 'SMOKE Ship it'), 'launch' => row_by_title($epics, 'SMOKE Launch')];
}

/**
 * A fresh database with Tasks' schema and the three tasks (Fix the login: 3 points, Bug, Done, due 2026-10-01, Priya, urgent; Build the board: 8, Feature, Todo, 2026-10-20 → 22, Marco; Ship it: 2,
 * Feature, Todo, no date), for a proof that counts rows. Named "SMOKE <name>"; made once per name.
 */
function lab_database(string $name): array
{
    $w = pages_world();
    $marco = as_member(27);
    $id = db_by_title('SMOKE ' . $name);
    if ($id === null) {
        $props = ['Task' => 'title', 'Notes' => 'rich_text', 'Points' => 'number', 'Kind' => ['type' => 'select', 'options' => ['Bug', 'Feature']], 'Tags' => ['type' => 'multi_select', 'options' => ['ui', 'db']],
            'Status' => ['type' => 'status', 'options' => ['Todo | yellow', 'Doing | blue', 'Done | green']], 'Due' => 'date', 'Owner' => 'people', 'Files' => 'files', 'Urgent' => 'checkbox', 'Link' => 'url', 'Ref' => ['type' => 'unique_id', 'prefix' => 'LAB']];
        [, $b] = act($marco, '/databases/save.php', ['title' => 'SMOKE ' . $name, 'space' => $w['product'], 'properties' => json_encode($props)]);
        $id = (string) $b['record_id'];
        mkrow($marco, $id, 'SMOKE Fix the login', ['Points' => 3, 'Kind' => 'Bug', 'Tags' => ['ui'], 'Status' => 'Done', 'Due' => ['start' => '2026-10-01'], 'Owner' => ['SMOKE Priya'], 'Urgent' => true]);
        mkrow($marco, $id, 'SMOKE Build the board', ['Points' => 8, 'Kind' => 'Feature', 'Tags' => ['ui', 'db'], 'Status' => 'Todo', 'Due' => ['start' => '2026-10-20', 'end' => '2026-10-22'], 'Owner' => ['SMOKE Marco'], 'Urgent' => false]);
        mkrow($marco, $id, 'SMOKE Ship it', ['Points' => 2, 'Kind' => 'Feature', 'Status' => 'Todo', 'Urgent' => false]);
    }
    return ['db' => $id, 'fix' => row_by_title($id, 'SMOKE Fix the login'), 'build' => row_by_title($id, 'SMOKE Build the board'), 'ship' => row_by_title($id, 'SMOKE Ship it'), 'view' => views_of($id)[0]['id']];
}
/** A view through the handler as a person: the new view's id (or ''). */
function mkview(string $jar, string $db, string $name, array $f = []): string
{
    [, $b] = act($jar, '/databases/views/save.php', ['database' => $db, 'name' => $name] + $f);
    return (string) ($b['record_id'] ?? '');
}

/** The world the browser proof drives: a lab database with one view of each layout, and the epics' Launch row. Returns the ids as an array (run.sh hands it to browser.mjs as JSON). */
function browser_world(): array
{
    $w = databases_world();
    $lab = lab_database('Browser lab' . (getenv('LAB_SUFFIX') ?: ''));
    $marco = as_member(27);
    $have = array_column(views_of($lab['db']), 'id', 'name');
    $mk = fn (string $n, array $f) => $have[$n] ?? mkview($marco, $lab['db'], $n, $f);
    $views = ['table' => $lab['view'], 'board' => $mk('Board', ['layout' => 'board', 'group_by' => 'status']), 'gallery' => $mk('Gallery', ['layout' => 'gallery']), 'list' => $mk('List', ['layout' => 'list']),
              'calendar' => $mk('Calendar', ['layout' => 'calendar', 'calendar_by' => 'due']), 'timeline' => $mk('Timeline', ['layout' => 'timeline', 'timeline_start' => 'due', 'timeline_end' => 'due'])];
    return $lab + ['views' => $views, 'launch' => $w['launch'], 'epics' => $w['epics']];
}
