<?php
declare(strict_types=1);
/** Action `channel_announce` (log `message.announce`: reach; confirm; agent approval `other`): a member posts with @channel, @here or @everyone — the explicit way (an agent's shout in message_post is stripped). */
require_once dirname(__DIR__, 3) . '/app/features/messages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$c = channel_from_request($pdo);
require_posting($c);
if (in_array($c['kind'], ['dm', 'group_dm'], true)) { refuse(422, 'A conversation has nobody to announce to: everyone in it is told already.'); }
$reach = (string) (req_val('reach') ?? 'channel');
if (!in_array($reach, ['channel', 'here', 'everyone'], true)) { sp_refuse_fields(['reach' => 'reach is channel, here or everyone.']); }
$md = trim((string) (req_val('markdown') ?? ''));
if ($md === '') { sp_refuse_fields(['markdown' => 'Say what to announce.']); }
$body = array_merge([['type' => 'mention', 'mention' => ['type' => $reach, 'id' => '', 'name' => $reach], 'plain_text' => '@' . $reach], ['type' => 'text', 'text' => ['content' => ' ', 'link' => null], 'annotations' => [], 'plain_text' => ' ']],
    message_body_from_markdown($pdo, $md, $c['channel_id'], false));
$id = sp_guard($pdo, static function () use ($pdo, $c, $body, $me, $reach): int {
    $pdo->beginTransaction();
    $id = post_message($pdo, $c['channel_id'], $body, null, false, null, [], $me);
    message_log($pdo, 'message.announce', $c, $id, message_facts($body, [], null) + ['reach' => $reach]);
    mark_read($pdo, $c['channel_id'], $id);
    $pdo->commit();
    return $id;
});
message_reply($pdo, $id, $c, 'Announced to @' . $reach . ' in ' . $c['label'], ['reach' => $reach]);
