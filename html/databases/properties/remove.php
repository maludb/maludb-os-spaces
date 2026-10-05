<?php
declare(strict_types=1);
/** Action `database_property_remove` (log `database.property_remove`: key, purge_values; confirm; agent approval `other`): edit; the rows keep their values until `purge_values`; the title and a relation a rollup goes through are the database's to refuse. */
require_once dirname(dirname(__DIR__, 2)) . '/app/features/databases/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$d = database_from_request($pdo);
require_page_level($d['database_id'], 'edit', 'Database');
$key = trim((string) (req_val('key') ?? ''));
if ($key === '') { sp_refuse_fields(['key' => 'Say which property to remove.']); }
$purge = sp_yes('purge_values');
$k = sp_guard($pdo, static function () use ($pdo, $me, $d, $key, $purge): string {
    $pdo->beginTransaction();
    $k = remove_property($pdo, $d['database_id'], $key, $purge, $me);
    database_log($pdo, 'database.property_remove', 'database', $d['database_id'], $d['space_id'], ['after' => ['key' => $k, 'purge_values' => $purge]]);
    $pdo->commit();
    return $k;
});
sp_done('Removed ' . $k . ($purge ? ' and its values' : ''), $d['database_id'], sp_land(return_path('/databases/' . $d['database_id'] . '/schema'), 'removed'), 'databaseChanged', ['database_id' => $d['database_id'], 'key' => $k, 'purge_values' => $purge]);
