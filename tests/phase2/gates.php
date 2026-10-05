<?php
/**
 * Proof: rights per role on the shell's screens and placeholders (403 vs 200), the menu, CSRF, My settings (prefs, status, time zone),
 * the tokens screen, notifications, the trail's visibility, the command bar through the kernel's chat endpoint (a reply, a refusal in
 * the kernel's words, an approval, a navigate), presence, the sidebar's lazy children, and the action-token / run-token gate — an agent
 * kept off the person-only screens (sso-shell.md "The shell", "Handlers", "Proof: gates"). Run after sso.php through tests/phase2/run.sh.
 */
require __DIR__ . '/lib.php';
$L = 'https://app.example.invalid/launcher?app=spaces';
$json = ['headers' => ['Accept: application/json']];

echo "1. Rights per role — every screen and placeholder answers 200 or 403\n";
// route => the roles that get 200; the roles are admin (the owner 1), owner (Marco 27), member (Priya 26), guest (Ann 29).
$all = 'admin owner member guest';
$matrix = [
    '/' => $all, '/activity' => $all, '/saved' => $all, '/search' => $all, '/pages/' => $all, '/channels/' => $all, '/dm/' => $all,
    '/settings/' => $all, '/settings/tokens/' => $all, '/notifications' => $all, '/trail' => $all,
    '/spaces/' => 'admin owner member',
    '/admin/settings' => 'admin', '/admin/spaces' => 'admin', '/admin/published' => 'admin', '/admin/retention' => 'admin', '/admin/trash' => 'admin',
    '/admin/agents' => 'admin', '/admin/connections' => 'admin', '/exports/' => 'admin', '/proposals/' => 'admin owner member',   // slice 7: the proposals are every member's (the view hides what they may not see)
];
[$jAdmin, ] = sign_on(1);
[$jOwner, ] = sign_on(27);
[$jMember, ] = sign_on(26);
[$jGuest, ] = sign_on(29);
$who = ['admin' => $jAdmin, 'owner' => $jOwner, 'member' => $jMember, 'guest' => $jGuest];
$wrong = [];
$n = 0;
foreach ($matrix as $route => $allowed) {
    foreach ($who as $role => $jarf) {
        $want = in_array($role, explode(' ', $allowed), true) ? 200 : 403;
        $code = page($jarf, $route)['code'];
        $n++;
        if ($code !== $want) { $wrong[] = "$role $route wanted $want got $code"; }
    }
}
ok($wrong === [], "$n role × screen checks (" . count($matrix) . ' screens × 4 roles) answer 200 or 403 as the rights say' . ($wrong ? ': ' . implode('; ', array_slice($wrong, 0, 8)) : ''));
$r = page($jMember, '/admin/settings');
ok(str_contains($r['body'], 'You may not change the workspace settings.'), 'a refusal says what the person may not do, in words ("You may not change the workspace settings.")');
ok(str_contains(page($jGuest, '/spaces/')['body'], 'You may not join spaces.'), 'a guest on Spaces: "You may not join spaces."');
$stubs = trim((string) shell_exec('grep -rl "render_nav_stub(" ' . escapeshellarg(dirname(__DIR__, 2) . '/html') . ' 2>/dev/null | wc -l'));
ok((int) $stubs >= 1, "the placeholders stand until their slices ship: $stubs controllers name their slice");
ok(str_contains(page($jAdmin, '/admin/trash')['body'], 'slice 9 builds this screen') && str_contains(page($jAdmin, '/exports/')['body'], 'slice 8 builds this screen'), 'each placeholder names its slice (trash 9, exports 8; proposals, agents and connections are real since slice 7; search and activity are real since slice 6)');
ok(req('POST', '/admin/trash', ['jar' => $jAdmin, 'form' => ['csrf_token' => page_csrf($jAdmin)]])['code'] === 501, 'a POST to a placeholder: 501');
$menu = fn (string $j): array => (preg_match_all('/id="nav-([a-z-]+)"/', page($j, '/')['body'], $m) ? $m[1] : []);
$groups = fn (string $j): array => (preg_match_all('/nxl-caption"><label>([^<]+)</', page($j, '/')['body'], $m) ? $m[1] : []);
ok($groups($jMember) === ['Browse', 'Me'] && in_array('spaces', $menu($jMember), true) && in_array('dms', $menu($jMember), true) && in_array('trail', $menu($jMember), true) && !in_array('admin-settings', $menu($jMember), true), 'a Member sees Browse (Spaces, Pages, Channels, DMs) and Me: ' . implode(', ', $groups($jMember)));
ok($groups($jGuest) === ['Browse', 'Me'] && !in_array('spaces', $menu($jGuest), true) && in_array('pages', $menu($jGuest), true) && in_array('dms', $menu($jGuest), true), 'a Guest sees Browse without Spaces, and Me');
ok($groups($jAdmin) === ['Browse', 'Me', 'Admin'] && in_array('admin-settings', $menu($jAdmin), true) && in_array('admin-retention', $menu($jAdmin), true) && in_array('admin-exports', $menu($jAdmin), true), 'the admin sees the Admin group too: ' . implode(', ', $groups($jAdmin)));
ok(in_array('home', $menu($jMember), true) && in_array('activity', $menu($jMember), true) && in_array('saved', $menu($jMember), true) && in_array('search', $menu($jMember), true), 'Home · Activity · Saved · Search head the sidebar');
$r = page(jar(), '/spaces/');
ok($r['code'] === 302 && $r['location'] === $L, 'no session: a screen goes to the launcher with ?app=spaces');
$r = req('GET', '/spaces/', $json);
ok($r['code'] === 401 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'unauthorized', 'no session, JSON: 401 unauthorized');
$r = req('GET', '/', ['jar' => $jOwner] + $json);
$d = json_decode($r['body'], true)['data'] ?? [];
ok($r['code'] === 200 && $d['may']['member'] === true && $d['may']['owner'] === true && $d['may']['admin'] === false && array_key_exists('unread', $d) && array_key_exists('mentions', $d) && array_key_exists('recent_pages', $d) && array_key_exists('verification_due', $d) && isset($d['sidebar']['spaces']), 'the home answers JSON: the shape (may, unread, mentions, recent_pages, verification_due, sidebar …)');
$h = page($jMember, '/')['body'];
ok(str_contains($h, 'id="home-unread"') && str_contains($h, 'id="home-recent"') && str_contains($h, 'id="home-spaces"') && !str_contains($h, 'id="home-joins"') && !str_contains($h, 'id="home-admin"'), 'a Member\'s home: Unread (slice 4), Recently edited (slice 2), Your spaces; no join requests, nothing for the admin');
ok(str_contains(page($jOwner, '/')['body'], 'id="home-joins"') && str_contains(page($jAdmin, '/')['body'], 'id="home-admin"'), 'a Space owner\'s home shows Requests to join (slice 1); the admin\'s For the admin (slice 9)');
ok(preg_match('/id="home-space-\d+"/', $h) === 1 && str_contains($h, 'General'), 'Your spaces lists General');

echo "2. My settings — how I am told, my status, my time zone\n";
$t = csrf_of(page($jMember, '/')['body']);
$d = json_decode(page($jMember, '/settings/', $json)['body'], true)['data'];
ok($d['notify']['email_enabled'] === true && $d['notify']['text_enabled'] === false && $d['notify']['digest'] === false && $d['notify']['away_minutes'] === null && $d['notify']['saved'] === false && in_array('mention', $d['notify']['kinds'], true) && $d['notify']['text_kinds'] === ['dm', 'mention'], 'the defaults before any save: email on, text off, no digest, the default kinds, texts for dm and mention');
ok($d['status'] === null && $d['timezone'] === 'UTC', 'no status yet; the time zone is the directory\'s (UTC)');
$r = req('POST', '/settings/prefs.php', ['jar' => $jMember, 'form' => ['email_enabled' => 'yes', 'text_enabled' => 'yes', 'kinds' => ['mention', 'reply'], 'text_kinds' => ['mention']]]);
ok($r['code'] === 403, 'saving without the CSRF token: 403');
$since = last_activity_id();
$r = req('POST', '/settings/prefs.php', ['jar' => $jMember, 'form' => ['email_enabled' => 'yes', 'text_enabled' => 'yes', 'digest' => 'yes', 'away_minutes' => '30', 'kinds' => ['mention', 'reply'], 'text_kinds' => ['mention'], 'csrf_token' => $t, 'return_to' => '/settings/?tab=notify']]);
ok($r['code'] === 302 && str_starts_with($r['location'], '/settings/?tab=notify') && str_contains($r['location'], 'notice=prefs_saved'), 'with it: 302 back to the settings with the notice');
$row = q('SELECT email_enabled, text_enabled, kinds, text_kinds, digest, away_minutes FROM notification_prefs WHERE member_id = 26')[0] ?? null;
ok($row && $row['text_enabled'] && $row['digest'] && (int) $row['away_minutes'] === 30 && $row['kinds'] === '{mention,reply}' && $row['text_kinds'] === '{mention}', 'the row holds the choices (text on, digest, 30 away minutes, two kinds, one text kind)');
$log = activity('prefs.save', $since);
ok(count($log) === 1 && str_contains((string) $log[0]['after'], 'digest') && str_contains((string) $log[0]['before'], 'text_enabled'), 'prefs.save logs before and after of the changed keys');
$r = req('POST', '/settings/prefs.php', ['jar' => $jMember, 'form' => ['kinds' => ['mention', 'nonsense'], 'csrf_token' => $t]]);
ok($r['code'] === 422 && str_contains($r['body'], 'Unknown event: nonsense'), 'an unknown event kind: 422 in words');
$r = req('POST', '/settings/prefs.php', ['jar' => $jMember, 'form' => ['text_enabled' => 'maybe', 'csrf_token' => $t]]);
ok($r['code'] === 422, 'a yes/no field that is neither: 422');
$r = req('POST', '/settings/prefs.php', ['jar' => $jMember, 'form' => ['away_minutes' => '5000', 'csrf_token' => $t]]);
ok($r['code'] === 422 && str_contains($r['body'], '1 to 1440'), 'away minutes out of range: 422');
$r = req('POST', '/settings/prefs.php', ['jar' => $jMember, 'form' => ['text_enabled' => 'no', 'away_minutes' => '', 'csrf_token' => $t]]);
ok($r['code'] === 302 && q('SELECT text_enabled, kinds, digest, away_minutes FROM notification_prefs WHERE member_id = 26')[0] === ['text_enabled' => false, 'kinds' => '{mention,reply}', 'digest' => true, 'away_minutes' => null], 'a field left out stays as it was (text off, the kinds and digest kept, away minutes back to the workspace\'s)');
ok(str_contains(page($jMember, '/settings/')['body'], 'id="prefs-text-hint"') && str_contains(page($jMember, '/settings/')['body'], 'id="settings-timezone-value"'), 'the settings screen shows where texts go (the OS channels link) and the time zone');
$r = req('POST', '/settings/prefs.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['email_enabled' => 'yes', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['headers'], 'HX-Location') && str_contains($r['headers'], 'prefsChanged'), 'under HTMX: HX-Location to the settings and the prefsChanged trigger');
// the status line
$since = last_activity_id();
$r = req('POST', '/settings/status.php', ['jar' => $jMember, 'form' => ['text' => 'In a meeting', 'emoji' => '📅', 'until' => '', 'csrf_token' => $t]]);
ok($r['code'] === 302 && str_contains($r['location'], 'notice=status_set') && q('SELECT status_text, status_emoji, status_until FROM members WHERE id = 26')[0] === ['status_text' => 'In a meeting', 'status_emoji' => '📅', 'status_until' => null], 'status_set: 302 with the notice; text, emoji, no until');
$h = page($jMember, '/')['body'];
ok(str_contains($h, 'id="header-status"') && str_contains($h, '📅 In a meeting'), 'the header shows my status line');
$log = activity('status.set', $since);
ok(count($log) === 1 && str_contains((string) $log[0]['after'], 'In a meeting'), 'status.set is logged');
$d = json_decode(page($jMember, '/settings/', $json)['body'], true)['data'];
ok($d['status']['text'] === 'In a meeting' && $d['status']['emoji'] === '📅' && $d['status']['until'] === null, 'the settings JSON carries it');
$r = req('POST', '/settings/status.php', ['jar' => $jMember, 'form' => ['text' => str_repeat('x', 101), 'csrf_token' => $t]]);
ok($r['code'] === 422 && str_contains($r['body'], 'up to 100 characters'), 'a status over 100 characters: 422');
$r = req('POST', '/settings/status.php', ['jar' => $jMember, 'form' => ['text' => 'Gone', 'until' => '2020-01-01T10:00', 'csrf_token' => $t]]);
ok($r['code'] === 422 && str_contains($r['body'], 'in the future'), 'an until in the past: 422');
$r = req('POST', '/settings/status.php', ['jar' => $jMember, 'form' => ['text' => 'Gone', 'until' => 'next tuesday-ish', 'csrf_token' => $t]]);
ok($r['code'] === 422, 'an until that is not a time: 422');
$soon = (new DateTimeImmutable('+2 hours'))->format('Y-m-d\TH:i');
$r = req('POST', '/settings/status.php', ['jar' => $jMember, 'headers' => ['Accept: application/json'], 'form' => ['text' => 'Out', 'emoji' => '🚶', 'until' => $soon, 'csrf_token' => $t]]);
$d = json_decode($r['body'], true);
ok($r['code'] === 200 && $d['ok'] === true && $d['did'] === 'Set your status' && $d['until'] !== null && one('SELECT status_until FROM members WHERE id = 26') !== null, 'with an until two hours away, as JSON: ok, did, until set');
$r = req('POST', '/settings/status.php', ['jar' => $jMember, 'form' => ['clear' => '1', 'csrf_token' => $t]]);
ok($r['code'] === 302 && str_contains($r['location'], 'notice=status_cleared') && q('SELECT status_text, status_emoji, status_until FROM members WHERE id = 26')[0] === ['status_text' => null, 'status_emoji' => null, 'status_until' => null], 'clear=1 clears all three');
ok(!str_contains(page($jMember, '/')['body'], 'id="header-status"'), 'and the header shows none');
pdo()->exec("UPDATE members SET status_text = 'Lapsed', status_until = now() - interval '1 minute' WHERE id = 26");
ok(!str_contains(page($jMember, '/')['body'], 'id="header-status"'), 'a status whose until has passed is not shown');
pdo()->exec("UPDATE members SET status_text = NULL, status_until = NULL WHERE id = 26");

echo "3. CSRF and the tokens screen\n";
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jMember, 'form' => ['label' => 'proof']]);
ok($r['code'] === 403, 'minting a token without the CSRF token: 403');
$before = (int) one('SELECT count(*) FROM mcp_access_tokens WHERE member_id = 26');
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jMember, 'form' => ['label' => 'proof', 'csrf_token' => $t]]);
ok($r['code'] === 302 && $r['location'] === '/settings/tokens/', 'with it: 302 to the tokens screen');
$r = page($jMember, '/settings/tokens/');
preg_match('/id="tokens-minted-value">(mcp_[0-9a-f]{48})</', $r['body'], $m);
$raw = $m[1] ?? '';
ok($raw !== '' && !str_contains(page($jMember, '/settings/tokens/')['body'], $raw), 'the new token is shown once (mcp_ + 48 hex) and never again');
$row = q('SELECT id, token_hash, label, scope FROM mcp_access_tokens WHERE member_id = 26 ORDER BY id DESC LIMIT 1')[0];
ok($row['token_hash'] === hash('sha256', $raw) && $row['token_hash'] !== $raw && $row['scope'] === 'mcp' && (int) one('SELECT count(*) FROM mcp_access_tokens WHERE member_id = 26') === $before + 1, 'only its SHA-256 is stored; scope mcp');
$logged = q("SELECT after::text AS a FROM activity_log WHERE action = 'token.mint' ORDER BY id DESC LIMIT 1")[0]['a'] ?? '';
ok($logged !== '' && !str_contains($logged, $raw) && str_contains($logged, 'proof'), 'token.mint logs the label, never the token');
$mine = json_decode(page($jMember, '/settings/tokens/', $json)['body'], true)['data']['tokens'];
ok($mine !== [] && !isset($mine[0]['token_hash']) && !str_contains(json_encode($mine), $raw) && isset($mine[0]['token_id']), 'the tokens screen\'s JSON lists tokens without the value or the hash');
pdo()->exec("SELECT set_config('app.member_id', '26', false)");
$resolved = q("SELECT * FROM mcp_resolve_token(:h, 'mcp')", ['h' => hash('sha256', $raw)]);
ok(count($resolved) === 1 && (int) $resolved[0]['member_id'] === 26 && $resolved[0]['member_kind'] === 'human', 'mcp_resolve_token() resolves the minted token to Priya (what the records server will do in Phase 4)');
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jMember, 'form' => ['label' => 'x', 'scope' => 'other', 'csrf_token' => $t]]);
ok($r['code'] === 422, 'a scope that is neither mcp nor api: 422');
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jMember, 'form' => ['label' => str_repeat('x', 81), 'csrf_token' => $t]]);
ok($r['code'] === 422, 'a label over 80 characters: 422');
$id = (int) $row['id'];
$r = req('POST', '/settings/tokens/revoke.php', ['jar' => $jOwner, 'form' => ['token' => $id, 'csrf_token' => csrf_of(page($jOwner, '/')['body'])]]);
ok($r['code'] === 404 && one('SELECT revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $id]) === null && str_contains($r['body'], 'Token not found.'), 'another person cannot revoke it: 404 "Token not found.", still live');
$r = req('POST', '/settings/tokens/revoke.php', ['jar' => $jMember, 'form' => ['token' => $id, 'csrf_token' => $t]]);
ok($r['code'] === 302 && one('SELECT revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $id]) !== null, 'the owner revokes it: 302, revoked_at set');
ok(count(q("SELECT 1 FROM activity_log WHERE action = 'token.revoke' AND entity_id = :i", ['i' => $id])) === 1, 'token.revoke is logged');
ok(q("SELECT * FROM mcp_resolve_token(:h, 'mcp')", ['h' => hash('sha256', $raw)]) === [], 'and the revoked token no longer resolves');
$r = req('POST', '/settings/tokens/revoke.php', ['jar' => $jMember, 'form' => ['token' => $id, 'csrf_token' => $t]]);
ok($r['code'] === 404, 'revoking it again: 404');

echo "4. Notifications\n";
ok(str_contains(page($jMember, '/notifications')['body'], 'id="notifications-empty"') && !str_contains(page($jMember, '/')['body'], 'id="header-bell-count"'), 'the empty shape ("Nothing yet"); no count on the bell');
$general = (int) one("SELECT id FROM spaces WHERE is_default LIMIT 1");
pdo()->exec("INSERT INTO notifications (member_id, kind, record_type, record_id, title, body) VALUES (26, 'share', 'space', $general, 'SMOKE Welcome was shared with you', 'by Marco'), (26, 'join_decided', 'space', $general, 'SMOKE You joined General', null), (27, 'join_request', 'space', $general, 'SMOKE for Marco', null)");
$h = page($jMember, '/')['body'];
ok(preg_match('/id="header-bell-count">2</', $h) === 1, 'two unread: the bell shows 2');
$d = json_decode(page($jMember, '/notifications', $json)['body'], true)['data'];
ok(count($d['notifications']) === 2 && $d['notifications'][0]['read'] === false && $d['notifications'][0]['record_type'] === 'space' && !str_contains(json_encode($d), 'for Marco'), 'the screen lists her two with their records, never Marco\'s');
ok(str_contains(page($jMember, '/notifications')['body'], 'href="/spaces/' . $general . '?back='), 'a notification links to its record (the space)');
$nid = (int) one("SELECT id FROM notifications WHERE member_id = 26 AND kind = 'share'");
$since = last_activity_id();
$r = req('POST', '/settings/notifications/read.php', ['jar' => $jMember, 'form' => ['notification' => $nid, 'csrf_token' => $t]]);
ok($r['code'] === 302 && one('SELECT read_at FROM notifications WHERE id = :i', ['i' => $nid]) !== null && preg_match('/id="header-bell-count">1</', page($jMember, '/')['body']) === 1, 'marking one read: 302, read_at set, the bell shows 1');
$mid = (int) one("SELECT id FROM notifications WHERE member_id = 27");
$r = req('POST', '/settings/notifications/read.php', ['jar' => $jMember, 'form' => ['notification' => $mid, 'csrf_token' => $t]]);
ok($r['code'] === 404 && one('SELECT read_at FROM notifications WHERE id = :i', ['i' => $mid]) === null, 'marking Marco\'s: 404, nothing changes (own rows only; the view hides it)');
$r = req('POST', '/settings/notifications/read.php', ['jar' => $jMember, 'form' => ['csrf_token' => $t]]);
ok($r['code'] === 302 && (int) one('SELECT count(*) FROM notifications WHERE member_id = 26 AND read_at IS NULL') === 0 && count(activity('notification.read', $since)) === 2, 'no id marks all of hers read; notification.read logged each time one was changed (Marco\'s attempt is a 404, not a row)');
ok(count(json_decode(page($jMember, '/notifications?unread=1', $json)['body'], true)['data']['notifications']) === 0, '?unread=1 now lists none');
$r = page($jMember, '/notifications?count=1', ['headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && str_starts_with(trim($r['body']), '<span id="header-bell-count-wrap"') && !str_contains($r['body'], '<html'), 'the bell re-fetches its count alone (Pattern A)');

echo "5. The trail — the caller's own rows, or a record's by its key\n";
$rows = json_decode(page($jMember, '/trail', $json)['body'], true)['data']['rows'] ?? [];
ok($rows !== [] && count(array_unique(array_map(fn ($x) => $x['actor']['member_id'], $rows))) === 1 && $rows[0]['actor']['member_id'] === 26, 'Priya sees only her own rows (' . count($rows) . ', all by member 26)');
ok(str_contains(json_encode($rows), 'changed how they are told') && str_contains(json_encode($rows), 'made an access token'), 'each row has its sentence');
$rows = json_decode(page($jAdmin, '/trail?action=member.', $json)['body'], true)['data']['rows'] ?? [];
ok($rows !== [] && count(array_unique(array_map(fn ($x) => $x['actor']['member_id'], $rows))) === 1 && $rows[0]['actor']['member_id'] === 1, 'the trail is the member\'s OWN by default — even the super-admin\'s shows only their rows here');
$r = page($jMember, '/trail');
ok($r['code'] === 200 && str_contains($r['body'], 'id="trail-table"'), 'the screen renders (Pattern B table)');
$r = page($jMember, '/trail?period=7', ['headers' => ['HX-Request: true', 'HX-Target: trail-results']]);
ok($r['code'] === 200 && str_starts_with(trim($r['body']), '<div id="trail-results"') && !str_contains($r['body'], '<html'), 'the filter swaps only #trail-results');
pdo()->exec("INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, space_id, after) VALUES (27, 'web', 'space.update', 'space', $general, $general, '{\"name\": \"General\"}')");
$rows = json_decode(page($jOwner, '/trail?space=' . $general, $json)['body'], true)['data']['rows'] ?? [];
ok($rows !== [] && $rows[0]['space_id'] === $general && $rows[0]['action'] === 'space.update', '?space= answers that space\'s rows (by the key)');
ok((json_decode(page($jGuest, '/trail?space=' . $general, $json)['body'], true)['data']['rows'] ?? []) === [], 'and nothing for a guest the view does not admit to it');
ok(page($jMember, '/activity')['code'] === 200 && str_contains(page($jMember, '/activity')['body'], 'id="activity-header"') && !str_contains(page($jMember, '/activity')['body'], 'builds this screen'), '/activity (the feed) is real since slice 6');

echo "6. The command bar goes through the kernel's chat endpoint\n";
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'What changed in General today?', 'screen' => 'home', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['body'], 'Hello from the fake expert'), 'the utterance is answered with the kernel\'s reply');
$a = activity('assistant.ask', 0);
$la = json_decode((string) end($a)['after'], true);
ok($a !== [] && ($la['length'] ?? 0) === 30 && ($la['run_id'] ?? 0) === 1 && !str_contains((string) end($a)['after'], 'General today'), 'assistant.ask logs the length and the run id, never the text');
$chatLines = array_values(array_filter(explode("\n", (string) file_get_contents(need('FAKE_KERNEL_STATE') . '.chat'))));
$chatLog = json_decode((string) end($chatLines), true);
ok(($chatLog['agent'] ?? '') === 'expert' && (int) ($chatLog['acting'] ?? 0) === 26, 'the kernel saw agent=expert and X-Acting-Member 26');
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'form' => ['utterance' => 'hi']]);
ok($r['code'] === 403, 'without the CSRF token: 403');
kernel_state(function ($s) { $s['chat_status'] = 404; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'hi', 'csrf_token' => $t]]);
ok($r['code'] === 404 && str_contains($r['body'], 'No expert for this application.'), 'the kernel answers 404: the bar shows the kernel\'s words');
kernel_state(function ($s) { $s['chat_status'] = 409; $s['chat_message'] = 'The expert is busy with another turn.'; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'hi', 'csrf_token' => $t]]);
ok($r['code'] === 409 && str_contains($r['body'], 'busy with another turn'), 'a 409: shown in the kernel\'s words');
kernel_state(function ($s) { unset($s['chat_status'], $s['chat_message']); $s['chat'] = ['run_id' => 2, 'status' => 'awaiting_approval', 'finished' => true, 'reply' => 'I made the page; publishing it waits for approval.',
    'actions' => [['tool' => 'page_publish', 'status' => 'awaiting_approval', 'record_id' => 5], ['tool' => 'page_create', 'status' => 'ok', 'record_id' => 5]], 'approval_request_id' => 77]; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'publish', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['body'], 'waits for approval') && str_contains($r['body'], 'request #77') && str_contains($r['body'], 'page_create') && str_contains($r['headers'], 'HX-Trigger: pageChanged'), 'a paused action is shown as "waits for approval"; the succeeded one fires pageChanged');
kernel_state(function ($s) { $s['chat'] = ['run_id' => 3, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Opening your tokens.', 'actions' => [], 'navigate' => '/settings/tokens/']; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'open my tokens', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['headers'], 'HX-Location') && str_contains($r['headers'], '/settings/tokens/') && str_contains($r['body'], 'id="assistant-reply-navigate"'), 'a navigate answer is followed (HX-Location to /settings/tokens/)');
kernel_state(function ($s) { $s['chat'] = ['run_id' => 4, 'status' => 'succeeded', 'finished' => true, 'reply' => 'x', 'actions' => [], 'navigate' => 'https://evil.invalid/x']; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'go', 'csrf_token' => $t]]);
ok($r['code'] === 200 && !str_contains($r['headers'], 'HX-Location'), 'a navigate that is not a local path is ignored');
kernel_state(function ($s) { unset($s['chat']); return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => str_repeat('x', 2100), 'csrf_token' => $t]]);
ok($r['code'] === 422, 'an utterance over 2,000 characters: 422');
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'hi', 'csrf_token' => $t]]);
ok($r['code'] === 200, 'fake kernel restored');

echo "7. Presence and the sidebar's lazy children\n";
pdo()->exec("UPDATE members SET last_seen_at = now() - interval '10 minutes' WHERE id = 26");
$r = req('POST', '/presence.php', ['jar' => $jMember, 'form' => []]);
ok($r['code'] === 403, 'the heartbeat without the CSRF token: 403');
$r = req('POST', '/presence.php', ['jar' => $jMember, 'form' => ['csrf_token' => $t]]);
ok($r['code'] === 204 && one("SELECT last_seen_at > now() - interval '1 minute' FROM members WHERE id = 26"), 'with it: 204 and last_seen_at is now');
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
ok(one('SELECT is_active_now FROM mcp_members WHERE member_id = 26') === true, 'mcp_members.is_active_now says she is active (within 5 minutes)');
pdo()->exec("UPDATE members SET last_seen_at = now() - interval '10 minutes' WHERE id = 26");
ok(one('SELECT is_active_now FROM mcp_members WHERE member_id = 26') === false, 'and not after 10 minutes');
[$jFresh, ] = sign_on(26);
pdo()->exec("UPDATE members SET last_seen_at = now() - interval '10 minutes' WHERE id = 26");
page($jFresh, '/');
ok(one("SELECT last_seen_at > now() - interval '1 minute' FROM members WHERE id = 26") === true, 'any signed-in request sets it (once a minute per session)');
pdo()->exec("UPDATE members SET last_seen_at = now() - interval '10 minutes' WHERE id = 26");
page($jFresh, '/');
ok(one("SELECT last_seen_at > now() - interval '1 minute' FROM members WHERE id = 26") === false, 'and a second request within the minute does not touch it again (one UPDATE a minute)');
ok(req('GET', '/pages/children.php?page=0f3a5b7c-1111-4222-8333-444455556666', ['jar' => $jMember])['code'] === 404 && req('GET', '/pages/children.php?page=nonsense', ['jar' => $jMember])['code'] === 404, 'children of a page that does not exist or is not visible: 404');

echo "8. Action tokens and run tokens — the gate for the actions server and the MCP servers; an agent stays off the person-only screens\n";
$r = req('GET', '/trail', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26)]]);
$rows = json_decode($r['body'], true)['data']['rows'] ?? [];
ok($r['code'] === 200 && $rows !== [] && $rows[0]['actor']['member_id'] === 26 && !str_contains($r['headers'], 'Set-Cookie'), 'a person\'s action token acts as that member for one request (200, her rows, no Set-Cookie)');
$r = req('GET', '/trail', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26, -5)]]);
ok($r['code'] === 401, 'an expired action token: 401');
$r = req('GET', '/trail', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . (function (string $t): string { return substr($t, 0, -1) . ($t[-1] === '0' ? '1' : '0'); })(person_token(26))]]);
ok($r['code'] === 401, 'a tampered action token: 401');
$r = req('POST', '/settings/tokens/mint.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26)], 'form' => ['label' => 'by action token']]);
$d = json_decode($r['body'], true);
ok($r['code'] === 200 && str_starts_with($d['token'] ?? '', 'mcp_') && ($d['record_id'] ?? 0) > 0 && str_ends_with((string) $d['location'], '#token-row-' . $d['record_id']), 'a write under the action token needs no CSRF token: JSON carries the token once, record_id and a location ending in the id');
$r = req('POST', '/settings/prefs.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26)], 'form' => ['text_enabled' => 'yes']]);
$d = json_decode($r['body'], true);
ok($r['code'] === 200 && $d['ok'] === true && $d['did'] === 'Saved how you are told' && one('SELECT text_enabled FROM notification_prefs WHERE member_id = 26'), 'prefs_save under the action token answers from emit_action_status (ok, did)');
// Seamus (40): an agent the feed admitted — a run token with the relay acts as him; the person-only screens refuse him.
$tok = run_token(40, 77);
$r = req('GET', '/trail', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok]]);
ok($r['code'] === 401, 'a run token WITHOUT the relay: 401');
$r = req('GET', '/trail', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . str_repeat('0', 64)]]);
ok($r['code'] === 401, 'a run token with a wrong relay: 401');
kernel_state(function ($s) { $s['facts']['77'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 77, 'request_id' => 'req-run-77', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$since = last_activity_id();
$r = req('GET', '/trail', ['headers' => as_agent($tok) + ['X-Screen-View: 1']]);
ok($r['code'] === 200, 'Seamus with the relay: 200 (his own trail)');
$r = req('GET', '/trail', ['headers' => array_merge(as_agent($tok), ['X-Screen-View: 1'])]);
$row = q("SELECT source, agent_run_id, request_id FROM activity_log WHERE action = 'screen.view' AND actor_member_id = 40 AND id > :s ORDER BY id DESC LIMIT 1", ['s' => $since])[0] ?? [];
ok(($row['source'] ?? '') === 'agent' && (int) ($row['agent_run_id'] ?? 0) === 77 && ($row['request_id'] ?? '') === 'req-run-77', 'his rows are source agent, run 77, with the run\'s own request id from the kernel');
ok(req('GET', '/', ['headers' => as_agent($tok)])['code'] === 403, 'an agent may not open the person\'s home: 403');
ok(req('POST', '/settings/tokens/mint.php', ['headers' => as_agent($tok), 'form' => ['label' => 'agent']])['code'] === 403, 'nor mint a person\'s token: 403');
ok(req('POST', '/assistant/ask.php', ['headers' => as_agent($tok), 'form' => ['utterance' => 'hi']])['code'] === 403, 'nor use the command bar: 403');
ok(req('GET', '/settings/tokens/', ['headers' => as_agent($tok)])['code'] === 403 && req('GET', '/notifications', ['headers' => as_agent($tok)])['code'] === 403, 'nor open Tokens or Notifications: 403');
$r = req('POST', '/settings/prefs.php', ['headers' => as_agent($tok), 'form' => ['text_enabled' => 'no']]);
ok($r['code'] === 200 && one('SELECT text_enabled FROM notification_prefs WHERE member_id = 40') === false, 'but an agent may save its own notification choices (prefs_save is "own")');
$r = req('POST', '/settings/status.php', ['headers' => as_agent($tok), 'form' => ['text' => 'Thinking', 'emoji' => '🤖']]);
ok($r['code'] === 200 && one('SELECT status_text FROM members WHERE id = 40') === 'Thinking', 'and set its own status (status_set is "own")');
req('POST', '/settings/status.php', ['headers' => as_agent($tok), 'form' => ['clear' => '1']]);
// an agent the feed introduces with no grant: admitted at first contact only when the kernel vouches
kernel_state(function ($s) { $s['incremental'] = incr(['members' => [['id' => 900, 'member_kind' => 'agent', 'display_name' => 'SMOKE Expert agent', 'email' => null, 'business_role' => 'user', 'is_external' => false, 'status' => 'active', 'updated_at' => '2026-01-01T00:00:00Z', 'departments' => []]]], '2026-01-01T00:03:00.000000Z'); return $s; });
sync();
ok(q('SELECT member_kind, capability FROM members WHERE id = 900')[0] === ['member_kind' => 'agent', 'capability' => null], 'the feed introduced an agent (member 900) with no grant yet');
$tok2 = run_token(900, 78);
$r = req('GET', '/trail', ['headers' => as_agent($tok2)]);
ok($r['code'] === 401 && q('SELECT capability FROM members WHERE id = 900')[0]['capability'] === null, 'a run token with the relay but a run the kernel does not vouch for: 401, no admission recorded');
kernel_state(function ($s) { $s['facts']['78'] = ['valid' => true, 'is_agent' => true, 'member_id' => 900, 'run_id' => 78, 'request_id' => 'req-run-78', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$r = req('GET', '/trail', ['headers' => as_agent($tok2)]);
ok($r['code'] === 200 && q('SELECT capability FROM members WHERE id = 900')[0]['capability'] === 'write', 'once the kernel vouches (valid, an agent, this application\'s endpoints): 200 and the admission is recorded');
ok((int) one("SELECT count(*) FROM space_members sm JOIN spaces s ON s.id = sm.space_id WHERE s.is_default AND sm.member_id = 900") === 1, 'and the admitted agent joined General (D4)');
kernel_state(function ($s) { unset($s['incremental'], $s['facts']); return $s; });

echo "9. Health\n";
$r = req('GET', '/api/v1/health');
$h = json_decode($r['body'], true);
ok($r['code'] === 200 && $h['ok'] === true && $h['application'] === 'spaces' && $h['database'] === 'ok', 'GET /api/v1/health: ok, application spaces, database ok');
ok(array_key_exists('ingest_lag', $h) && is_array($h['directory']) && $h['directory']['synced'] === true && $h['directory']['error'] === null, 'it carries ingest_lag and the directory sync state (synced, no error)');
ok(req('POST', '/api/v1/health')['code'] === 405, 'POST to health: 405');
finish();
