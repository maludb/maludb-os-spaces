<?php
declare(strict_types=1);
/** GET /channels/mentions.php?channel=&q=&kind=member|emoji — the composer's pickers (a screen helper, JSON, no log): the channel's people and agents, departments, the shouts for a person; emoji. */
require_once dirname(__DIR__, 2) . '/app/features/messages/handler.php';
require_login();
$pdo = db();
$id = request_integer('channel') ?? refuse(404, 'Channel not found.');
$c = find_channel($pdo, $id) ?? refuse(404, 'Channel not found.');
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60);
header('Cache-Control: no-store');
if (($_GET['kind'] ?? '') === 'emoji') {
    json_response(['data' => ['q' => $q, 'kind' => 'emoji', 'candidates' => mention_candidates($pdo, $q, 'emoji')]]);
}
$shouts = (current_member()['member_kind'] ?? '') === 'human' && !in_array($c['kind'], ['dm', 'group_dm'], true);
json_response(['data' => ['q' => $q, 'kind' => 'member', 'candidates' => channel_mention_candidates($pdo, $id, $q, $shouts)]]);
