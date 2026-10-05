<?php
declare(strict_types=1);
/** Action `message_edit` (log `message.edit`: length): own (the guard's words otherwise); the row re-rendered with its "edited" mark. */
require_once dirname(__DIR__, 3) . '/app/features/messages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
[$m, $c] = message_from_request($pdo);
if ((int) $m['author_member_id'] !== $me) { refuse(403, 'Only the author edits a message.'); }
if ($m['deleted_at'] !== null) { refuse(422, 'A deleted message is not edited.'); }
$human = (current_member()['member_kind'] ?? '') === 'human' && !is_action_authed_agent();
$body = message_body_from_request($pdo, $c, $human);
if ($body === []) { sp_refuse_fields(['markdown' => 'Say something.']); }
sp_guard($pdo, static function () use ($pdo, $c, $m, $body): void {
    $pdo->beginTransaction();
    edit_message($pdo, (int) $m['message_id'], $body);
    message_log($pdo, 'message.edit', $c, (int) $m['message_id'], ['length' => message_facts($body, [], null)['length']]);
    $pdo->commit();
});
message_reply($pdo, (int) $m['message_id'], $c, 'Edited');
