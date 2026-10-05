<?php
/** Proof — JSON mode (spec "Proof"): the four actions under a signed action token answer {ok, did, record_id, location, refresh}; every screen of the slice answers JSON with its data; an agent under a run token searches only what its member may see, is not given the bell, and the registry knows the slice (no approvals). */
require __DIR__ . '/lib.php';
$w = wiki_world();
$tok = ['X-Action-Token: ' . person_token(27)];
$ptok = ['X-Action-Token: ' . person_token(26)];
$contract = static fn (array $b): bool => ($b['ok'] ?? false) === true && array_key_exists('record_id', $b) && isset($b['location'], $b['did'], $b['refresh']);

echo "1. The four actions under the action token\n";
[$c, $b] = act_token('/settings/prefs.php', ['digest' => 'no', 'email_enabled' => 'yes'], $ptok);
ok($c === 200 && $contract($b) && $b['refresh'] === 'prefsChanged' && $b['record_id'] === 26, 'prefs_save: the contract, about the token\'s own member');
[$c, $b] = act_token('/settings/prefs.php', ['kinds' => 'mention,nonsense'], $ptok);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid', 'prefs_save: a refusal is 422 {error}');
ok(($b['error']['message'] ?? '') !== '' && str_contains($b['error']['message'], 'nonsense'), '... naming the unknown kind');
[$c, $b] = act_token('/settings/status.php', ['text' => 'SMOKE token status', 'emoji' => '🤖'], $ptok);
ok($c === 200 && $contract($b) && $b['refresh'] === 'statusChanged' && $b['text'] === 'SMOKE token status', 'status_set: the contract');
[$c, $b] = act_token('/settings/status.php', ['clear' => '1'], $ptok);
ok($c === 200 && $contract($b), 'status_set clears by token');
$nid = (int) one('SELECT id FROM notifications WHERE member_id = 26 AND read_at IS NULL ORDER BY id DESC LIMIT 1');
if ($nid === 0) { as_viewer(27); one("SELECT sp_notify(26, 'share', 'SMOKE for the token', NULL)"); $nid = (int) one('SELECT id FROM notifications WHERE member_id = 26 AND read_at IS NULL ORDER BY id DESC LIMIT 1'); }
[$c, $b] = act_token('/settings/notifications/read.php', ['notification' => $nid], $ptok);
ok($c === 200 && $contract($b) && $b['record_id'] === $nid && $b['count'] === 1 && $b['refresh'] === 'notificationChanged', 'notification_read: the contract and the count');
[$c, $b] = act_token('/settings/notifications/read.php', [], $ptok);
ok($c === 200 && $contract($b) && $b['record_id'] === null, 'notification_read (all): record_id null');
[$c, $b] = act_token('/spaces/wiki/nudge.php', ['page' => $w['never'], 'member' => 30], $tok);
ok($c === 200 && $contract($b) && $b['record_id'] === $w['never'] && $b['refresh'] === 'wikiChanged' && is_bool($b['queued']), 'wiki_nudge_send: the contract, record_id the page');
[$c, $b] = act_token('/spaces/wiki/nudge.php', ['page' => $w['never'], 'member' => 30], $tok);
ok($c === 200 && $b['queued'] === false, '... and a second is queued=false');
[$c, $b] = act_token('/spaces/wiki/nudge.php', ['page' => $w['never']], $ptok);
ok($c === 403, 'wiki_nudge_send by a member who does not own the space: 403');
$since = last_activity_id();
act_token('/settings/status.php', ['text' => 'SMOKE x'], $ptok); act_token('/settings/status.php', ['clear' => '1'], $ptok);
$l = activity('status.set', $since);
ok(count($l) === 2 && $l[0]['source'] === 'assistant', 'logged with source assistant (a person\'s token)');

echo "2. Every screen answers JSON\n";
$marco = as_member(27); $priya = as_member(26);
foreach ([['/notifications', ['notifications', 'unread']], ['/activity', ['activity', 'since']], ['/saved', ['saved', 'reminders']], ['/settings/', ['notify', 'kinds', 'status']], ['/search?q=rate+limit', ['results', 'total', 'filters']],
          ['/spaces/' . $w['product'] . '/wiki', ['space', 'pages']], ['/spaces/' . $w['product'] . '/wiki/report', ['lists', 'numbers', 'counts', 'may']]] as [$path, $keys]) {
    [$c, $d] = screen($marco, $path);
    ok($c === 200 && array_diff($keys, array_keys($d)) === [], "$path: 200 and " . implode(', ', $keys));
}
[$c, $d] = screen($priya, '/notifications');
$leak = json_encode($d);
ok(!str_contains($leak, 'dedupe') && !str_contains($leak, 'actor_member_id') && !str_contains($leak, 'read_at'), 'the bell\'s JSON is a whitelist: no dedupe key, no raw columns');
[$c, $d] = screen($priya, '/search?q=rate+limit');
$row = $d['results']['message'][0];
ok(!array_key_exists('rank', $row) && !array_key_exists('total', $row) && !array_key_exists('entity_kind', $row), 'the search rows are a whitelist: no rank, no raw columns');
ok(req('GET', '/notifications', ['jar' => '', 'headers' => JSONH])['code'] === 401, 'signed out, JSON: 401');

echo "3. An agent\n";
$rt = as_agent(run_token(40, 761));
$r = req('GET', '/search?q=' . urlencode('rate limit'), ['headers' => array_merge(JSONH, $rt)]);
$d = json_decode($r['body'], true)['data'] ?? [];
ok($r['code'] === 200 && count($d['results']['message']) >= 2, 'a run token searches: Seamus (in #smoke-launch) finds its messages');
post($marco, $w['leads_channel'], 'SMOKE leads-only rate limit secret two');
$r = req('GET', '/search?q=' . urlencode('secret two'), ['headers' => array_merge(JSONH, $rt)]);
ok($r['code'] === 200 && (json_decode($r['body'], true)['data']['total'] ?? 1) === 0, '... and not what is in a channel its member is not in');
$r = req('GET', '/notifications', ['headers' => array_merge(JSONH, $rt)]);
ok($r['code'] === 403, 'an agent is dispatched, not notified: the bell is for people (403)');
$r = req('GET', '/spaces/' . $w['product'] . '/wiki/report', ['headers' => array_merge(JSONH, $rt)]);
ok($r['code'] === 200 && isset(json_decode($r['body'], true)['data']['lists']), 'an agent reads the wiki\'s reports (the Librarian\'s work)');
$since = last_activity_id();
$r = req('GET', '/search?q=' . urlencode('rate limit'), ['headers' => array_merge(JSONH, $rt, ['X-Screen-View: 1'])]);
$l = activity('screen.view', $since);
ok(count($l) === 1 && $l[0]['source'] === 'agent', 'its search is logged with source agent');

echo "4. The manifest\n";
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$acts = $reg['actions'] ?? [];
foreach (['prefs_save', 'status_set', 'notification_read', 'wiki_nudge_send'] as $a) {
    ok(isset($acts[$a]) && $acts[$a]['built'] === true && ($acts[$a]['approval'] ?? null) === null, "$a: built, no approval");
}
foreach (['notifications', 'activity', 'saved', 'settings', 'search', 'wiki-view', 'wiki-report'] as $s) {
    ok(($reg['screens'][$s]['built'] ?? false) === true && ($reg['screens'][$s]['stub'] ?? true) === false, "screen $s: built");
}
finish();
