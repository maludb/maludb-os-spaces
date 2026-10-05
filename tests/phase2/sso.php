<?php
/**
 * Proof: the kernel's hand-off under the shell, the mirror (capability + roles), the badges, the guest's sidebar, the Watcher refused,
 * revocation and sign-out (sso-shell.md "The receiver", "Every request", "Proof: sso"). Run through tests/phase2/run.sh.
 */
require __DIR__ . '/lib.php';
$L = 'https://app.example.invalid/launcher?app=spaces';
$refusedWith = fn (int $since): ?string => (($r = activity('member.sign_on.refused', $since)) ? (json_decode((string) end($r)['after'], true)['reason'] ?? null) : null);

echo "1. A hand-off opens a session once\n";
$sinceLog = last_activity_id();
$url = handoff(27);
$jm = jar();
$r = req('GET', $url, ['jar' => $jm]);
ok($r['code'] === 302 && $r['location'] === '/', "the hand-off answers 302 to / ({$r['code']} {$r['location']})");
$m = q('SELECT display_name, capability, status, roles FROM members WHERE id = 27')[0] ?? null;
ok($m && $m['display_name'] === 'SMOKE Marco' && $m['capability'] === 'write' && $m['status'] === 'active', 'the mirror row follows the claims (name, capability write, active)');
ok($m && $m['roles'] === '{space_owner,user}', 'and claims.roles landed in members.roles ({space_owner,user})');
$home = page($jm, '/');
ok($home['code'] === 200 && str_contains($home['body'], 'id="home-header"') && badge_of($jm) === 'Space owner', 'the home answers 200 with the role badge "Space owner"');
ok(str_contains($home['body'], 'id="shell-sidebar"') && str_contains($home['body'], 'id="right-pane"') && str_contains($home['body'], 'id="app-tabbar"') && str_contains($home['body'], 'id="assistant-bar"'), 'the shell is whole: the sidebar tree, the right pane, the tab bar, the command bar');
ok(count(activity('member.sign_on', $sinceLog)) === 1 && str_contains((string) activity('member.sign_on', $sinceLog)[0]['after'], '"roles"'), 'member.sign_on is logged with capability and roles');
ok(one('SELECT last_seen_at FROM members WHERE id = 27') !== null, 'presence: his first signed-in request set last_seen_at');
$r = req('GET', $url, ['jar' => jar()]);
ok($r['code'] === 403 && $refusedWith($sinceLog) === 'replay', "the same URL again: 403, reason logged as replay ({$r['code']})");
ok(!preg_match('/replay|nonce|signature|token|audience/i', preg_replace('/sign-on link|launcher/i', '', $r['body'])), 'and the page never says which check failed');
$r = req('GET', handoff(27, ['app' => 'hr']), ['jar' => jar()]);
ok($r['code'] === 403 && $refusedWith($sinceLog) === 'token', 'a token made for app_key hr: 403 (audience)');
$r = req('GET', handoff(27, ['ttl' => -5]), ['jar' => jar()]);
ok($r['code'] === 403, 'an expired token: 403');
$r = req('GET', handoff(27, ['key' => str_repeat('ab', 32)]), ['jar' => jar()]);
ok($r['code'] === 403, 'a token signed with another key: 403');
$u = handoff(27);
$r = req('GET', preg_replace('/claims=([^&.]+)/', 'claims=x$1', $u), ['jar' => jar()]);
ok($r['code'] === 403 && $refusedWith($sinceLog) === 'claims', 'tampered claims: 403 (claims)');
$r = req('GET', handoff(26, ['claims' => fixture()['claims']['27'] + ['member_id' => 27]]), ['jar' => jar()]);
ok($r['code'] === 403, 'claims of another member beside the token: 403');
$r = req('GET', handoff(27, ['claims' => ['status' => 'suspended'] + fixture()['claims']['27']]), ['jar' => jar()]);
ok($r['code'] === 403 && $refusedWith($sinceLog) === 'status', 'a suspended member: 403 (status)');
$r = req('GET', handoff(27, ['claims' => ['capability' => null] + fixture()['claims']['27']]), ['jar' => jar()]);
ok($r['code'] === 403 && $refusedWith($sinceLog) === 'capability', 'no capability on this application: 403 (capability)');
ok(req('GET', '/sso', ['jar' => jar()])['code'] === 403, '/sso with no token: the one refusal page');
ok(q('SELECT roles FROM members WHERE id = 27')[0]['roles'] === '{space_owner,user}', 'a refused hand-off changed nothing in the mirror');

echo "2. Every fixture member lands on the home with their badge; the guest's sidebar holds Shared and DMs only\n";
$badges = [1 => 'Super-admin', 26 => 'Member', 27 => 'Space owner', 28 => 'Member', 29 => 'Guest', 30 => 'Member'];
$wrong = [];
foreach ($badges as $id => $want) { [$j, $r] = sign_on($id); $b = badge_of($j); if ($r['code'] !== 302 || $b !== $want) { $wrong[] = "$id: {$r['code']} '$b'"; } }
ok($wrong === [], 'six members sign on and wear Super-admin / Member / Space owner / Member / Guest / Member' . ($wrong ? ': ' . implode('; ', $wrong) : ''));
[$jg, ] = sign_on(29);
$h = page($jg, '/')['body'];
ok(str_contains($h, 'id="sidebar-shared-caption"') && str_contains($h, 'id="sidebar-dms-caption"') && !str_contains($h, 'id="sidebar-private-caption"') && !preg_match('/id="sidebar-space-\d+-caption"/', $h) && !str_contains($h, 'id="sidebar-joinable-caption"'), 'Ann (guest): Shared with me and Direct messages, no spaces, no Private, no joinable');
ok(!str_contains($h, 'id="nav-spaces"') && str_contains($h, 'id="nav-dms"') && !str_contains($h, 'id="nav-admin-settings"') && str_contains($h, 'id="nav-my-settings"'), 'and her menu holds no Spaces item and no Admin group');
$h = page($jm, '/')['body'];
ok(preg_match('/id="sidebar-space-(\d+)-caption"/', $h, $mm) === 1 && str_contains($h, 'General') && preg_match('/id="sidebar-channel-\d+"/', $h) === 1, 'Marco\'s sidebar lists General (seeded, db/006) with its channel');
ok(str_contains($h, 'id="sidebar-private-caption"') && str_contains($h, 'id="sidebar-dms-caption"') && str_contains($h, 'id="sidebar-private-new"'), 'with Private (and "+ new private page") and Direct messages');
[$ja, ] = sign_on(1);
$h = page($ja, '/')['body'];
ok(str_contains($h, 'id="nav-admin-settings"') && str_contains($h, 'id="nav-admin-trash"') && str_contains($h, 'id="nav-admin-agents"') && str_contains($h, 'id="nav-admin-proposals"'), 'the admin\'s menu holds the Admin group (settings, trash, agents, proposals)');
ok(!str_contains(page($jm, '/')['body'], 'id="nav-admin-settings"'), 'a Space owner\'s does not');

echo "3. The Watcher (an agent the kernel never vouched for) is refused on the action-token path\n";
ok(q('SELECT capability FROM members WHERE id = 41')[0]['capability'] === null && q('SELECT capability FROM members WHERE id = 40')[0]['capability'] === 'write', 'the feed admitted Seamus (40, access[]) and not the Watcher (41)');
$since = last_activity_id();
$tok = run_token(41, 501);
$r = req('GET', '/trail', ['headers' => as_agent($tok)]);
ok($r['code'] === 401 && q('SELECT capability FROM members WHERE id = 41')[0]['capability'] === null, 'a run token for the Watcher with the relay, no run-facts vouching: 401, no admission');
ok(count(activity('member.refused', $since)) === 1, 'and member.refused is logged');
$r = req('GET', '/trail', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(4242)]]);
ok($r['code'] === 401 && (int) one('SELECT count(*) FROM members WHERE id = 4242') === 0, 'an action token for an id with no mirror row: 401 and NO row created');

echo "4. Roles from the claims — and without them, the capability\n";
[$j1, ] = sign_on(27, ['claims' => ['roles' => ['user']] + fixture()['claims']['27']]);
ok(q('SELECT roles FROM members WHERE id = 27')[0]['roles'] === '{user}' && badge_of($j1) === 'Member', 'a hand-off naming only user: members.roles = {user}, badge "Member"');
[$j2, ] = sign_on(27, ['claims' => ['roles' => ['space_owner', 'user', 'nonsense']] + fixture()['claims']['27']]);
ok(q('SELECT roles FROM members WHERE id = 27')[0]['roles'] === '{space_owner,user}', 'a role sp_roles does not know is dropped ({space_owner,user} from space_owner,user,nonsense)');
$c = fixture()['claims']['1']; unset($c['roles']);
[$j3, ] = sign_on(1, ['claims' => $c]);
ok(in_array(q('SELECT roles FROM members WHERE id = 1')[0]['roles'], ['{}', '{admin}'], true), 'a kernel from before roles sends none: the mirror keeps what it had');
ok(page($j3, '/admin/settings')['code'] === 200 && badge_of($j3) === 'Super-admin', 'the super-admin holds every right (the admin settings 200) and wears the Super-admin badge');
$c = fixture()['claims']['26']; $c['roles'] = []; $c['capability'] = 'admin';
[$j4, ] = sign_on(26, ['claims' => $c]);
ok(q('SELECT roles FROM members WHERE id = 26')[0]['roles'] === '{}' && page($j4, '/admin/settings')['code'] === 200 && badge_of($j4) === 'Spaces admin', 'roles {} with capability admin: every right (admin settings 200), badge "Spaces admin"');
$c['capability'] = 'write';
[$j5, ] = sign_on(26, ['claims' => $c]);
ok(page($j5, '/admin/settings')['code'] === 403 && page($j5, '/spaces/')['code'] === 200 && badge_of($j5) === 'Member', 'roles {} with capability write: a Member (admin settings 403, Spaces 200)');
$c['capability'] = 'read';
[$j6, ] = sign_on(26, ['claims' => $c]);
ok(page($j6, '/spaces/')['code'] === 403 && badge_of($j6) === 'Guest', 'roles {} with capability read: a Guest (Spaces 403)');
sign_on(26);   // restore Priya as the fixture has her

echo "5. Revocation — the next request lands on the launcher\n";
[$jr, ] = sign_on(26);
ok(page($jr, '/')['code'] === 200, 'Priya is signed in');
kernel_state(fn ($s) => $s + ['incremental' => incr(['access' => [['member_id' => 26, 'role' => null, 'roles' => [], 'capability' => null, 'scopes' => []]]], '2026-01-01T00:01:00.000000Z')]);
$out = sync();
ok(str_contains($out, '1 holdings'), "the sync applies Priya's access row with no capability: $out");
$r = page($jr, '/');
ok($r['code'] === 302 && $r['location'] === $L, 'her next request: 302 to the launcher with ?app=spaces');
ok(q("SELECT ended_by FROM member_sessions WHERE member_id = 26 ORDER BY created_at DESC LIMIT 1")[0]['ended_by'] === 'directory', "member_sessions shows ended_by = 'directory'");
ok(q('SELECT capability, roles FROM members WHERE id = 26')[0] === ['capability' => null, 'roles' => '{}'], 'the mirror holds no capability and no roles for her');
$r = req('GET', handoff(26, ['claims' => ['capability' => null, 'roles' => []] + fixture()['claims']['26']]), ['jar' => jar()]);
ok($r['code'] === 403 && $refusedWith($sinceLog) === 'capability', 'and a new hand-off for her, with the claims the kernel would now send, is refused');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [['member_id' => 26, 'role' => 'user', 'roles' => ['user'], 'capability' => 'write', 'scopes' => []]]], '2026-01-01T00:02:00.000000Z'); return $s; });
sync();
ok(q('SELECT capability, roles FROM members WHERE id = 26')[0] === ['capability' => 'write', 'roles' => '{user}'], 'granted again by the feed: capability write, roles {user}');
kernel_state(function ($s) { unset($s['incremental']); return $s; });

echo "6. Sign-out\n";
$notice = fn (int $m, string $app = APP, ?int $issued = null): string => ($p = $m . '.' . ($issued ?? time()) . '.' . $app) . '.' . hash_hmac('sha256', 'sso-logout:' . $p, need('ACTION_TOKEN_KEY'));
[$jk, ] = sign_on(26);
[$jk2, ] = sign_on(26);
ok(page($jk, '/')['code'] === 200 && page($jk2, '/')['code'] === 200, 'Priya holds two sessions');
$since = last_activity_id();
$r = req('POST', '/sso/logout', ['form' => ['notice' => $notice(26)]]);
ok($r['code'] === 204 && $r['body'] === '', 'the kernel\'s sign-out notice: 204, no body');
ok(page($jk, '/')['code'] === 302 && page($jk2, '/')['code'] === 302, 'every session of hers ends: the next requests go to the launcher');
ok(q("SELECT DISTINCT ended_by FROM member_sessions WHERE member_id = 26 AND ended_at IS NOT NULL AND ended_by = 'kernel'") !== [] && count(activity('member.sign_out', $since)) === 1, "ended_by = 'kernel' and member.sign_out logged once");
[$jk3, ] = sign_on(26);
$r = req('POST', '/sso/logout', ['form' => ['notice' => 'garbage']]);
ok($r['code'] === 204 && count(activity('member.sign_out.refused', $since)) === 1, 'an invalid notice: 204 and member.sign_out.refused logged');
$r = req('POST', '/sso/logout', ['form' => ['notice' => $notice(26, 'hr')]]);
ok($r['code'] === 204 && page($jk3, '/')['code'] === 200, 'a notice made for another application: 204 and changes nothing');
$r = req('POST', '/sso/logout', ['form' => ['notice' => $notice(26, APP, time() - 600)]]);
ok($r['code'] === 204 && page($jk3, '/')['code'] === 200, 'a stale notice (10 minutes old): 204 and changes nothing');
$r = req('POST', '/sso/logout', ['raw' => json_encode(['notice' => $notice(26)]), 'headers' => ['Content-Type: application/json']]);
ok($r['code'] === 204 && page($jk3, '/')['code'] === 302, 'the notice as JSON works too');
ok(req('GET', '/sso/logout')['code'] === 405, 'GET /sso/logout: 405');
[$jo, ] = sign_on(27);
$r = req('POST', '/logout.php', ['jar' => $jo, 'form' => []]);
ok($r['code'] === 403 && page($jo, '/')['code'] === 200, 'own sign-out without the CSRF token: 403, still signed in');
$r = req('POST', '/logout.php', ['jar' => $jo, 'form' => ['csrf_token' => csrf_of(page($jo, '/')['body'])]]);
ok($r['code'] === 302 && $r['location'] === 'https://app.example.invalid/launcher' && page($jo, '/')['code'] === 302, 'own sign-out (POST + CSRF): 302 to the launcher, this session ended');
ok(page($jo, '/')['location'] === $L, 'and a signed-out visitor is sent to the launcher with ?app=spaces');
ok(req('GET', '/login.php')['location'] === $L, '/login.php is only a redirect to the launcher (no form, no password)');
finish();
