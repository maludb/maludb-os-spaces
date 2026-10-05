<?php
declare(strict_types=1);
/**
 * Action `database_property_save` (log `database.property_save`: key, type, retyped_rows): edit. `key` is the property's key, display name or id (a name nobody has makes a new one); `name` renames;
 * type, options[], relation_database + two_way, rollup_relation + rollup_property + rollup_function, prefix, number_format. A retype is lossless only; every row's value follows in the same
 * transaction. The database's own sentence refuses a second title, a formula (Extended), a rollup through a non-relation.
 */
require_once dirname(dirname(__DIR__, 2)) . '/app/features/databases/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$d = database_from_request($pdo);
require_page_level($d['database_id'], 'edit', 'Database');
$key = trim((string) (req_val('key') ?? ''));
if ($key === '') { sp_refuse_fields(['key' => 'Say which property, or name the new one.']); }
$spec = [];
foreach (['type', 'name', 'relation_database', 'rollup_relation', 'rollup_property', 'rollup_function', 'prefix', 'number_format'] as $f) {
    if (req_has($f) && (string) req_val($f) !== '') { $spec[$f] = (string) req_val($f); }
}
if (req_has('two_way')) { $spec['two_way'] = sp_yes('two_way'); }
if (array_key_exists('options', $_POST)) {
    $o = $_POST['options'];
    $spec['options'] = is_array($o) ? $o : (string) $o;
}
$r = sp_guard($pdo, static function () use ($pdo, $me, $d, $key, $spec): array {
    $pdo->beginTransaction();
    $r = save_property($pdo, $d['database_id'], $key, $spec, $me);
    database_log($pdo, 'database.property_save', 'database', $d['database_id'], $d['space_id'], ['after' => ['key' => $r['key'], 'type' => $r['def']['type'], 'retyped_rows' => $r['retyped'], 'new' => $r['is_new'], 'from_type' => $r['from_type'], 'renamed' => $r['renamed']]]);
    $pdo->commit();
    return $r;
});
sp_done(($r['is_new'] ? 'Added ' : 'Saved ') . ($r['def']['name'] ?? $r['key']), $d['database_id'], sp_land(return_path('/databases/' . $d['database_id'] . '/schema'), 'property', 'property-row-' . $r['key']), 'databaseChanged', ['database_id' => $d['database_id'], 'key' => $r['key'], 'retyped_rows' => $r['retyped']]);
