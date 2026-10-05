<?php
declare(strict_types=1);
/** GET /channels/{id}/threads/{message}/since?after=&hashes= — the thread's poll, as the channel's. */
require_once dirname(__DIR__, 3) . '/app/features/messages/handler.php';
require_login();
$pdo = db();
$id = request_integer('id') ?? request_integer('channel') ?? refuse(404, 'Channel not found.');
$c = find_channel($pdo, $id) ?? refuse(404, 'Channel not found.');
$rootId = request_integer('message') ?? refuse(404, 'Message not found.');
$after = request_integer('after') ?? 0;
$known = json_decode((string) ($_GET['hashes'] ?? '{}'), true);
$known = is_array($known) ? $known : [];
header('Cache-Control: no-store');
$all = thread($pdo, $rootId);
$new = array_values(array_filter($all, static fn (array $m): bool => $m['message_id'] > $after));
$current = row_hashes($all);
$changed = [];
foreach ($current as $mid => $h) { if (isset($known[$mid]) && $known[$mid] !== $h && (int) $mid <= $after) { $changed[] = (int) $mid; } }
$running = array_values(array_filter(running_dispatches($pdo, $id), static fn (array $d): bool => $d['conversation_id'] === 'spaces:thread:' . $rootId));
header('X-Message-Hashes: ' . json_encode($current));
header('X-Running: ' . count($running));
if ($new === [] && $changed === []) {
    if (wants_json()) { json_response(['data' => ['new' => [], 'changed' => [], 'hashes' => $current, 'running' => $running]]); }
    http_response_code(204);
    exit;
}
if (wants_json()) {
    json_response(['data' => ['new' => array_map('present_message', $new), 'changed' => array_map('present_message', array_values(array_filter($all, static fn (array $m): bool => in_array($m['message_id'], $changed, true)))), 'hashes' => $current, 'running' => $running]]);
}
$may = message_may($c);
if ($new !== []) { header('HX-Trigger: ' . json_encode(['readMoved' => $new[count($new) - 1]['message_id']])); }
foreach ($new as $m) { echo message_html($pdo, $m, $c, ['may' => $may, 'in_thread' => true]); }
foreach ($all as $m) { if (in_array($m['message_id'], $changed, true)) { echo message_html($pdo, $m, $c, ['may' => $may, 'in_thread' => true, 'oob' => true]); } }
