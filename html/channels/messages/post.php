<?php
declare(strict_types=1);
/**
 * Action `message_post` (log `message.post`: message_id, length, mentions, has_attachments, scheduled_for — never the body): sp_can_post(); the body from
 * `markdown` (the converter) or the composer's `runs` (JSON); an agent's shouts stripped; `schedule_for` sends later; `attachments[]` the ids uploaded first.
 * Answers the rendered row (the composer appends it) and marks the channel read to it.
 */
require_once dirname(__DIR__, 3) . '/app/features/messages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$c = channel_from_request($pdo, false);
require_posting($c);
$human = (current_member()['member_kind'] ?? '') === 'human' && !is_action_authed_agent();
$body = message_body_from_request($pdo, $c, $human);
$when = request_time('schedule_for');
$att = attachment_ids_from_request();
if ($body === [] && $att === []) { sp_refuse_fields(['markdown' => 'Say something, or attach a file.']); }
$id = sp_guard($pdo, static function () use ($pdo, $c, $body, $when, $att, $me): int {
    $pdo->beginTransaction();
    $id = post_message($pdo, $c['channel_id'], $body, null, false, $when, $att, $me);
    message_log($pdo, 'message.post', $c, $id, message_facts($body, $att, $when));
    if ($when === null) { mark_read($pdo, $c['channel_id'], $id); }
    $pdo->commit();
    return $id;
});
message_reply($pdo, $id, $c, $when === null ? 'Posted in ' . $c['label'] : 'Scheduled for ' . format_ts($when, member_timezone(), 'M j, g:i A'));
