<?php
declare(strict_types=1);
/** /channels/{id}/pins — pinned messages and pages, bookmarks (screen `channel-pins`): add a bookmark; unpin; delete a bookmark. */
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
require_login();
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('channel') ?? refuse(404, 'Channel not found.');
$c = find_channel($pdo, $id) ?? refuse(404, 'Channel not found.');
$pins = channel_pins($pdo, $id);
$bookmarks = channel_bookmarks($pdo, $id);
$may = ['write' => $c['i_am_member'] && $c['archived_at'] === null];
$pages = $may['write'] ? $pdo->query("SELECT page_id::text AS page_id, plain_title FROM mcp_pages WHERE archived_at IS NULL AND NOT is_template AND parent_database_id IS NULL ORDER BY last_edited_at DESC LIMIT 200")->fetchAll() : [];
log_screen_view($pdo, 'channel-pins');
if (wants_json()) {
    respond_screen(['channel' => present_channel($c), 'pins' => array_map('present_pin', $pins), 'bookmarks' => array_map('present_bookmark', $bookmarks), 'may' => $may]);
}
render_screen('Pins of ' . $c['label'], view('channels/pins.php', ['c' => $c, 'pins' => $pins, 'bookmarks' => $bookmarks, 'pages' => $pages, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, channel_notices($c))]),
    ['activeNav' => 'channels', 'screen' => 'channel-pins', 'entity' => 'channel', 'recordId' => (string) $id]);
