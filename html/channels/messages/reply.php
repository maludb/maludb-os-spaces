<?php
declare(strict_types=1);
/** Action `thread_reply` (log `thread.reply`: + also_to_channel): sp_can_post(); the thread's first message (or any in it); `also_to_channel` shows it in the channel too. */
require_once dirname(__DIR__, 3) . '/app/features/messages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
[$root, $c] = message_from_request($pdo);
require_posting($c);
$rootId = $root['thread_root_id'] !== null ? (int) $root['thread_root_id'] : (int) $root['message_id'];
$human = (current_member()['member_kind'] ?? '') === 'human' && !is_action_authed_agent();
$body = message_body_from_request($pdo, $c, $human);
$att = attachment_ids_from_request();
if ($body === [] && $att === []) { sp_refuse_fields(['markdown' => 'Say something, or attach a file.']); }
$also = sp_yes('also_to_channel');
$id = sp_guard($pdo, static function () use ($pdo, $c, $body, $att, $me, $rootId, $also): int {
    $pdo->beginTransaction();
    $id = post_message($pdo, $c['channel_id'], $body, $rootId, $also, null, $att, $me);
    message_log($pdo, 'thread.reply', $c, $id, message_facts($body, $att, null) + ['thread_root_id' => $rootId, 'also_to_channel' => $also]);
    $pdo->commit();
    return $id;
});
message_reply($pdo, $id, $c, 'Replied in ' . $c['label'], ['thread_root_id' => $rootId]);
