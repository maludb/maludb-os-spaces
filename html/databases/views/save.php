<?php
declare(strict_types=1);
/**
 * Action `view_save` (log `view.save`: name, layout, has_filter, sort_count, group_by): edit. Makes a view of the database (`database`) or changes one (`view`) — a field left out stays. `filter` is Notion's
 * filter object (JSON), or the editor's `f`/`g`/`fj` rows; `sort` `[{property, direction}]` (up to three) or the editor's `sort[n]`; group_by / sub_group_by / calendar_by / timeline_start /
 * timeline_end / card_cover name properties (by key, display name or id); `visible_properties[]`; card_size; wrap; `linked_from` a page — a linked view, embedded there read-only.
 */
require_once dirname(__DIR__, 3) . '/app/features/databases/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$viewId = (string) (req_val('view') ?? '');
$cur = null;
if ($viewId !== '') {
    $cur = is_uuid($viewId) ? find_view($pdo, $viewId) : null;
    $cur ?? refuse(404, 'View not found.');
    $_POST['database'] = $cur['database_id'];
}
$d = database_from_request($pdo);
require_page_level($d['database_id'], 'edit', 'Database');
$schema = $d['properties'];
$errors = [];
$f = [];
if (req_has('name')) {
    $n = trim((string) req_val('name'));
    if ($n === '' || mb_strlen($n) > 80) { $errors['name'] = 'Name the view in up to 80 characters.'; }
    $f['name'] = $n;
} elseif ($cur === null) {
    $errors['name'] = 'Name the view in up to 80 characters.';
}
if (req_has('layout')) { $f['layout'] = (string) req_val('layout'); }
$prop = static function (string $name) use (&$errors, $schema, $pdo, &$f): void {
    if (!req_has($name)) { return; }
    $v = trim((string) req_val($name));
    if ($v === '') { $f[$name] = null; return; }
    $k = resolve_property_key($pdo, $schema, $v);
    if ($k === null) { $errors[$name] = 'No property is called "' . $v . '".'; return; }
    $f[$name] = $k;
};
foreach (['group_by', 'sub_group_by', 'calendar_by', 'timeline_start', 'timeline_end'] as $n) { $prop($n); }
foreach (['calendar_by', 'timeline_start', 'timeline_end'] as $n) {
    if (isset($f[$n]) && !in_array($schema[$f[$n]]['type'] ?? '', ['date', 'created_time', 'last_edited_time'], true)) { $errors[$n] = ($schema[$f[$n]]['name'] ?? $f[$n]) . ' is not a date property.'; }
}
$layout = $f['layout'] ?? ($cur['layout'] ?? 'table');
$board = $f['group_by'] ?? ($cur['group_by'] ?? null);
if ($layout === 'board' && $board !== null && !in_array($schema[$board]['type'] ?? '', ['select', 'status', 'people'], true) && !isset($errors['group_by'])) {
    $errors['group_by'] = 'A board groups by a select, status or people property.';
}
if (req_has('card_cover')) {
    $v = trim((string) req_val('card_cover'));
    if ($v === '' || in_array($v, ['page_cover', 'page_icon'], true)) { $f['card_cover'] = $v === '' ? null : $v; }
    else {
        $k = resolve_property_key($pdo, $schema, $v);
        if ($k === null || ($schema[$k]['type'] ?? '') !== 'files') { $errors['card_cover'] = 'A card\'s cover is the page cover, the page icon or a files property.'; } else { $f['card_cover'] = $k; }
    }
}
if (req_has('card_size')) { $v = (string) req_val('card_size'); $f['card_size'] = $v === '' ? null : $v; }
if (req_has('wrap')) { $f['wrap'] = sp_yes('wrap'); }
if (array_key_exists('visible_properties', $_POST) || req_has('visible_all')) {
    $list = req_has('visible_all') && sp_yes('visible_all') ? [] : (request_list('visible_properties') ?? []);
    $keys = [];
    foreach ($list as $x) {
        $k = resolve_property_key($pdo, $schema, $x);
        if ($k === null) { $errors['visible_properties'] = 'No property is called "' . $x . '".'; break; }
        $keys[$k] = $k;
    }
    $f['visible_properties'] = $keys === [] ? null : array_values($keys);
}
// the filter: the editor's rows, or Notion's object as JSON
try {
    if (isset($_POST['f']) || isset($_POST['g']) || isset($_POST['fj'])) {
        $f['filter'] = filter_from_form($_POST, $schema);
    } elseif (array_key_exists('filter', $_POST)) {
        $raw = $_POST['filter'];
        $j = is_array($raw) ? $raw : (trim((string) $raw) === '' ? null : json_decode((string) $raw, true));
        if ($raw !== null && !is_array($j) && trim((string) $raw) !== '' ) { throw new DomainException('The filter is Notion\'s filter object as JSON.'); }
        $f['filter'] = validate_filter($j, $schema, $pdo);
    }
} catch (DomainException $e) {
    $errors['filter'] = $e->getMessage();
}
// the sort
if (array_key_exists('sort', $_POST)) {
    $raw = $_POST['sort'];
    $rows = is_array($raw) ? $raw : (trim((string) $raw) === '' ? [] : json_decode((string) $raw, true));
    if (!is_array($rows)) { $errors['sort'] = 'The sort is a list of {property, direction}.'; $rows = []; }
    $sort = [];
    foreach ($rows as $s) {
        if (!is_array($s) || trim((string) ($s['property'] ?? '')) === '') { continue; }
        $k = resolve_property_key($pdo, $schema, (string) $s['property']);
        if ($k === null) { $errors['sort'] = 'No property is called "' . $s['property'] . '".'; break; }
        $dir = strtolower((string) ($s['direction'] ?? 'ascending'));
        $dir = in_array($dir, ['desc', 'descending'], true) ? 'descending' : 'ascending';
        $sort[] = ['property' => $k, 'direction' => $dir];
    }
    if (count($sort) > 3) { $errors['sort'] = 'Sort by up to three properties.'; }
    $f['sort'] = array_slice($sort, 0, 3);
}
$linkedPage = null;
if (req_has('linked_from') && (string) req_val('linked_from') !== '') {
    $lp = (string) req_val('linked_from');
    $linkedPage = is_uuid($lp) ? find_page($pdo, $lp) : null;
    if ($linkedPage === null || $linkedPage['archived_at'] !== null || $linkedPage['is_row'] || $linkedPage['kind'] !== 'page') { $errors['linked_from'] = 'The page to embed the view on is not here.'; }
    else { require_page_level($lp, 'edit', 'Page'); $f['linked_from'] = $lp; }
} elseif (req_has('linked_from')) {
    $f['linked_from'] = null;
}
if ($errors !== []) { sp_refuse_fields($errors); }
$id = sp_guard($pdo, static function () use ($pdo, $me, $d, $cur, $viewId, $f): string {
    $pdo->beginTransaction();
    $id = save_view($pdo, $d['database_id'], $cur === null ? null : $viewId, $f, $me);
    $v = view_state($pdo, $id);
    database_log($pdo, 'view.save', 'view', $id, $d['space_id'], ['after' => ['database_id' => $d['database_id'], 'name' => mb_substr((string) $v['name'], 0, 80), 'layout' => $v['layout'], 'has_filter' => $v['filter'] !== null, 'sort_count' => count($v['sort']), 'group_by' => $v['group_by'], 'created' => $cur === null]]);
    $pdo->commit();
    return $id;
});
sp_done(($cur === null ? 'Made the view ' : 'Saved the view ') . ($f['name'] ?? $cur['name'] ?? ''), $id, sp_land(return_path('/databases/' . $d['database_id'] . '?view=' . $id), 'view'), 'databaseChanged', ['view_id' => $id, 'database_id' => $d['database_id']]);
