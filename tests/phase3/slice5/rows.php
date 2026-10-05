<?php
/** Proof — rows (spec "Proof", 2): a row by the form with every type, the refusals in words, the title, a cell edit that re-renders the row only, the log, computed values ignored, the panel, trash and restore. */
require __DIR__ . '/lib.php';
$w = databases_world();
$marco = as_member(27); $priya = as_member(26); $dana = as_member(30); $ann = as_member(29);
$tasks = $w['tasks'];
/** A browser's HTMX request (HTML, not JSON): [code, body, headers]. */
function htmx_post(string $jar, string $path, array $form): array
{
    $t = csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $r = req('POST', $path, ['jar' => $jar, 'headers' => ['HX-Request: true', 'Accept: text/html'], 'form' => $form + ['csrf_token' => $t]]);
    return [$r['code'], $r['body'], $r['headers'], $r];
}
function plain_post(string $jar, string $path, array $form): array
{
    $t = csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $r = req('POST', $path, ['jar' => $jar, 'headers' => ['Accept: text/html'], 'form' => $form + ['csrf_token' => $t]]);
    return [$r['code'], $r['body'], $r['location']];
}
$keys = db_keys($tasks);

echo "1. A row by the form, with every kind of value\n";
$since = last_activity_id(); $note0 = last_note_id();
$seq = (int) one('SELECT last_value FROM unique_id_sequences WHERE database_id = CAST(:d AS uuid)', ['d' => $tasks]);
[$c, $b] = act($marco, '/databases/rows/save.php', ['database' => $tasks, 'title' => 'SMOKE Form row', 'p' => ['notes' => 'Some notes', 'points' => '5', 'kind' => 'Bug', 'tags' => ['ui', 'db'], 'status' => 'Doing', 'due' => ['start' => '2026-11-02', 'end' => '2026-11-04'],
    'owner' => ['26', ''], 'urgent' => '1', 'link' => 'https://example.com/row', 'mail' => 'who@example.com', 'phone' => '+1 555 0100']]);
$row = (string) ($b['record_id'] ?? '');
ok($c === 200 && is_uuid($row) && str_contains((string) $b['location'], '/databases/' . $tasks . '/rows/' . $row), 'row_create from the form fields: 200, a UUID record_id, the location is the row');
$p = row_props($row);
ok(row_title($row) === 'SMOKE Form row' && $p['points'] === 5 && $p['kind']['name'] === 'Bug' && array_column($p['tags'], 'name') === ['ui', 'db'] && $p['status']['name'] === 'Doing' && $p['status']['color'] === 'blue'
    && $p['due'] == ['start' => '2026-11-02', 'end' => '2026-11-04'] && $p['owner'] === [26] && $p['urgent'] === true && $p['link'] === 'https://example.com/row' && $p['mail'] === 'who@example.com' && $p['phone'] === '+1 555 0100', 'each value has its type: a number, a select by name, a list, a status with its colour, a date with an end, people by id, a checkbox, a link, an email, a phone');
ok(display_plain($p['notes']) === 'Some notes', 'the text property is rich text');
ok($p['ref']['prefix'] === 'TSK' && $p['ref']['number'] === $seq + 1 && ($seq !== 3 || $p['ref']['number'] === 4), 'the unique id is numbered by the database: TSK-' . $p['ref']['number'] . ' (TSK-4 on a fresh world)');
$n = notes(26, 'mention', $note0);
ok(count($n) === 1 && str_contains($n[0]['title'], 'SMOKE Marco assigned you SMOKE Form row in SMOKE Tasks'), 'Priya is told: "' . ($n[0]['title'] ?? '') . '" (kind mention)');
$l = activity('row.create', $since);
ok(count($l) === 1 && $l[0]['entity_uuid'] === $row && (int) $l[0]['space_id'] === $w['product'] && !str_contains($l[0]['after'], 'Some notes') && !str_contains($l[0]['after'], 'example.com'), 'row.create: entity_uuid and space_id; the keys, never the values');
[$c, $b] = act($marco, '/databases/rows/save.php', ['database' => $tasks, 'properties' => json_encode(['Points' => 1, 'Owner' => ['SMOKE Priya', 'SMOKE Marco']])]);
$nameless = (string) ($b['record_id'] ?? '');
ok($c === 200 && row_props($nameless)['owner'] === [26, 27] && row_title($nameless) === '', 'row_create with `properties` keyed by display names and people by name; a row may be untitled');
[$c, $b] = act($marco, '/databases/rows/save.php', ['database' => $tasks, 'title' => 'SMOKE With body', 'markdown' => "A first line\n\n- one\n- two"]);
$wb = (string) ($b['record_id'] ?? '');
ok($c === 200 && (int) one("SELECT count(*) FROM blocks WHERE page_id = CAST(:p AS uuid) AND type = 'bulleted_list_item'", ['p' => $wb]) === 2, 'markdown becomes the row\'s body (slice 3\'s converter)');
$tplRow = (string) one("INSERT INTO pages (space_id, parent_page_id, parent_database_id, kind, title, is_template, owner_member_id, created_by) VALUES (:s, CAST(:d AS uuid), CAST(:d AS uuid), 'page', sp_rich_text('SMOKE Row template'), true, 27, 27) RETURNING id::text", ['s' => $w['product'], 'd' => $tasks]);
mkblock($tplRow, 'paragraph', ['rich_text' => rt('Template body')]);
[$c, $b] = act($marco, '/databases/rows/save.php', ['database' => $tasks, 'title' => 'SMOKE From row template', 'template' => $tplRow]);
ok($c === 200 && (int) one("SELECT count(*) FROM blocks WHERE page_id = CAST(:p AS uuid) AND plain_text = 'Template body'", ['p' => (string) $b['record_id']]) === 1, 'a row from a row template carries its blocks');
ok(!in_array('SMOKE Row template', titles(rows_as(27, $tasks)), true), 'and the template is never one of the rows');

echo "2. What is refused, in words\n";
[$c, $b] = act($marco, '/databases/rows/save.php', ['database' => $tasks, 'title' => 'SMOKE Bad', 'p' => ['points' => 'five']]);
ok($c === 422 && (fields($b)['p[points]'] ?? '') === 'Points takes a number.', 'a bad number: a field error on the field — ' . (fields($b)['p[points]'] ?? msg($b)));
[$c, $b] = act($marco, '/databases/rows/save.php', ['database' => $tasks, 'title' => 'SMOKE Bad', 'p' => ['kind' => 'Chore']]);
ok($c === 422 && str_contains(fields($b)['p[kind]'] ?? '', 'no option "Chore"') && str_contains(fields($b)['p[kind]'], 'Bug, Feature'), 'an unknown option: ' . (fields($b)['p[kind]'] ?? msg($b)));
[$c, $b] = act($marco, '/databases/rows/save.php', ['database' => $tasks, 'title' => 'SMOKE Bad', 'p' => ['due' => ['start' => 'soon']]]);
ok($c === 422 && str_contains(fields($b)['p[due]'] ?? '', 'date'), 'a bad date: ' . (fields($b)['p[due]'] ?? ''));
[$c, $b] = act($marco, '/databases/rows/save.php', ['database' => $tasks, 'title' => 'SMOKE Bad', 'p' => ['due' => ['start' => '2026-05-02', 'end' => '2026-05-01']]]);
ok($c === 422 && str_contains(fields($b)['p[due]'] ?? '', 'ends before'), 'an end before the start: ' . (fields($b)['p[due]'] ?? ''));
[$c, $b] = act($marco, '/databases/rows/save.php', ['database' => $tasks, 'title' => 'SMOKE Bad', 'p' => ['mail' => 'nope', 'link' => 'ftp://x']]);
ok($c === 422 && isset(fields($b)['p[mail]']) && isset(fields($b)['p[link]']), 'a bad email and a bad link: both named at once');
[$c, $b] = act($marco, '/databases/rows/save.php', ['database' => $tasks, 'title' => 'SMOKE Bad', 'p' => ['owner' => ['Nobody Atall']]]);
ok($c === 422 && str_contains(fields($b)['p[owner]'] ?? '', 'nobody is called'), 'a person nobody is called: ' . (fields($b)['p[owner]'] ?? ''));
[$c, $b] = act($marco, '/databases/rows/save.php', ['database' => $tasks, 'title' => 'SMOKE Bad', 'properties' => json_encode(['Nope' => 1])]);
ok($c === 422 && isset(fields($b)['p[Nope]']), 'a property the database does not have: refused');
ok(row_by_title($tasks, 'SMOKE Bad') === null, 'none of them made a row');
[$c, $b] = act($dana, '/databases/rows/save.php', ['database' => $tasks, 'title' => 'SMOKE Dana row']);
ok($c === 200, 'Dana is a member of Product (edit): she may add a row');
[$c, $b] = act($ann, '/databases/rows/save.php', ['database' => $tasks, 'title' => 'SMOKE Ann row']);
ok($c === 404, 'Ann (nothing shared): the database does not exist for her, 404');

echo "3. Change a row\n";
$since = last_activity_id();
[$c, $b] = act($priya, '/databases/rows/save.php', ['row' => $row, 'title' => 'SMOKE Renamed row']);
ok($c === 200 && row_title($row) === 'SMOKE Renamed row' && display_plain(row_props($row)['title']) === 'SMOKE Renamed row' && row_props($row)['points'] === 5, 'the title cell changes the page title (the guard keeps the two in step); the other values stay');
[$c, $b] = act($priya, '/databases/rows/save.php', ['row' => $row, 'p' => ['points' => '9']]);
$l = activity('row.update', $since);
ok($c === 200 && row_props($row)['points'] === 9 && count($l) === 2 && json_decode($l[1]['after'], true)['keys'] === ['points'] && !preg_match('/points"\s*:\s*9/', $l[1]['after']) && $l[1]['entity_uuid'] === $row, 'one property saved: row.update logs the key changed — never the value');
$since = last_activity_id();
[$c, $b] = act($priya, '/databases/rows/save.php', ['row' => $row, 'p' => ['points' => '9']]);
ok($c === 200 && activity('row.update', $since) === [], 'saving the same value logs nothing');
$refBefore = row_props($row)['ref'];
[$c, $b] = act($priya, '/databases/rows/save.php', ['row' => $row, 'p' => ['ref' => ['number' => 99, 'prefix' => 'X'], 'created' => '2001-01-01', 'creator' => '1', 'edited' => '2001-01-01', 'editor' => '1'], 'properties' => json_encode(['Points' => 10])]);
ok($c === 200 && row_props($row)['ref'] === $refBefore && !isset(row_props($row)['creator']) && !isset(row_props($row)['created']) && row_props($row)['points'] === 10, 'a computed property in the POST is ignored, not refused; the real one beside it is saved');
[$c, $b] = act($priya, '/databases/rows/save.php', ['row' => $row, 'p' => ['tags' => [''], 'owner' => [''], 'due' => ['start' => '', 'end' => ''], 'kind' => '', 'urgent' => '0']]);
$p = row_props($row);
ok($c === 200 && $p['tags'] === [] && $p['owner'] === [] && $p['due'] === null && $p['kind'] === null && $p['urgent'] === false, 'empty values clear a list, a person, a date, a select and a checkbox');
[$c, $b] = act($dana, '/databases/rows/save.php', ['row' => $row, 'p' => ['points' => '1']]);
ok($c === 200, 'Dana (edit through the space) saves a cell');
[$c, $html, $hdr] = htmx_post($priya, '/databases/rows/save.php', ['row' => $row, 'render' => 'row', 'view' => views_of($tasks)[0]['id'], 'p' => ['points' => '12']]);
ok($c === 200 && str_contains($html, 'id="row-row-' . $row . '"') && substr_count($html, '<tr ') === 1 && !str_contains($html, '<html') && row_props($row)['points'] === 12 && str_contains($hdr, 'rowChanged'), 'an HTMX cell edit answers the ROW alone (one <tr id="row-row-…">, no shell) and triggers rowChanged');
ok(str_contains($html, 'id="cell-' . $row . '-points"') && str_contains($html, 'value="12"'), 'with the cell showing the new value');
[$c, $body, $loc] = plain_post($priya, '/databases/rows/save.php', ['row' => $row, 'p' => ['points' => '13'], 'return_to' => '/databases/' . $tasks . '/rows/' . $row]);
ok($c === 302 && str_starts_with($loc, '/databases/' . $tasks . '/rows/' . $row) && row_props($row)['points'] === 13, 'with JavaScript off the cell\'s form posts and lands on the row page');

echo "4. The row page and its panel\n";
$r = page($priya, '/databases/' . $tasks . '/rows/' . $row);
$h = $r['body'];
preg_match_all('/class="sp-prop-value" id="property-field-([A-Za-z0-9_-]+)"/', $h, $m);
$kids = array_map(fn ($k) => trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $k), '-'), $keys);
ok($r['code'] === 200 && $m[1] === $kids && str_contains($h, 'id="properties-panel"'), 'the panel lists every property in schema order (' . count($m[1]) . ')');
ok(str_contains($h, 'id="page-title"') && str_contains($h, 'SMOKE Renamed row'), 'and the page is slice 2\'s: the title, the body, the comments bar');
$seg = function (string $key) use ($h): string { $parts = explode('class="sp-prop-line"', $h); foreach ($parts as $part) { if (str_contains($part, 'id="property-field-' . $key . '"')) { return $part; } } return ''; };
ok(str_contains($seg('ref'), 'TSK-' . row_props($row)['ref']['number']) && !str_contains($seg('ref'), '<input') && !str_contains($seg('created'), '<input') && !str_contains($seg('creator'), '<input'), 'a unique id and the created/edited ones are shown, never an input');
$r2 = page($marco, '/databases/' . $w['epics'] . '/rows/' . $w['launch']);
$h2 = $r2['body'];
preg_match('/id="property-field-points">(.*?)<\/div>/s', $h2, $mm);
ok($r2['code'] === 200 && isset($mm[1]) && !str_contains($mm[1], '<input') && !str_contains($mm[1], '<form'), 'a rollup (Points) is read-only on its row page');
ok(str_contains($h2, 'rows/relation.php?row=' . $w['launch']), 'a relation shows the picker\'s link');
$r3 = page($dana, '/databases/' . $tasks . '/rows/' . $row);
[$c, $d] = screen($marco, '/databases/' . $tasks . '/rows/' . $row);
ok($c === 200 && $d['row']['row_id'] === $row && $d['row']['values']['points'] === '13' && count($d['properties']) === count($keys) && isset($d['blocks']) && $d['database']['database_id'] === $tasks, 'row-view answers JSON: the row\'s values (also as text), the schema, the body');
$r = req('GET', '/pages/' . $row, ['jar' => $marco]);
ok($r['code'] === 302 && str_starts_with($r['location'], '/databases/' . $tasks . '/rows/' . $row), 'a row asked for as a page goes to its database page');
$r = req('GET', '/pages/' . $tasks, ['jar' => $marco]);
ok($r['code'] === 302 && str_starts_with($r['location'], '/databases/' . $tasks), 'and a database asked for as a page goes to the database');
[$c, $d] = screen($marco, '/pages/' . $row);
ok($c === 200 && $d['page']['is_row'] === true, 'while an agent asking for the page as JSON still gets it');
$r = page($marco, '/databases/' . $w['epics'] . '/rows/' . $row);
ok($r['code'] === 404, 'a row under the wrong database: 404');

echo "5. A file on a row\n";
$f = proof_file('row.png', png_bytes());
[$c, $b] = upload($priya, '/files/upload.php', ['row' => $row, 'property' => 'Files'], $f, 'row.png', 'image/png');
$aid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $aid > 0 && row_props($row)['files'] === [$aid] && one('SELECT record_type FROM attachments WHERE id = :a', ['a' => $aid]) === 'row_files', 'file_upload on a row: an attachment of kind row_files, its id in the files property');
$r = page($marco, '/databases/' . $tasks . '/rows/' . $row);
ok(str_contains($r['body'], '/files/' . $aid) && str_contains($r['body'], 'id="property-field-files-upload"'), 'the panel lists it with an upload form');
$r = req('GET', '/files/' . $aid, ['jar' => $marco]);
ok($r['code'] === 200 && str_contains($r['headers'], 'image/png'), 'served through the gated door to someone who sees the row');
$r = req('GET', '/files/' . $aid, ['jar' => $ann]);
ok($r['code'] === 404 || $r['code'] === 403, 'and not to Ann');
[$c, $b] = upload($priya, '/files/upload.php', ['row' => $row, 'property' => 'Notes'], $f, 'row.png', 'image/png');
ok($c === 422, 'a file on a property that is not files: refused in words');
[$c, $b] = act($priya, '/databases/rows/save.php', ['row' => $row, 'p' => ['files' => ['']]]);
ok($c === 200 && row_props($row)['files'] === [], 'removing it: the property is empty again');

echo "6. Trash and restore\n";
$since = last_activity_id();
[$c, $b] = act($priya, '/databases/rows/delete.php', ['row' => $nameless]);
ok($c === 200 && one('SELECT archived_at IS NOT NULL FROM pages WHERE id = CAST(:r AS uuid)', ['r' => $nameless]) && !in_array($nameless, array_column(rows_as(27, $tasks), 'row_id'), true), 'row_delete: the row goes to the trash and out of the view');
$l = activity('row.delete', $since);
ok(count($l) === 1 && $l[0]['entity_uuid'] === $nameless, 'row.delete is logged');
[$c, $b] = act($priya, '/pages/restore.php', ['page' => $nameless]);
ok($c === 403, 'restoring needs full on the page (slice 2\'s rule): Priya (edit) is refused in words');
[$c, $b] = act($marco, '/pages/restore.php', ['page' => $nameless]);
ok($c === 200 && in_array($nameless, array_column(rows_as(27, $tasks), 'row_id'), true), 'page_restore brings it back');
[$c, $b] = act($priya, '/databases/rows/save.php', ['row' => '00000000-0000-0000-0000-000000000000', 'title' => 'x']);
ok($c === 404, 'a row that is not there: 404');
finish();

function display_plain(mixed $rt): string { return is_array($rt) ? trim(implode('', array_map(fn ($r) => $r['plain_text'] ?? '', $rt))) : (string) $rt; }
