<?php
declare(strict_types=1);
/**
 * /admin/connections — what sibling applications read of ours (screen `connection-list`; right agents.settings): this application's shares[] (from maludb-os.json) and the `share.read` rows of the activity log
 * (which application, which tool, when, how many rows). Approving a connection is the super-admin's in the kernel (bin/app_connection.php — there is no API): the page links to the OS.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/agents/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/agents/present.php';
require_right('agents.settings');
$pdo = db();
$shares = declared_shares();
$reads = share_reads($pdo, 100);
$os = ($b = rtrim((string) env('OS_LAUNCHER_URL', ''), '/')) === '' ? null : $b . '/applications';
log_screen_view($pdo, 'connection-list');
if (wants_json()) {
    respond_screen(['shares' => $shares, 'reads' => array_map('present_share_read', $reads), 'os' => ['applications' => $os]]);
}
render_screen('Connections', view('admin/connections.php', ['shares' => $shares, 'reads' => $reads, 'os' => $os, 'tz' => member_timezone()]), ['activeNav' => 'admin-connections', 'screen' => 'connection-list']);
