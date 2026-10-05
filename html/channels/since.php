<?php
declare(strict_types=1);
/**
 * GET /channels/{id}/since?after=&hashes= — the poll (design §8, D9): 204 with nothing new (one indexed query), else the new rows rendered, the rows
 * whose state hash changed as out-of-band swaps, and X-Message-Hashes for the client's next ask. A screen helper (session only; no log).
 */
require_once dirname(__DIR__, 2) . '/app/features/messages/handler.php';
require_login();
$pdo = db();
$id = request_integer('id') ?? request_integer('channel') ?? refuse(404, 'Channel not found.');
$c = find_channel($pdo, $id) ?? refuse(404, 'Channel not found.');
$after = request_integer('after') ?? 0;
$known = json_decode((string) ($_GET['hashes'] ?? '{}'), true);
$known = is_array($known) ? $known : [];
header('Cache-Control: no-store');
$new = channel_history($pdo, $id, null, $after, 50);
$current = row_hashes(channel_history($pdo, $id, null, null, 50));
$changed = [];
foreach ($current as $mid => $h) {
    if (isset($known[$mid]) && $known[$mid] !== $h && (int) $mid <= $after) { $changed[] = (int) $mid; }
}
$running = running_dispatches($pdo, $id);
header('X-Message-Hashes: ' . json_encode($current));
header('X-Running: ' . count($running));
if ($new === [] && $changed === []) {
    if (wants_json()) { json_response(['data' => ['new' => [], 'changed' => [], 'hashes' => $current, 'running' => $running]]); }
    http_response_code(204);
    exit;
}
if (wants_json()) {
    $rows = [];
    foreach ($changed as $mid) { $m = message_row($pdo, $mid); if ($m !== null) { $rows[] = present_message($m); } }
    json_response(['data' => ['new' => array_map('present_message', $new), 'changed' => $rows, 'hashes' => $current, 'running' => $running]]);
}
$last = $new === [] ? null : $new[count($new) - 1]['message_id'];
if ($last !== null) { header('HX-Trigger: ' . json_encode(['readMoved' => $last])); }
$may = message_may($c);
foreach ($new as $m) { echo message_html($pdo, $m, $c, ['may' => $may]); }
foreach ($changed as $mid) { $m = message_row($pdo, $mid); if ($m !== null) { echo message_html($pdo, $m, $c, ['may' => $may, 'oob' => true]); } }
