<?php
declare(strict_types=1);
/**
 * GET /admin/settings — the workspace settings and the emoji list (screen `admin-settings`; right settings.manage).
 * POST — action `settings_save` (log `settings.save`: before/after of the fields changed; agent approval `other`): any field of the settings; a number outside the table's bounds is a 422 naming the field;
 * the attachment limit is MB in the form (`max_attachment_mb`) or bytes for an agent; embed hosts one per line, lower-cased, no scheme.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/write.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/present.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    require_right('settings.manage');
    $pdo = db();
    $cur = find_settings($pdo);
    $emoji = find_emoji($pdo);
    log_screen_view($pdo, 'admin-settings');
    if (wants_json()) {
        respond_screen(['settings' => present_settings($cur), 'emoji' => $emoji]);
    }
    $notices = ['saved' => ['success', 'Saved the settings.'], 'emoji_saved' => ['success', 'Saved the emoji.'], 'emoji_deleted' => ['success', 'Removed the emoji.']];
    render_screen('Workspace settings', view('admin/settings.php', ['s' => $cur, 'emoji' => $emoji, 'notice' => sp_notice($_GET['notice'] ?? null, $notices), 'here' => here_url()]),
        ['activeNav' => 'admin-settings', 'screen' => 'admin-settings', 'entity' => 'settings']);
    exit;
}
sp_handler_begin();
require_right('settings.manage');
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$f = settings_from_request($pdo, find_settings($pdo), $errors);
if ($errors !== []) { sp_refuse_fields($errors); }
$res = sp_guard($pdo, static function () use ($pdo, $f, $me): array {
    $pdo->beginTransaction();
    try {
        $r = save_settings($pdo, $f, $me);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23514') { throw new DomainException('One of the settings is outside what is allowed (' . (preg_match('/constraint "sp_settings_([a-z_]+)_check"/', $e->getMessage(), $m) ? str_replace('_', ' ', $m[1]) : 'see the bounds') . ').'); }
        throw $e;
    }
    if ($r['changed'] !== []) { log_activity($pdo, 'settings.save', 'settings', 1, ['before' => $r['before'], 'after' => $r['after']]); }
    $pdo->commit();
    return $r;
});
sp_done($res['changed'] === [] ? 'Nothing changed' : 'Saved the settings (' . implode(', ', array_map(static fn (string $k): string => str_replace('_', ' ', $k), $res['changed'])) . ')', null, sp_land(return_path('/admin/settings'), 'saved', 'settings-form'), 'settingsChanged', ['changed' => $res['changed']]);
