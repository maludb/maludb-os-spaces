<?php
declare(strict_types=1);
/** Action `reaction_remove` (log `message.unreact`: emoji): own reaction. */
require_once dirname(__DIR__, 3) . '/app/features/messages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
[$m, $c] = message_from_request($pdo);
$emoji = trim((string) (req_val('emoji') ?? ''));
if (preg_match('/^:?([a-z0-9_+-]+):?$/', $emoji, $x) && ($e = emoji_lookup($pdo, $x[1])) !== null) { $emoji = $e; }
if ($emoji === '') { sp_refuse_fields(['emoji' => 'Say which emoji.']); }
sp_guard($pdo, static function () use ($pdo, $c, $m, $me, $emoji): void {
    $pdo->beginTransaction();
    if (toggle_reaction($pdo, (int) $m['message_id'], $me, $emoji, false)) { message_log($pdo, 'message.unreact', $c, (int) $m['message_id'], ['emoji' => $emoji]); }
    $pdo->commit();
});
message_reply($pdo, (int) $m['message_id'], $c, 'Removed ' . $emoji, ['emoji' => $emoji]);
