<?php
declare(strict_types=1);
/**
 * GET — the relation picker (HTMX partial or a plain page; params row, property, q): the rows of the target database, the current links ticked. POST — action `row_relation_set`
 * (log `row.relation_set`: key, target_count): edit_content; `property` a relation, `targets[]` the WHOLE list (a row of another database is the guard's to refuse); the dual side follows by trigger.
 */
require_once dirname(__DIR__, 3) . '/app/features/databases/handler.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    require_login();
    require_human();
    $pdo = db();
    $r = row_from_request($pdo);
    require_page_level($r['page_id'], 'edit_content', 'Database');
    $d = $r['database'];
    $key = resolve_property_key($pdo, $d['properties'], (string) ($_GET['property'] ?? '')) ?? refuse(422, 'Say which relation property.');
    $def = $d['properties'][$key];
    if ($def['type'] !== 'relation') { refuse(422, ($def['name'] ?? $key) . ' is not a relation.'); }
    $target = find_database($pdo, (string) $def['relation']['database_id']);
    $q = mb_substr(request_string('q'), 0, 80);
    $cands = $target === null ? [] : relation_candidates($pdo, $target['database_id'], $q, 50);
    $have = row_relations($pdo, $r['page_id'], $key);
    $ids = array_column($have, 'row_id');
    if (wants_json()) {
        respond_screen(['row_id' => $r['page_id'], 'property' => $key, 'target_database' => $target === null ? null : ['database_id' => $target['database_id'], 'title' => $target['plain_title']], 'linked' => $have, 'candidates' => $cands]);
    }
    $html = view('databases/partials/relation-picker.php', ['r' => $r, 'key' => $key, 'def' => $def, 'target' => $target, 'cands' => $cands, 'have' => $have, 'ids' => $ids, 'q' => $q, 'here' => here_url()]);
    if (is_htmx_request() && !is_htmx_boosted()) { header('Vary: HX-Request'); echo $html; exit; }
    render_screen('Link ' . ($def['name'] ?? $key), $html, ['activeNav' => 'databases', 'screen' => 'row-view', 'entity' => 'row', 'recordId' => $r['page_id']]);
    exit;
}
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$r = row_from_request($pdo);
require_page_level($r['page_id'], 'edit_content', 'Database');
$d = $r['database'];
$prop = trim((string) (req_val('property') ?? ''));
$key = $prop === '' ? null : resolve_property_key($pdo, $d['properties'], $prop);
if ($key === null) { sp_refuse_fields(['property' => 'Say which relation property.']); }
if (($d['properties'][$key]['type'] ?? '') !== 'relation') { sp_refuse_fields(['property' => ($d['properties'][$key]['name'] ?? $key) . ' is not a relation.']); }
$targets = request_list('targets') ?? [];
sp_guard($pdo, static function () use ($pdo, $me, $r, $d, $key, $targets): void {
    $pdo->beginTransaction();
    set_row_relation($pdo, $r['page_id'], $key, $targets, $me);
    database_log($pdo, 'row.relation_set', 'row', $r['page_id'], $r['space_id'], ['after' => ['database_id' => $d['database_id'], 'key' => $key, 'target_count' => count($targets)]]);
    $pdo->commit();
});
sp_done('Saved ' . ($d['properties'][$key]['name'] ?? $key), $r['page_id'], sp_land(return_path('/databases/' . $d['database_id'] . '/rows/' . $r['page_id']), 'related'), 'rowChanged', ['row_id' => $r['page_id'], 'key' => $key, 'target_count' => count($targets)]);
