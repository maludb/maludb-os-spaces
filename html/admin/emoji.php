<?php
declare(strict_types=1);
/** Action `emoji_save` (log `emoji.save`: shortcode; right settings.manage): add an emoji or replace the one with that shortcode (a seeded one may be replaced). Shortcode `^[a-z0-9_+-]{1,40}$`, one emoji of 1 to 16 characters, keywords. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/queries.php';
sp_handler_begin();
require_right('settings.manage');
$pdo = db();
$code = strtolower(trim((string) req_val('shortcode'), ": \t"));
$emoji = (string) req_val('emoji');
$words = array_map(static fn (string $w): string => mb_strtolower(mb_substr($w, 0, 30)), array_slice(request_list('keywords') ?? [], 0, 10));
$errors = [];
if (!preg_match('/^[a-z0-9_+-]{1,40}$/', $code)) { $errors['shortcode'] = 'A shortcode is 1 to 40 of a-z, 0-9, _, + and -.'; }
if ($emoji === '' || mb_strlen($emoji) > 16) { $errors['emoji'] = 'Give one emoji (1 to 16 characters).'; }
if ($errors !== []) { sp_refuse_fields($errors); }
$had = (bool) one_value($pdo, 'SELECT EXISTS (SELECT 1 FROM emoji_shortcodes WHERE shortcode = ' . $pdo->quote($code) . ')');
sp_guard($pdo, static function () use ($pdo, $code, $emoji, $words): void {
    $pdo->beginTransaction();
    save_emoji($pdo, $code, $emoji, $words);
    log_activity($pdo, 'emoji.save', 'emoji', null, ['after' => ['shortcode' => $code]]);
    $pdo->commit();
});
sp_done(($had ? 'Replaced :' : 'Added :') . $code . ':', null, sp_land(return_path('/admin/settings'), 'emoji_saved', 'emoji-list'), 'emojiChanged', ['shortcode' => $code]);
