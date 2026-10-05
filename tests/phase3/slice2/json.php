<?php
/** Proof — JSON mode (spec "Proof", 8): every handler under a signed action token answers the contract; _partial=1 keeps icon and cover; the expert creates and shares. */
require __DIR__ . '/lib.php';
$w = pages_world();
$tok = ['X-Action-Token: ' . person_token(27)];
$adm = ['X-Action-Token: ' . person_token(1)];
$contract = fn (array $b) => ($b['ok'] ?? false) === true && array_key_exists('record_id', $b) && isset($b['location'], $b['did'], $b['refresh']);

echo "1. Every handler under the action token\n";
[$c, $b] = act_token('/pages/save.php', ['title' => 'SMOKE Token page', 'space' => $w['product'], 'icon' => '🔑', 'markdown' => 'words'], $tok);
$tp = (string) ($b['record_id'] ?? '');
ok($c === 200 && $contract($b) && is_uuid($tp) && str_starts_with((string) $b['location'], '/pages/' . $tp), 'page_create: {ok, did, record_id (a UUID), location, refresh}');
[$c, $b] = act_token('/pages/save.php', ['page' => $tp, 'title' => 'SMOKE Token page 2', '_partial' => '1'], $tok);
ok($c === 200 && page_row($tp)['plain_title'] === 'SMOKE Token page 2' && one('SELECT icon FROM pages WHERE id = CAST(:p AS uuid)', ['p' => $tp]) === '🔑', '_partial=1 on page_update: the title changed, the icon kept');
[$c, $b] = act_token('/pages/save.php', ['page' => $tp, 'title' => '', '_partial' => '1'], $tok);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($b['error']['fields']['title']), '422 {error: {code: invalid, fields}}');
[$c, $b] = act_token('/pages/save.php', ['title' => 'SMOKE Token child', 'parent' => $tp], $tok);
$tc = (string) $b['record_id'];
foreach ([['/pages/favorite.php', ['page' => $tp]], ['/pages/favorite.php', ['page' => $tp, 'favorite' => 'no']], ['/pages/lock.php', ['page' => $tp, 'locked' => 'yes']], ['/pages/lock.php', ['page' => $tp, 'locked' => 'no']],
          ['/pages/share.php', ['page' => $tp, 'member' => 26, 'level' => 'view']], ['/pages/restrict.php', ['page' => $tp]], ['/pages/unrestrict.php', ['page' => $tp]], ['/pages/unshare.php', ['page' => $tp, 'member' => 26]],
          ['/pages/share-guest.php', ['page' => $tp, 'guest' => 29, 'level' => 'view']], ['/pages/unshare.php', ['page' => $tp, 'guest' => 29]],
          ['/pages/move.php', ['page' => $tc, 'space' => $w['product']]], ['/pages/move.php', ['page' => $tc, 'parent' => $tp]], ['/pages/duplicate.php', ['page' => $tc]],
          ['/pages/verify.php', ['page' => $tp]], ['/pages/owner.php', ['page' => $tp, 'member' => 26]], ['/pages/template-publish.php', ['page' => $tc, 'template' => 'yes']],
          ['/pages/template-apply.php', ['template' => $tc, 'space' => $w['product'], 'title' => 'SMOKE Applied by token']], ['/pages/template-publish.php', ['page' => $tc, 'template' => 'no']],
          ['/pages/trash.php', ['page' => $tc]], ['/pages/restore.php', ['page' => $tc]], ['/pages/trash.php', ['page' => $tc]], ['/pages/purge.php', ['page' => $tc]]] as [$path, $form]) {
    [$c, $b] = act_token($path, $form, $tok);
    ok($c === 200 && $contract($b), "$path: 200 and the contract" . ($c !== 200 ? ' — ' . msg($b) : ''));
}
foreach ([['/pages/publish.php', ['page' => $tp, 'include_subpages' => 'yes']], ['/pages/publish-rotate.php', ['page' => $tp]], ['/pages/unpublish.php', ['page' => $tp]]] as [$path, $form]) {
    [$c, $b] = act_token($path, $form, $adm);
    ok($c === 200 && $contract($b) && ($path === '/pages/unpublish.php' || str_contains((string) $b['link'], '/p/')), "$path (the admin): 200, the contract" . ($path === '/pages/unpublish.php' ? '' : ', the link in the reply'));
}
act_token('/pages/trash.php', ['page' => $tp], $tok);
[$c, $b] = act_token('/pages/trash-purge.php', ['space' => $w['product']], $adm);
ok($c === 200 && $contract($b) && ($b['count'] ?? 0) >= 1, '/pages/trash-purge.php (the admin): the count');
foreach (['/pages/', '/pages/new', '/pages/' . $w['pricing'], '/pages/' . $w['pricing'] . '/edit', '/pages/' . $w['pricing'] . '/share', '/pages/' . $w['pricing'] . '/move', '/pages/' . $w['pricing'] . '/history', '/pages/trash', '/templates/'] as $path) {
    [$c, $d] = screen(as_member(27), $path);
    ok($c === 200 && $d !== [], "$path answers JSON");
}
[$c, $d] = screen(as_member(1), '/pages/' . $w['pricing'] . '/publish');
ok($c === 200, '/pages/{id}/publish answers JSON to the admin');

echo "2. The expert (a run token with the relay) makes a page in General and shares it to Priya — source agent\n";
kernel_state(function ($s) { $s['facts']['601'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 601, 'request_id' => 'req-601', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$rt = run_token(40, 601);
$since = last_activity_id();
[$c, $b] = act_token('/pages/save.php', ['title' => 'SMOKE Agent page', 'space' => $w['general'], 'markdown' => 'Written by Seamus.'], as_agent($rt));
$ap = (string) ($b['record_id'] ?? '');
ok($c === 200 && is_uuid($ap) && (int) page_row($ap)['owner_member_id'] === 40, 'Seamus makes a page in General');
[$c, $b] = act_token('/pages/share.php', ['page' => $ap, 'member' => 26, 'level' => 'comment'], as_agent($rt));
ok($c === 403, 'it cannot share a General page (edit through the space, not full): 403 — the same rule as a person');
[$c, $b] = act_token('/pages/save.php', ['title' => 'SMOKE Agent private page', 'private' => 'yes'], as_agent($rt));
$app = (string) ($b['record_id'] ?? '');
[$c, $b] = act_token('/pages/share.php', ['page' => $app, 'member' => 26, 'level' => 'comment'], as_agent($rt));
ok($c === 200 && level_of(26, $app) === 'comment' && (int) one("SELECT count(*) FROM notifications WHERE member_id = 26 AND kind = 'share' AND record_uuid = CAST(:p AS uuid)", ['p' => $app]) === 1, 'it makes a private page of its own and shares it to Priya at comment; she is told');
$log = activity('page.create', $since);
ok(count($log) === 2 && $log[0]['source'] === 'agent' && (int) $log[0]['agent_run_id'] === 601 && $log[0]['entity_uuid'] === $ap, 'page.create (twice): source agent, run 601, entity_uuid');
[$c, $b] = act_token('/pages/share-guest.php', ['page' => $ap, 'guest' => 29, 'level' => 'view'], as_agent($rt));
ok($c === 403, 'share_guest by the agent (no share.guest): 403 here; the pause (external_send) is the kernel\'s on the MCP path');
kernel_state(function ($s) { unset($s['facts']); return $s; });
finish();
