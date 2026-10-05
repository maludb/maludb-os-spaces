<?php
declare(strict_types=1);
/**
 * Action `status_set` (log `status.set`): my status line — text (up to 100 characters; empty clears), emoji (up to 16), until (a time in my
 * time zone, or ISO 8601; empty means until cleared; a time in the past is refused). `clear=1` clears it. Always about oneself; an agent may set its own.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$clear = sp_yes('clear');
$text = $clear ? '' : (string) (req_val('text') ?? '');
$emoji = $clear ? '' : (string) (req_val('emoji') ?? '');
$untilRaw = $clear ? '' : (string) (req_val('until') ?? '');
$errors = [];
if (mb_strlen($text) > 100) {
    $errors['text'] = 'A status is up to 100 characters.';
}
if (mb_strlen($emoji) > 16) {
    $errors['emoji'] = 'The emoji is up to 16 characters.';
}
$until = null;
if ($untilRaw !== '' && $text . $emoji !== '') {
    try {
        $dt = new DateTimeImmutable($untilRaw, new DateTimeZone(member_timezone() ?: 'UTC'));
        if ($dt->getTimestamp() <= time()) {
            $errors['until'] = 'Until is a time in the future.';
        } else {
            $until = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
        }
    } catch (Exception) {
        $errors['until'] = 'Until is a date and time (2026-10-05T15:00) or empty.';
    }
}
if ($errors !== []) {
    sp_refuse_fields($errors);
}
$pdo->beginTransaction();
$r = sp_guard($pdo, static fn (): array => set_status($pdo, $me, $text, $emoji, $until));
$cleared = trim($text) === '' && trim($emoji) === '';
log_activity($pdo, 'status.set', 'member', $me, ['before' => $r['before'], 'after' => ['text' => $cleared ? null : trim($text), 'emoji' => $cleared ? null : trim($emoji), 'until' => $until]]);
$pdo->commit();
sp_done($cleared ? 'Cleared your status' : 'Set your status', $me, sp_land(return_path('/settings/'), $cleared ? 'status_cleared' : 'status_set', 'status'), 'statusChanged',
    ['text' => $cleared ? null : trim($text), 'emoji' => $cleared ? null : trim($emoji), 'until' => $until === null ? null : json_ts($until)]);
