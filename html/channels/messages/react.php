<?php
declare(strict_types=1);
/** Action `reaction_add` (log `message.react`: emoji): a member; a Unicode emoji or :shortcode:. */
require_once dirname(__DIR__, 3) . '/app/features/messages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
[$m, $c] = message_from_request($pdo);
require_channel_member($c);
$emoji = trim((string) (req_val('emoji') ?? ''));
if (preg_match('/^:?([a-z0-9_+-]+):?$/', $emoji, $x) && ($e = emoji_lookup($pdo, $x[1])) !== null) { $emoji = $e; }
if ($emoji === '' || mb_strlen($emoji) > 16 || preg_match('/[A-Za-z0-9]/', $emoji)) { sp_refuse_fields(['emoji' => 'Give an emoji, or a :shortcode: of the workspace.']); }
sp_guard($pdo, static function () use ($pdo, $c, $m, $me, $emoji): void {
    $pdo->beginTransaction();
    if (toggle_reaction($pdo, (int) $m['message_id'], $me, $emoji, true)) { message_log($pdo, 'message.react', $c, (int) $m['message_id'], ['emoji' => $emoji]); }
    $pdo->commit();
});
message_reply($pdo, (int) $m['message_id'], $c, 'Reacted ' . $emoji, ['emoji' => $emoji]);
