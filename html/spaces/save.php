<?php
declare(strict_types=1);
/**
 * Actions `space_create` (no `space`) / `space_update` (with; log `space.create` / `space.update` — changed fields, never the description's words): a member creates and
 * owns it; an owner updates. A field left out stays. A kind change is its own action (space_kind_set): sent here on an update, it is refused in words.
 */
require_once dirname(__DIR__, 2) . '/app/features/spaces/handler.php';
sp_handler_begin();
require_right('spaces.join');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('space') ?? request_integer('space_id');
$cur = null;
if ($id !== null) {
    $s = find_space($pdo, $id) ?? refuse(404, 'Space not found.');
    require_space_owner($s);
    if ($s['archived_at'] !== null) {
        refuse(422, 'Space "' . $s['name'] . '" is archived: restore it before changing it.');
    }
    $cur = space_state($pdo, $id);
}
$settings = $pdo->query('SELECT default_space_kind, default_member_level, default_everyone_level, wiki_default_verify_months FROM sp_settings WHERE id = 1')->fetch();
$errors = [];
$name = req_has('name') ? trim((string) req_val('name')) : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 80) {
    $errors['name'] = 'Give the space a name of up to 80 characters.';
}
$icon = req_has('icon') ? ((string) req_val('icon') === '' ? null : mb_substr((string) req_val('icon'), 0, 16)) : ($cur['icon'] ?? null);
$description = req_has('description') ? ((string) req_val('description') === '' ? null : mb_substr((string) req_val('description'), 0, 2000)) : ($cur['description'] ?? null);
$kind = $cur['kind'] ?? (string) $settings['default_space_kind'];
if (req_has('kind') && (string) req_val('kind') !== '') {
    $k = (string) req_val('kind');
    if (!isset(SPACE_KINDS[$k])) {
        $errors['kind'] = 'The kind is open, closed or private.';
    } elseif ($cur !== null && $k !== $cur['kind']) {
        $errors['kind'] = 'Change the kind of a space with its own action (space_kind_set): it is confirmed.';
    } else {
        $kind = $k;
    }
}
$memberLevel = $cur['member_level'] ?? (string) $settings['default_member_level'];
if (req_has('member_level') && (string) req_val('member_level') !== '') {
    $memberLevel = (string) req_val('member_level');
    if (!isset(SPACE_LEVELS[$memberLevel])) { $errors['member_level'] = 'The member level is view, comment, edit_content, edit or full.'; }
}
$everyoneLevel = $cur['everyone_level'] ?? ($kind === 'open' ? (string) $settings['default_everyone_level'] : 'none');
if (req_has('everyone_level') && (string) req_val('everyone_level') !== '') {
    $everyoneLevel = (string) req_val('everyone_level');
    if (!isset(SPACE_EVERYONE_LEVELS[$everyoneLevel])) { $errors['everyone_level'] = 'The everyone level is none, view, comment, edit_content or edit.'; }
}
$isWiki = sp_yes('is_wiki', $cur['is_wiki'] ?? false);
$months = sp_int('verify_months', $cur['wiki_default_verify_months'] ?? (int) $settings['wiki_default_verify_months'], 1, 12, 'Verify months', $errors, true) ?? (int) $settings['wiki_default_verify_months'];
if (!in_array($months, WIKI_MONTHS, true)) { $errors['verify_months'] = 'Verify every 1, 3, 6 or 12 months.'; }
if ($errors !== []) {
    sp_refuse_fields($errors);
}
$f = ['name' => $name, 'icon' => $icon, 'description' => $description, 'kind' => $kind, 'member_level' => $memberLevel, 'everyone_level' => $everyoneLevel, 'is_wiki' => $isWiki, 'wiki_default_verify_months' => $months];
$newId = sp_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f): int {
    $pdo->beginTransaction();
    $newId = save_space($pdo, $id, $f, $me);
    $after = space_loggable(space_state($pdo, $newId));
    if ($cur === null) {
        space_log($pdo, 'space.create', $newId, ['after' => $after]);
    } else {
        $d = sp_diff(space_loggable($cur), $after);
        if ($d['after'] !== []) {
            space_log($pdo, 'space.update', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
sp_done(($id === null ? 'Made ' : 'Saved ') . $f['name'], $newId, sp_land(return_path('/spaces/' . $newId), $id === null ? 'created' : 'saved'), 'spaceChanged', ['space_id' => $newId]);
