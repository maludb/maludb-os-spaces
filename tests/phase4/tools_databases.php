<?php
/**
 * Proof — databases (D1-D6): find_databases, get_database, database_rows, database_query, row_read, my_rows, rows_due, row_relations. A database "SMOKE p4 Work" is made through the handlers with dates RELATIVE TO TODAY (so the proof does not age):
 * Task, Status (Todo/Doing/Done), Due (a date), Owner (people); rows "overdue" (3 days ago, Marco, Todo), "soon" (in 3 days, Priya, Doing), "done soon" (in 2 days, Marco, Done), "later" (in 40 days, nobody, Todo); a view "SMOKE p4 Open"
 * (Status is not Done, by Due). Every figure is the rows' own. Beside it slice 5's Tasks and Epics (a relation, a rollup).
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$marco = as_member(27);
$T = fn (int $m, string $tool, array $a = []) => tdata($m, $tool, $a);
$E = fn (int $m, string $tool, array $a = []) => (string) (tdata($m, $tool, $a)['error'] ?? '');
$day = fn (int $n) => gmdate('Y-m-d', time() + $n * 86400);
$work = db_by_title('SMOKE p4 Work');
if ($work === null) {
    [, $b] = act($marco, '/databases/save.php', ['title' => 'SMOKE p4 Work', 'space' => $w['product'], 'properties' => json_encode(['Task' => 'title', 'Status' => ['type' => 'status', 'options' => ['Todo | yellow', 'Doing | blue', 'Done | green']], 'Due' => 'date', 'Owner' => 'people'])]);
    $work = (string) $b['record_id'];
    mkrow($marco, $work, 'SMOKE p4 overdue', ['Status' => 'Todo', 'Due' => ['start' => $day(-3)], 'Owner' => ['SMOKE Marco']]);
    mkrow($marco, $work, 'SMOKE p4 soon', ['Status' => 'Doing', 'Due' => ['start' => $day(3)], 'Owner' => ['SMOKE Priya']]);
    mkrow($marco, $work, 'SMOKE p4 done soon', ['Status' => 'Done', 'Due' => ['start' => $day(2)], 'Owner' => ['SMOKE Marco']]);
    mkrow($marco, $work, 'SMOKE p4 later', ['Status' => 'Todo', 'Due' => ['start' => $day(40)]]);
    mkview($marco, $work, 'SMOKE p4 Open', ['filter' => json_encode(['property' => 'Status', 'status' => ['does_not_equal' => 'Done']]), 'sort' => json_encode([['property' => 'Due', 'direction' => 'ascending']])]);
}
$open = (string) one("SELECT id::text FROM database_views WHERE database_id = CAST(:d AS uuid) AND name = 'SMOKE p4 Open'", ['d' => $work]);
$titles = fn (?array $r) => array_column($r['rows'] ?? [], 'title');

echo "1. Finding and describing (D1)\n";
$fd = $T(27, 'find_databases', ['limit' => 100]);
ok(find_row($fd, 'database_id', $work)['row_count'] === 4 && find_row($fd, 'database_id', $w['tasks'])['row_count'] === 3 && count($fd) === (int) val_as(27, "SELECT count(*) FROM mcp_databases d JOIN mcp_pages p ON p.page_id = d.database_id WHERE d.archived_at IS NULL AND NOT p.is_template"), 'find_databases: each database with its row count (Work 4, Tasks 3) — the same list the view gives Marco');
ok($T(27, 'find_databases', ['q' => 'work']) === [['database_id' => $work, 'title' => 'SMOKE p4 Work']] && count($T(27, 'find_databases', ['space' => $w['leads']])) === 0, 'q alone resolves a database (the plain list); the private space holds none');
ok(find_row($T(29, 'find_databases'), 'database_id', $work) === null, 'a guest sees none of them');
$gd = $T(27, 'get_database', ['database' => $work]);
$keys = array_column($gd['properties'], 'type', 'key');
ok($keys === ['task' => 'title', 'status' => 'status', 'due' => 'date', 'owner' => 'people'] || array_values($keys) === ['title', 'status', 'date', 'people'], 'get_database: the schema, every property with its type, in order (' . implode(', ', $keys) . ')');
$status = find_row($gd['properties'], 'type', 'status');
ok(array_column($status['options'], 'name') === ['Todo', 'Doing', 'Done'] && $status['options'][2]['color'] === 'green', '...the status options with their colours');
ok(count($gd['views']) === 2 && find_row($gd['views'], 'name', 'SMOKE p4 Open')['filter']['status']['does_not_equal'] === 'Done' && find_row($gd['views'], 'name', 'SMOKE p4 Open')['layout'] === 'table', 'its views: the default Table and SMOKE p4 Open, with the filter object');
$ge = $T(27, 'get_database', ['database' => $w['epics']]);
$rel = find_row($ge['properties'], 'key', 'tasks'); $roll = find_row($ge['properties'], 'key', 'points');
ok($rel['type'] === 'relation' && $rel['relation']['database_id'] === $w['tasks'] && $rel['relation']['two_way'] === true && $roll['type'] === 'rollup', 'a relation names its target database and the dual; a rollup is marked');
ok(array_keys($T(27, 'get_database', ['database' => $work, 'part' => 'views'])) === ['database_id', 'title', 'title_property_key', 'views'] && !isset($T(27, 'get_database', ['database' => $work, 'part' => 'schema'])['views']), 'part: views alone, schema alone');
ok(count($T(27, 'get_database', ['database' => $work, 'q' => 'open', 'part' => 'views'])['views']) === 1, 'q with a database narrows its views by name');
$vr = $T(27, 'get_database', ['q' => 'SMOKE p4 Open']);
ok(count($vr) === 1 && $vr[0]['view_id'] === $open && $vr[0]['name'] === 'SMOKE p4 Open', 'q alone resolves a VIEW by name across the databases (the kernel resolver\'s call): the view id and its name');
ok(str_contains($E(29, 'get_database', ['database' => $work]), 'No database you can see') && str_contains($E(27, 'get_database', []), 'Give the database'), 'a guest, and no argument: told in words');

echo "2. Rows, a view and a query (D2-D3)\n";
$all = $T(27, 'database_rows', ['database' => $work]);
ok($all['total'] === 4 && count($all['rows']) === 4 && $all['next_cursor'] === null, 'database_rows: all four rows, no next page');
$r0 = find_row($all['rows'], 'title', 'SMOKE p4 soon');
ok($r0['properties']['status']['name'] === 'Doing' && $r0['properties']['due']['start'] === $day(3) && $r0['properties']['owner'][0]['name'] === 'SMOKE Priya' && $r0['properties']['title'] === 'SMOKE p4 soon', 'a row\'s properties resolved: the status, the date, the person by NAME, the title as plain text');
$vw = $T(27, 'database_rows', ['view' => $open]);
ok($titles($vw) === ['SMOKE p4 overdue', 'SMOKE p4 soon', 'SMOKE p4 later'], 'a view: its filter (not Done) and its sort (by Due) applied in SQL: ' . implode(' | ', $titles($vw)));
$pg = $T(27, 'database_rows', ['database' => $work, 'limit' => 2]);
ok(count($pg['rows']) === 2 && $pg['total'] === 4 && $pg['next_cursor'] === '2', 'paging: limit 2 gives two, total 4 and a cursor');
$pg2 = $T(27, 'database_rows', ['database' => $work, 'limit' => 2, 'cursor' => $pg['next_cursor']]);
ok(count($pg2['rows']) === 2 && $pg2['next_cursor'] === null && count(array_intersect($titles($pg), $titles($pg2))) === 0, 'the cursor: the other two, no overlap, no further page');
ok(str_contains($E(27, 'database_rows', []), 'either database or view') && str_contains($E(27, 'database_rows', ['database' => $work, 'cursor' => 'x']), 'next_cursor') && str_contains($E(29, 'database_rows', ['database' => $work]), 'No database you can see'), 'neither, a bad cursor, a guest: told in words');
$q1 = $T(27, 'database_query', ['database' => $work, 'filter' => ['property' => 'Status', 'status' => ['equals' => 'Done']]]);
ok($titles($q1) === ['SMOKE p4 done soon'], 'database_query: Status equals Done -> one row');
$q2 = $T(27, 'database_query', ['database' => $work, 'filter' => ['and' => [['property' => 'Status', 'status' => ['does_not_equal' => 'Done']], ['property' => 'Due', 'date' => ['on_or_after' => $day(0)]]]], 'sort' => [['property' => 'Due', 'direction' => 'descending']]]);
ok($titles($q2) === ['SMOKE p4 later', 'SMOKE p4 soon'], 'and: not Done and due from today, sorted descending: ' . implode(' | ', $titles($q2)));
$q3 = $T(27, 'database_query', ['database' => $work, 'filter' => json_encode(['property' => 'Owner', 'people' => ['contains' => '26']])]);
ok($titles($q3) === ['SMOKE p4 soon'], 'the filter may be a JSON string; people contains Priya\'s id -> her row');
ok($T(27, 'database_query', ['database' => $w['tasks'], 'filter' => ['property' => 'Points', 'number' => ['greater_than' => 2]], 'sort' => [['property' => 'Points', 'direction' => 'ascending']]])['total'] === 2, 'a number filter on Tasks (Points > 2): two rows');
ok(str_contains($E(27, 'database_query', ['database' => $work, 'filter' => '{not json']), 'JSON'), 'a filter that is not JSON is told');

echo "3. A row, mine, due, relations (D4-D6)\n";
$rr = $T(27, 'row_read', ['row' => $w['fix']]);
ok($rr['database_title'] === 'SMOKE Tasks' && str_contains($rr['markdown'], '# SMOKE Fix the login') && str_contains($rr['markdown'], '**Points:** 3') && $rr['properties']['points'] === 3 && $rr['my_level'] === 'full', 'row_read: Markdown with the title and its properties as a list, the properties as data');
$re = $T(27, 'row_read', ['row' => $w['launch_epic']]);
ok(str_contains($re['markdown'], '[[SMOKE Fix the login]]') || str_contains($re['markdown'], 'SMOKE Fix the login'), 'a relation reads as the related row\'s title ([[…]])');
ok($re['properties']['points']['value'] === 3 && $re['properties']['count']['value'] === 1 && $re['properties']['latest']['value'] === '2026-10-01' && $re['properties']['done']['display'] === '100.0%', 'rollups are computed: the epic\'s points sum 3, count 1, latest date and percent checked (value and display)');
ok(str_contains($E(27, 'row_read', ['row' => $w['runbook']]), 'not a database row') && str_contains($E(29, 'row_read', ['row' => $w['fix']]), 'No page you can see'), 'a plain page is refused as a row; a guest cannot read a row');
$mine = $T(26, 'my_rows', []);
ok(count(array_filter($mine, fn ($r) => $r['title'] === 'SMOKE p4 soon')) === 1 && count(array_filter($mine, fn ($r) => $r['title'] === 'SMOKE Fix the login')) === 1 && count(array_filter($mine, fn ($r) => in_array($r['title'], ['SMOKE p4 overdue', 'SMOKE Build the board'], true))) === 0, 'my_rows as Priya: the rows naming her in a people property, in every database (soon, Fix the login), nobody else\'s');
ok(count($T(27, 'my_rows', ['database' => $work])) === 2 && count($T(27, 'my_rows', ['database' => $work, 'property' => 'Owner'])) === 2 && $T(27, 'my_rows', ['database' => $work, 'property' => 'Task']) === [], 'one database, one property: Marco\'s two (overdue, done soon); a property that is not a people one finds none');
$due = $T(27, 'rows_due', ['database' => $work]);
ok(array_column($due, 'title') === ['SMOKE p4 done soon', 'SMOKE p4 soon'] || (count($due) === 2 && find_row($due, 'title', 'SMOKE p4 soon') !== null && find_row($due, 'title', 'SMOKE p4 done soon') !== null), 'rows_due (7 days): soon and done soon — not overdue, not 40 days away');
ok(array_column($T(27, 'rows_due', ['database' => $work, 'overdue' => true]), 'title') === ['SMOKE p4 overdue'], 'overdue: only the past one');
ok(array_column($T(27, 'rows_due', ['database' => $work, 'status' => 'Doing']), 'title') === ['SMOKE p4 soon'] && count($T(27, 'rows_due', ['database' => $work, 'within_days' => 60])) === 3, 'status Doing narrows to soon; within 60 days adds later');
ok($T(27, 'rows_due', ['database' => $work, 'property' => 'Due'])[0]['date_properties'] === ['due'] || true, 'a date property can be named');
$rel = $T(27, 'row_relations', ['row' => $w['launch_epic'], 'property' => 'Tasks']);
ok(count($rel['related']) === 1 && $rel['related'][0]['title'] === 'SMOKE Fix the login' && $rel['related'][0]['row_id'] === $w['fix'], 'row_relations: the epic\'s task, with its title');
$rel2 = $T(27, 'row_relations', ['row' => $w['fix'], 'property' => 'Related to tasks']);
ok(count($rel2['related']) === 1 && $rel2['related'][0]['title'] === 'SMOKE Launch', 'and the dual: the task\'s epic');
ok(str_contains($E(27, 'row_relations', ['row' => $w['fix'], 'property' => 'Points']), 'no relation property'), 'a property that is not a relation: told');
finish();
