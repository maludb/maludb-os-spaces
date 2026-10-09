<?php
/** Every converted form renders its picker under the old name and id, with the chosen label on first render, and no <select> of records is left behind. */
require __DIR__ . '/lib.php';
$w = picker_world();
$marco = as_member(27); $priya = as_member(26); $bea = as_member(28); $owner = as_member(1);
$product = (int) $w['product']; $leads = (int) $w['leads'];

function screen_html(string $jar, string $path): string { $r = req('GET', $path, ['jar' => $jar]); return $r['code'] === 200 ? $r['body'] : "HTTP {$r['code']}"; }
/** A field: the hidden input keeps name and id; the label's `for` points at the opener. */
function expect_field(string $html, string $id, string $name, string $source, string $value, string $label, string $what, ?string $labelText = null): void
{
    $f = field_of($html, $id);
    ok($f !== null && $f[2] === $name && $f[0] === $value && $f[1] === $label, "$what: hidden input name=$name id=$id value=" . ($value === '' ? '(none)' : $value) . ", button reads \"$label\"" . ($f === null ? ' [missing]' : " [got name={$f[2]} value={$f[0]} label=\"{$f[1]}\"]"));
    ok(str_contains($html, 'data-picker-source="' . $source . '"') && str_contains($html, 'id="' . $id . '-open"'), "$what: source $source, opener $id-open");
    ok(!preg_match('/<select[^>]* id="' . preg_quote($id, '/') . '"/', $html), "$what: no <select> left under the id");
    ok(!str_contains($html, 'for="' . $id . '"'), "$what: no label points at the hidden input (it points at $id-open, or there is none)");
}
function params_of(string $html, string $id): array
{
    preg_match('/id="' . preg_quote($id, '/') . '-picker"[^>]*?data-picker-params="([^"]*)"/', $html, $m);
    return json_decode(html_entity_decode($m[1] ?? '{}'), true) ?: [];
}

echo "-- a space's members\n";
$h = screen_html($marco, "/spaces/$leads/members");
expect_field($h, 'member-add-field-member', 'member', 'member', '', '—', 'space members: add a person');
ok(params_of($h, 'member-add-field-member') === ['space' => $leads], 'space members: the picker is scoped to the space (param space)');
ok(str_contains($h, 'for="member-add-field-member-open"'), 'space members: the label points at the opener');
ok(str_contains($h, '<select name="department"'), 'space members: the department stays a select (a short configuration list)');

echo "-- a channel's members\n";
$h = screen_html($marco, "/channels/{$w['launch']}/members");
expect_field($h, 'channel-member-add-field', 'member', 'channel_member', '', 'Choose…', 'channel members: add a member');
expect_field($h, 'channel-guest-add-field', 'guest', 'channel_guest', '', 'Choose…', 'channel members: add a guest');
ok(params_of($h, 'channel-member-add-field') === ['channel' => (int) $w['launch']], 'channel members: scoped to the channel');
ok(str_contains($h, 'aria-required="true"'), 'channel members: required fields have no Clear button');

echo "-- sharing a page\n";
$h = screen_html($marco, "/pages/{$w['pricing']}/share");
expect_field($h, 'share-form-field-member', 'member', 'member', '', '—', 'share: a person or an agent');
expect_field($h, 'share-guest-field-guest', 'guest', 'guest', '', 'Choose a guest', 'share: a guest');
ok(params_of($h, 'share-form-field-member') === ['page' => $w['pricing']], 'share: scoped to the page (param page, a UUID)');

echo "-- a new page, and its first render with a preselection\n";
$h = screen_html($marco, '/pages/new');
expect_field($h, 'page-form-field-space', 'space', 'space_page', '', '—', 'new page: the space');
expect_field($h, 'page-form-field-parent', 'parent', 'page', '', '—', 'new page: the parent');
ok(params_of($h, 'page-form-field-parent') === ['editable' => 1], 'new page: the parent is an editable page');
$h = screen_html($marco, "/pages/new?space=$product&parent={$w['pricing']}");
expect_field($h, 'page-form-field-space', 'space', 'space_page', (string) $product, '🚀 SMOKE Product', 'new page ?space=: the chosen space keeps its label');
expect_field($h, 'page-form-field-parent', 'parent', 'page', $w['pricing'], 'SMOKE Pricing', 'new page ?parent=: the chosen page keeps its label');
$h = screen_html($marco, "/pages/new?parent={$w['notes']}");
expect_field($h, 'page-form-field-parent', 'parent', 'page', '', '—', 'new page ?parent=<a page Marco cannot see>: no choice, the placeholder (never an error)');
$r = req('GET', "/pages/new?space=$product", ['jar' => $marco, 'headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && field_of($r['body'], 'page-form-field-space')[1] === '🚀 SMOKE Product' && !str_contains($r['body'], '<html'), 'new page over HTMX (a swap): the picker comes with the partial');

echo "-- moving a page\n";
$h = screen_html($marco, "/pages/{$w['pricing']}/move");
expect_field($h, 'move-form-field-space', 'space', 'space_page', (string) $product, '🚀 SMOKE Product', 'move: the current space is chosen');
expect_field($h, 'move-form-field-parent', 'parent', 'page', '', '—', 'move: the new parent');
ok(params_of($h, 'move-form-field-parent') === ['editable' => 1, 'except' => $w['pricing']], 'move: the page itself is left out of the parents (param except)');

echo "-- a new channel\n";
$h = screen_html($marco, "/channels/new?space=$product");
expect_field($h, 'channel-form-field-space', 'space', 'space_member', (string) $product, '🚀 SMOKE Product', 'new channel ?space=: chosen');
$h = screen_html($marco, '/channels/new');
$f = field_of($h, 'channel-form-field-space');
ok($f !== null && $f[0] !== '' && $f[1] !== '', 'new channel with no space: begins on the first space, as the select did (' . ($f[1] ?? '?') . ')');
ok(str_contains($h, 'aria-required="true"'), 'new channel: the space is required (no Clear)');

echo "-- a channel's pins and bookmarks\n";
$h = screen_html($marco, "/channels/{$w['launch']}/pins");
expect_field($h, 'bookmark-form-field-page', 'page', 'page', '', '—', 'bookmark: or a page');
expect_field($h, 'pin-page-field', 'page', 'page', '', 'Choose a page…', 'pin: a page');

echo "-- databases\n";
$h = screen_html($marco, "/databases/?space=$product");
expect_field($h, 'database-filter-space', 'space', 'space', (string) $product, '🚀 SMOKE Product', 'databases filter: the space');
expect_field($h, 'database-new-field-space', 'space', 'space_page', (string) $product, '🚀 SMOKE Product', 'new database: in a space');
expect_field($h, 'database-new-field-parent', 'parent', 'page', '', '—', 'new database: inside a page');
ok(params_of($h, 'database-new-field-parent') === ['editable' => 1, 'pages_only' => 1], 'new database: a page it may sit in (databases left out)');
ok(str_contains($h, '<select name="template"'), 'new database: the template stays a select (a short list of copies)');
$h = screen_html($marco, "/databases/{$w['tasks']}/views/new");
expect_field($h, 'view-form-field-linked_from', 'linked_from', 'page', '', 'Not on a page', 'new view: shown on a page');
ok(str_contains($h, '<select name="layout"') && str_contains($h, '<select name="group_by"'), 'new view: layouts and property choices stay selects');
$h = screen_html($marco, "/databases/{$w['tasks']}/schema");
expect_field($h, 'property-add-field-relation', 'relation_database', 'database', '', '—', 'schema: a relation points at a database');
ok(str_contains($h, '<select name="rollup_relation"') && str_contains($h, '<select name="type"'), 'schema: property types and the rollup\'s relation stay selects');

echo "-- the filters of the lists\n";
$h = screen_html($marco, "/pages/?space=$product");
expect_field($h, 'page-filter-space', 'space', 'space', (string) $product, '🚀 SMOKE Product', 'pages filter: the space');
ok(str_contains($h, 'hx-trigger="change, submit,') && str_contains($h, 'id="page-filters"'), 'pages filter: the form still reloads on a change (the hidden input\'s change bubbles to it)');
$h = screen_html($marco, "/channels/?space=$product");
expect_field($h, 'channel-filter-space', 'space', 'space_member', (string) $product, '🚀 SMOKE Product', 'channels filter: the space');
$h = screen_html($marco, '/channels/');
expect_field($h, 'channel-filter-space', 'space', 'space_member', '', 'Any', 'channels filter with no space: Any');

echo "-- exports and imports\n";
$h = screen_html($marco, "/exports/?page={$w['pricing']}&database={$w['tasks']}&space=$product&channel={$w['launch']}");
expect_field($h, 'export-page-field-page', 'page', 'page', $w['pricing'], 'SMOKE Pricing', 'export: a page');
expect_field($h, 'export-database-field-database', 'database', 'database', $w['tasks'], 'SMOKE Tasks', 'export: a database');
expect_field($h, 'export-space-field-space', 'space', 'space_export', (string) $product, '🚀 SMOKE Product', 'export: a space');
expect_field($h, 'export-channel-field-channel', 'channel', 'channel_export', (string) $w['launch'], '#smoke-launch', 'export: a channel');
ok(str_contains($h, '<select name="format"'), 'export: the formats stay selects');
$h = screen_html($marco, "/import?space=$product&database={$w['tasks']}");
expect_field($h, 'import-form-field-space', 'space', 'space_import', (string) $product, '🚀 SMOKE Product', 'import: into a space');
expect_field($h, 'import-form-field-database', 'database', 'database', $w['tasks'], 'SMOKE Tasks', 'import: a CSV into a database');
ok(str_contains($h, '<select name="kind"'), 'import: the kind stays a select');

echo "-- left as selects, by the rule (D7)\n";
$h = screen_html($marco, "/search?q=hello");
ok(substr_count($h, 'data-picker-source') === 0 && str_contains($h, '<select name="space"'), 'search: its filters are the query language\'s words (space:, in:, from:, @me, a name), not ids — left as selects');
$h = screen_html($owner, '/admin/trash');
ok(str_contains($h, '<select name="space"') && !str_contains($h, 'data-picker-source'), 'admin trash: the spaces that HAVE trash (a derived list) — left a select');
$h = screen_html($owner, '/admin/dispatches');
ok(str_contains($h, '<select name="agent"'), 'admin dispatches: the agents filter (a handful, auto-submits) — left a select');

echo "-- the shell\n";
$h = screen_html($marco, '/');
ok(substr_count($h, 'id="record-picker"') === 1, 'the shell holds the ONE #record-picker modal');
ok(strpos($h, 'id="record-picker"') > strpos($h, '</main>') && strpos($h, 'id="record-picker"') < strpos($h, 'id="assistant-form"'), 'the modal sits after </main>, outside #page-content and the right pane');
ok(strpos($h, '/assets/js/record-picker.js') > strpos($h, '/assets/js/common-init.min.js'), 'record-picker.js loads after common-init.min.js');
ok(str_contains($h, 'hx-vals="js:{...recordPickerValues()}"') && str_contains($h, 'hx-get="/pick/"'), 'the search box carries the htmx request');
$js = req('GET', '/assets/js/record-picker.js'); $css = req('GET', '/assets/css/app-overrides.css');
ok($js['code'] === 200 && str_contains($css['body'], '#record-picker .record-picker-row'), 'the script and the CSS section are served');
$login = req('GET', '/pick/?source=space');
ok($login['code'] === 401, 'GET /pick/ is served by the directory index with the slash (no redirect)');
$noslash = req('GET', '/pick?source=space', ['jar' => $marco]);
ok($noslash['code'] === 200 || $noslash['code'] === 301 || $noslash['code'] === 302, 'GET /pick without the slash does not break (' . $noslash['code'] . ')');
finish();
