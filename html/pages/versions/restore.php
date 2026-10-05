<?php
declare(strict_types=1);
/** Action `version_restore` (log `page.version_restore`: from_version, version_no; confirm; agent approval `deletion`): full; the present is snapshotted first (the SQL function). */
require_once dirname(__DIR__, 3) . '/app/features/pages/handler.php';
sp_handler_begin();
$pdo = db();
$vid = request_integer('version') ?? refuse(422, 'Say which version.');
$st = $pdo->prepare('SELECT page_id::text AS page_id, version_no FROM mcp_page_versions WHERE version_id = :v');
$st->execute(['v' => $vid]);
$row = $st->fetch() ?: refuse(404, 'Version not found.');
$p = find_page($pdo, (string) $row['page_id']) ?? refuse(404, 'Page not found.');
require_page_level($p['page_id'], 'full');
$vn = sp_guard($pdo, static function () use ($pdo, $p, $vid, $row): int {
    $pdo->beginTransaction();
    $vn = (int) one_value($pdo, 'SELECT sp_version_restore(:v)', ['v' => $vid]);
    page_log($pdo, 'page.version_restore', $p['page_id'], $p['space_id'], ['after' => ['from_version' => (int) $row['version_no'], 'version_no' => $vn]]);
    $pdo->commit();
    return $vn;
});
sp_done('Restored version ' . (int) $row['version_no'] . ' as version ' . $vn, $p['page_id'], sp_land(return_path('/pages/' . $p['page_id']), 'restored'), 'pageChanged', ['page_id' => $p['page_id'], 'version_no' => $vn]);
