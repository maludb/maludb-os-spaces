<?php
declare(strict_types=1);
/**
 * Action `prefs_save` (log `prefs.save`): a person's own notification choices — email_enabled, text_enabled and digest (yes/no), kinds[] (the events
 * to be told about), text_kinds[] (the events to be texted about), away_minutes (a text only when away this long; empty = the workspace's).
 * A field left out stays as it was. Both channels off is allowed: that person is told nothing but in the app. Always about oneself.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_post();
verify_csrf();
require_login();
$pdo = db();
$me = (int) current_member_id();
$has = static fn (string $k): bool => array_key_exists($k, $_POST);
$yes = static function (string $k): bool {
    $v = strtolower(trim((string) $_POST[$k]));
    if (in_array($v, ['1', 'true', 'on', 'yes'], true)) {
        return true;
    }
    if (in_array($v, ['0', 'false', 'off', 'no', ''], true)) {
        return false;
    }
    refuse(422, 'Say yes or no for ' . str_replace('_', ' ', $k) . '.');
};
$list = static function (string $k): ?array {
    if (!array_key_exists($k, $_POST)) {
        return null;
    }
    $v = $_POST[$k];
    $v = is_array($v) ? $v : explode(',', (string) $v);
    $v = array_values(array_unique(array_filter(array_map(static fn ($x): string => trim((string) $x), $v), static fn (string $x): bool => $x !== '')));
    $bad = array_diff($v, array_keys(NOTICE_KINDS));
    if ($bad !== []) {
        refuse(422, 'Unknown event: ' . implode(', ', array_map(static fn (string $x): string => mb_substr($x, 0, 30), $bad)) . '.');
    }
    return $v;
};
$f = [];
foreach (['email_enabled', 'text_enabled', 'digest'] as $k) {
    if ($has($k)) {
        $f[$k] = $yes($k);
    }
}
if ($has('away_minutes')) {
    $v = trim((string) $_POST['away_minutes']);
    if ($v === '') {
        $f['away_minutes'] = null;
    } elseif (filter_var($v, FILTER_VALIDATE_INT) === false || (int) $v < 1 || (int) $v > 1440) {
        refuse(422, 'Away minutes is a whole number from 1 to 1440, or empty for the workspace\'s.');
    } else {
        $f['away_minutes'] = (int) $v;
    }
}
if (($kinds = $list('kinds')) !== null) {
    $f['kinds'] = $kinds;
}
if (($textKinds = $list('text_kinds')) !== null) {
    $f['text_kinds'] = $textKinds;
}
$current = find_my_prefs($pdo, $me);                                                           // a text may only be for an event the person is told about at all
$notIn = array_values(array_diff($f['text_kinds'] ?? $current['text_kinds'], $f['kinds'] ?? $current['kinds']));
if ($notIn !== []) {
    refuse(422, 'You can only be texted about events you are told about: add ' . implode(', ', array_map(static fn (string $x): string => mb_substr($x, 0, 30), $notIn)) . ' to "Tell me when" first.');
}
$pdo->beginTransaction();
$r = save_prefs($pdo, $me, $f);
log_activity($pdo, 'prefs.save', 'notification_prefs', $me, ['before' => $r['before'], 'after' => $r['after']]);
$pdo->commit();
emit_action_status(true, ['did' => 'Saved how you are told', 'record_id' => $me, 'refresh' => 'prefsChanged', 'email_enabled' => $r['prefs']['email_enabled'], 'text_enabled' => $r['prefs']['text_enabled'], 'digest' => $r['prefs']['digest']]);
$return = safe_local_path($_POST['return_to'] ?? null) ?? '/settings/';
saved_go($return . (str_contains($return, '?') ? '&' : '?') . 'notice=prefs_saved', 'prefsChanged');
