<?php
declare(strict_types=1);
/** Action `emoji_delete` (log `emoji.delete`: shortcode; right settings.manage): remove an emoji from the picker. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/queries.php';
sp_handler_begin();
require_right('settings.manage');
$pdo = db();
$code = strtolower(trim((string) req_val('shortcode'), ": \t"));
if ($code === '') { sp_refuse_fields(['shortcode' => 'Say which emoji (its shortcode).']); }
sp_guard($pdo, static function () use ($pdo, $code): void {
    $pdo->beginTransaction();
    delete_emoji($pdo, $code);
    log_activity($pdo, 'emoji.delete', 'emoji', null, ['after' => ['shortcode' => $code]]);
    $pdo->commit();
});
sp_done('Removed :' . $code . ':', null, sp_land(return_path('/admin/settings'), 'emoji_deleted', 'emoji-list'), 'emojiChanged', ['shortcode' => $code]);
