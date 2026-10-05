<?php
declare(strict_types=1);
/**
 * GET /databases/{id}/schema — the properties as a table: name, key, type, options, relation target, rollup source and function, prefix, format; Add, Rename, Retype, Remove (screen `database-schema`).
 * POST — action `database_schema_save` (log `database.schema_save`: properties_added[], properties_removed[], properties_retyped[]; confirm; agent approval `other`): edit; the whole `properties` object
 * as one UPDATE (the database's guard validates every property).
 */
require_once dirname(__DIR__, 2) . '/app/features/databases/handler.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    require_login();
    require_human();
    $pdo = db();
    $d = find_database($pdo, (string) ($_GET['id'] ?? ($_GET['database'] ?? ''))) ?? refuse(404, 'Database not found.');
    $rank = PAGE_LEVELS[$d['my_level']] ?? 0;
    $may = ['schema' => $rank >= 4 && $d['archived_at'] === null, 'full' => $rank >= 5 && $d['archived_at'] === null];
    $targets = $may['schema'] ? relation_targets($pdo) : [];
    $relTitles = [];
    foreach ($d['properties'] as $def) {
        if (($def['type'] ?? '') === 'relation') {
            $t = find_database($pdo, (string) $def['relation']['database_id']);
            $relTitles[(string) $def['relation']['database_id']] = $t === null ? 'a database you cannot see' : $t['plain_title'];
        }
    }
    log_screen_view($pdo, 'database-schema');
    if (wants_json()) {
        respond_screen(['database' => present_database($d), 'properties' => present_schema($d['properties']), 'may' => $may]);
    }
    render_screen('Schema of ' . ($d['plain_title'] ?: 'the database'), view('databases/schema.php', ['d' => $d, 'may' => $may, 'targets' => $targets, 'relTitles' => $relTitles, 'here' => here_url(), 'notice' => sp_notice($_GET['notice'] ?? null, database_notices($d))]),
        ['activeNav' => 'databases', 'screen' => 'database-schema', 'entity' => 'database', 'recordId' => $d['database_id']]);
    exit;
}
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$d = database_from_request($pdo);
require_page_level($d['database_id'], 'edit', 'Database');
$raw = $_POST['properties'] ?? null;
$props = is_array($raw) ? $raw : (trim((string) $raw) === '' ? null : json_decode((string) $raw, true));
if (!is_array($props) || ($props !== [] && array_is_list($props))) { sp_refuse_fields(['properties' => 'The schema is a JSON object keyed by property key.']); }
$diff = sp_guard($pdo, static function () use ($pdo, $me, $d, $props): array {
    $pdo->beginTransaction();
    $diff = save_schema($pdo, $d['database_id'], $props, $me);
    database_log($pdo, 'database.schema_save', 'database', $d['database_id'], $d['space_id'], ['after' => ['properties_added' => $diff['added'], 'properties_removed' => $diff['removed'], 'properties_retyped' => $diff['retyped']]]);
    $pdo->commit();
    return $diff;
});
sp_done('Saved the schema of ' . ($d['plain_title'] ?: 'the database'), $d['database_id'], sp_land(return_path('/databases/' . $d['database_id'] . '/schema'), 'schema'), 'databaseChanged', ['database_id' => $d['database_id'], 'properties_added' => $diff['added'], 'properties_removed' => $diff['removed'], 'properties_retyped' => $diff['retyped']]);
