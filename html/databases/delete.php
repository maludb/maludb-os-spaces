<?php
declare(strict_types=1);
/** Action `database_delete` (log `database.delete`: title, row_count; confirm; agent approval `deletion`): full; to the trash with its rows (restore is `page_restore`). */
require_once dirname(__DIR__, 2) . '/app/features/databases/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$d = database_from_request($pdo);
require_page_level($d['database_id'], 'full', 'Database');
$n = sp_guard($pdo, static function () use ($pdo, $me, $d): int {
    $pdo->beginTransaction();
    $n = delete_database($pdo, $d['database_id'], $me);
    database_log($pdo, 'database.delete', 'database', $d['database_id'], $d['space_id'], ['after' => ['title' => mb_substr($d['plain_title'], 0, 120), 'row_count' => $n]]);
    $pdo->commit();
    return $n;
});
sp_done('Moved ' . ($d['plain_title'] ?: 'the database') . ' to the trash' . ($n > 0 ? ' with ' . $n . ' row' . ($n === 1 ? '' : 's') : ''), $d['database_id'], sp_land(return_path($d['space_id'] === null ? '/databases/' : '/spaces/' . $d['space_id']), 'trashed'), 'databaseChanged', ['database_id' => $d['database_id'], 'row_count' => $n]);
