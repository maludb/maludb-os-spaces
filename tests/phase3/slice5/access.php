<?php
/** Proof — who sees (spec "Proof", 5): the permission tree decides; Dana (comment) reads and cannot edit a cell; Ann (nothing shared) 404; the admin sees all; the read views answer what the screen shows. */
require __DIR__ . '/lib.php';
$w = databases_world();
$marco = as_member(27); $priya = as_member(26); $dana = as_member(30); $ann = as_member(29); $owner = as_member(1); $bea = as_member(28);
$lab = lab_database('Access lab');
[$db, $fix, $build, $ship, $view] = [$lab['db'], $lab['fix'], $lab['build'], $lab['ship'], $lab['view']];
act($marco, '/pages/restrict.php', ['page' => $db]);
act($marco, '/pages/share.php', ['page' => $db, 'member' => 26, 'level' => 'edit']);
act($marco, '/pages/share.php', ['page' => $db, 'member' => 30, 'level' => 'comment']);
$ids = fn (string $html, string $prefix): array => (preg_match_all('/id="' . preg_quote($prefix, '/') . '([0-9a-f-]{36})"/', $html, $m) ? $m[1] : []);

echo "1. Dana (comment) reads\n";
$r = page($dana, '/databases/' . $db);
$h = $r['body'];
ok($r['code'] === 200 && count($ids($h, 'row-row-')) === 3, 'Dana reads the rows: the table, three rows');
ok(!str_contains($h, 'id="database-new-row"') && !str_contains($h, 'hx-post="/databases/rows/save.php"') && !str_contains($h, 'id="view-add-link"') && !str_contains($h, 'id="database-toolbar-filter"') && !str_contains($h, 'id="database-toolbar-views"'), 'with no New row, no cell forms, no view or filter buttons');
ok(str_contains($h, 'id="database-toolbar-schema"'), 'but the schema is hers to read');
preg_match('/id="cell-' . $fix . '-points"[^>]*>(.*?)<\/td>/s', $h, $m);
ok(isset($m[1]) && !str_contains($m[1], '<input') && str_contains($m[1], '3'), 'a cell is plain text for her');
[$c, $b] = act($dana, '/databases/rows/save.php', ['row' => $fix, 'p' => ['points' => '99']]);
ok($c === 403 && msg($b) === 'You may not edit the rows of this database.' && row_props($fix)['points'] === 3, 'a cell edit: 403 in words — "' . msg($b) . '" — and nothing changed');
[$c, $b] = act($dana, '/databases/rows/save.php', ['database' => $db, 'title' => 'SMOKE Dana tries']);
ok($c === 403 && row_by_title($db, 'SMOKE Dana tries') === null, 'a new row: 403');
[$c, $b] = act($dana, '/databases/rows/delete.php', ['row' => $fix]);
ok($c === 403, 'a row delete: 403');
[$c, $b] = act($dana, '/databases/rows/relation.php', ['row' => $fix, 'property' => 'Owner', 'targets' => []]);
ok($c === 403, 'a relation: 403');
[$c, $b] = act($dana, '/databases/views/save.php', ['database' => $db, 'name' => 'Dana\'s view']);
ok($c === 403, 'a view: 403');
[$c, $b] = act($dana, '/databases/views/save.php', ['view' => $view, 'name' => 'Hijack']);
ok($c === 403 && view_row($view)['name'] === 'Table', 'a change to a view: 403');
[$c, $b] = act($dana, '/databases/views/reorder.php', ['view' => $view, 'after' => '']);
ok($c === 403, 'a reorder: 403');
$r = page($dana, '/databases/' . $db . '/rows/' . $fix);
ok($r['code'] === 200 && !str_contains($r['body'], 'hx-post="/databases/rows/save.php"') && str_contains($r['body'], 'id="properties-panel"') && str_contains($r['body'], 'id="page-comments-btn"'), 'Dana\'s row page: the panel read-only; the comments button (comment level) is there');
$r = page($dana, '/databases/' . $db . '/schema');
ok($r['code'] === 200 && !str_contains($r['body'], 'id="property-add-form"'), 'the schema without forms');
$r = page($dana, '/databases/rows/relation.php?row=' . $fix . '&property=Owner');
ok($r['code'] === 403 || $r['code'] === 422, 'the relation picker is for editors (' . $r['code'] . ')');
[$c, $d] = screen($dana, '/databases/' . $db);
ok($c === 200 && $d['may'] === ['edit' => false, 'schema' => false, 'full' => false, 'comment' => true] && $d['database']['my_level'] === 'comment', 'JSON says what she may: comment only (my_level comment)');

echo "2. Priya (edit) and Marco (full)\n";
[$c, $b] = act($priya, '/databases/rows/save.php', ['row' => $fix, 'p' => ['points' => '4']]);
ok($c === 200 && row_props($fix)['points'] === 4, 'Priya (edit) saves a cell');
[$c, $b] = act($priya, '/databases/views/save.php', ['database' => $db, 'name' => 'Priya view']);
ok($c === 200, 'and makes a view');
[$c, $b] = act($priya, '/databases/delete.php', ['database' => $db]);
ok($c === 403, 'but not a delete (full)');
act($priya, '/databases/rows/save.php', ['row' => $fix, 'p' => ['points' => '3']]);

echo "3. Ann and Bea (nothing shared)\n";
foreach ([[$ann, 'Ann'], [$bea, 'Bea']] as [$j, $who]) {
    $r = page($j, '/databases/' . $db);
    ok($r['code'] === 404, "$who: the database page is 404");
    $r = page($j, '/databases/' . $db . '/rows/' . $fix);
    ok($r['code'] === 404, "$who: a row page is 404");
    $r = page($j, '/databases/' . $db . '/schema');
    ok($r['code'] === 404, "$who: the schema is 404");
    [$c, $b] = act($j, '/databases/rows/save.php', ['row' => $fix, 'p' => ['points' => '1']]);
    ok($c === 404, "$who: a cell edit is 404");
    [$c, $b] = act($j, '/databases/delete.php', ['database' => $db]);
    ok($c === 404, "$who: a delete is 404");
}
[$c, $d] = screen($ann, '/databases/');
ok($c === 200 && !in_array($db, array_column($d['databases'], 'database_id'), true), 'the list as Ann holds none of it');
[$c, $d] = screen($bea, '/databases/');
ok($c === 200 && !in_array($w['tasks'], array_column($d['databases'], 'database_id'), true), 'Bea (General only) sees none of Product\'s databases');
$h = page($ann, '/pages/' . $w['handbook'])['body'];
ok(!str_contains($h, 'SMOKE Fix the login'), 'nor through a page that links one');

echo "4. The admin\n";
$r = page($owner, '/databases/' . $db);
ok($r['code'] === 200 && str_contains($r['body'], 'id="database-new-row"') && str_contains($r['body'], 'id="view-add-link"'), 'the Spaces admin sees the restricted database and may change everything');
[$c, $b] = act($owner, '/databases/rows/save.php', ['row' => $build, 'p' => ['points' => '8']]);
ok($c === 200, 'and edit a cell');

echo "5. The read views answer what the screens show\n";
$list = function (string $jar): array { [$c, $d] = screen($jar, '/databases/'); return $d['databases'] ?? []; };
foreach ([[27, $marco, 'Marco'], [26, $priya, 'Priya'], [30, $dana, 'Dana'], [29, $ann, 'Ann'], [1, $owner, 'the admin'], [28, $bea, 'Bea']] as [$mid, $jar, $who]) {
    as_viewer($mid);
    $viewDbs = array_column(q('SELECT database_id::text AS d FROM mcp_databases WHERE archived_at IS NULL AND NOT EXISTS (SELECT 1 FROM pages p WHERE p.id = mcp_databases.database_id AND p.is_template) ORDER BY 1'), 'd');
    $screenDbs = array_column($list($jar), 'database_id'); sort($screenDbs);
    ok($viewDbs === $screenDbs || array_diff($screenDbs, $viewDbs) === [] && count($screenDbs) === count($viewDbs), "mcp_databases and the list screen agree for $who (" . count($viewDbs) . ' databases)');
    $vw = array_column(q('SELECT view_id::text AS v FROM mcp_database_views WHERE database_id = CAST(:d AS uuid) ORDER BY 1', ['d' => $db]), 'v');
    [$c, $d] = screen($jar, '/databases/' . $db);
    $sv = $c === 200 ? array_column($d['views'], 'view_id') : [];
    sort($sv);
    ok($vw === $sv, "mcp_database_views and the screen agree for $who (" . count($vw) . ' views)');
}
as_viewer(30);
ok((int) one('SELECT count(*) FROM mcp_row_relations') >= 0 && (string) one("SELECT my_level FROM mcp_pages WHERE page_id = CAST(:d AS uuid)", ['d' => $db]) === 'comment', 'the same permission tree under the views: Dana\'s level on the database is comment');
as_viewer(29);
ok((int) one('SELECT count(*) FROM mcp_database_views WHERE database_id = CAST(:d AS uuid)', ['d' => $db]) === 0 && (int) one('SELECT count(*) FROM mcp_databases WHERE database_id = CAST(:d AS uuid)', ['d' => $db]) === 0, 'Ann reads none of it through the views');

echo "6. An agent\n";
act($marco, '/spaces/members/add.php', ['space' => $w['product'], 'member' => 40]);
kernel_state(function ($s) { $s['facts']['711'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 711, 'request_id' => 'req-711', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$rt = as_agent(run_token(40, 711));
[$c, $d] = [0, []];
$r = req('GET', '/databases/' . $db, ['headers' => $rt]);
ok($r['code'] === 403 && str_contains($r['body'], 'an agent uses the tools'), 'a screen is for people: an agent asking for one is told to use the tools (403)');
[$c, $b] = act_token('/databases/rows/save.php', ['row' => $fix, 'p' => ['points' => '5']], $rt);
ok($c === 404, 'Seamus (a member of Product, not named on the restricted database): the row does not exist for him, 404 — the same functions, no special case');
act($marco, '/pages/share.php', ['page' => $db, 'member' => 40, 'level' => 'edit_content']);
[$c, $b] = act_token('/databases/rows/save.php', ['row' => $fix, 'p' => ['points' => '5']], $rt);
ok($c === 200 && row_props($fix)['points'] === 5, 'shared at edit_content, the agent edits a cell');
[$c, $b] = act_token('/databases/properties/save.php', ['database' => $db, 'key' => 'Extra', 'type' => 'number'], $rt);
ok($c === 403, 'but not the schema: 403 — edit_content is rows, never the schema');
[$c, $b] = act_token('/databases/views/save.php', ['database' => $db, 'name' => 'Agent view'], $rt);
ok($c === 403, 'nor a view');
act($priya, '/databases/rows/save.php', ['row' => $fix, 'p' => ['points' => '3']]);
act($marco, '/pages/unshare.php', ['page' => $db, 'member' => 40]);      // a rerun starts the same
finish();
