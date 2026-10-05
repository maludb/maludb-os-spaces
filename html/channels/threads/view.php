<?php
declare(strict_types=1);
/** /channels/{id}/threads/{message} — the thread (screen `thread-view`): the first message and every reply; the reply composer with "Also send to #channel". At 1280 the right pane beside the channel (hx-target), at 375 this page. */
require_once dirname(__DIR__, 3) . '/app/features/messages/handler.php';
require_login();
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('channel') ?? refuse(404, 'Channel not found.');
$c = find_channel($pdo, $id) ?? refuse(404, 'Channel not found.');
$rootId = request_integer('message') ?? refuse(404, 'Message not found.');
$root = message_row($pdo, $rootId) ?? refuse(404, 'Message not found.');
if ((int) $root['channel_id'] !== $id) { refuse(404, 'Message not found.'); }
if ($root['thread_root_id'] !== null) { $rootId = (int) $root['thread_root_id']; $root = message_row($pdo, $rootId) ?? refuse(404, 'Message not found.'); }
$rows = thread($pdo, $rootId);
$may = message_may($c);
$running = array_values(array_filter(running_dispatches($pdo, $id), static fn (array $d): bool => $d['conversation_id'] === 'spaces:thread:' . $rootId));
log_screen_view($pdo, 'thread-view');
if (wants_json()) {
    respond_screen(['channel' => present_channel($c), 'root_id' => $rootId, 'messages' => array_map('present_message', $rows), 'hashes' => row_hashes($rows), 'running' => $running, 'may' => $may]);
}
$pane = ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'right-pane-body';
$html = view('channels/thread.php', ['c' => $c, 'rootId' => $rootId, 'rows' => $rows, 'running' => $running, 'may' => $may, 'me' => (int) current_member_id(), 'pane' => $pane, 'here' => here_url(), 'tz' => member_timezone()]);
if ($pane) {
    header('X-Pane-Title: Thread in ' . $c['label']);
    header('Cache-Control: no-store');
    echo $html;
    exit;
}
render_screen('Thread in ' . $c['label'], $html, ['activeNav' => in_array($c['kind'], ['dm', 'group_dm'], true) ? 'dms' : 'channels', 'screen' => 'thread-view', 'entity' => 'message', 'recordId' => (string) $rootId]);
