<?php
declare(strict_types=1);
/** Action `message_save` (log `message.save`: saved): a member; saved yes (default) or no. */
require_once dirname(__DIR__, 3) . '/app/features/messages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
[$m, $c] = message_from_request($pdo);
require_channel_member($c);
$on = sp_yes('saved', true);
sp_guard($pdo, static function () use ($pdo, $c, $m, $me, $on): void {
    $pdo->beginTransaction();
    if (save_message($pdo, (int) $m['message_id'], $me, $on)) { message_log($pdo, 'message.save', $c, (int) $m['message_id'], ['saved' => $on]); }
    $pdo->commit();
});
message_reply($pdo, (int) $m['message_id'], $c, $on ? 'Saved for later' : 'Removed from Saved', ['saved' => $on]);
