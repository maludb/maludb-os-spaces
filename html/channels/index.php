<?php
declare(strict_types=1);
/** /channels/ — the channels of my spaces as cards by space: followed (unread), joinable, private ones I am in; archived apart (screen `channel-browse`; params space, q, archived). */
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/shell/queries.php';
require_login();
require_human();
if (!has_right('spaces.join') && !has_right('spaces.guest')) { require_right('spaces.join'); }
$pdo = db();
$filters = ['space' => request_integer('space'), 'q' => mb_substr(request_string('q'), 0, 80), 'include_archived' => request_bool('archived')];
$rows = find_channels($pdo, $filters);
$unread = unread_counts($pdo);
$bySpace = [];
foreach ($rows as $c) {
    $key = (int) $c['space_id'];
    $bySpace[$key] ??= ['space_id' => $key, 'name' => $c['space_name'], 'icon' => $c['space_icon'], 'channels' => []];
    $bySpace[$key]['channels'][] = $c;
}
$spaces = spaces_for_channel_create($pdo);
$may = ['create' => has_right('channels.create') && $spaces !== []];
log_screen_view($pdo, 'channel-browse');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'spaces' => array_values(array_map(static fn (array $g): array => ['space_id' => $g['space_id'], 'name' => $g['name'], 'channels' => array_map('present_channel', $g['channels'])], $bySpace)), 'unread' => $unread, 'may' => $may]);
}
$notices = ['deleted' => ['success', 'The channel was deleted.'], 'left' => ['success', 'You left the channel.'], 'archived' => ['warning', 'The channel is archived.']];
render_screen('Channels', view('channels/index.php', ['groups' => array_values($bySpace), 'filters' => $filters, 'unread' => $unread, 'spaces' => $spaces, 'may' => $may, 'here' => here_url(), 'notice' => sp_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'channels', 'screen' => 'channel-browse', 'entity' => 'channel']);
