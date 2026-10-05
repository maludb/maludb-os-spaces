<?php
/**
 * Proof: the directory sync (bin/directory_sync.php) against the fake kernel — the full pass applies the fixture (members, departments,
 * access[] roles, General's membership, the standing departments' spaces), the incremental pass applies nothing and moves the cursor,
 * a role change reaches members.roles and the badge in one pass, an access row with capability null ends the member's sessions, a
 * suspended member's sessions end, a department delivered seeds its space, a new member admitted lands in General, --from-file, and
 * a failing kernel is reported (sso-shell.md "Proof: sync"). Run after gates.php.
 */
require __DIR__ . '/lib.php';
$state = fn () => q('SELECT * FROM directory_sync_state WHERE id = 1')[0];
$L = 'https://app.example.invalid/launcher?app=spaces';
$general = (int) one('SELECT id FROM spaces WHERE is_default LIMIT 1');

echo "1. The full pass\n";
ok((int) one('SELECT count(*) FROM members WHERE capability IS NOT NULL AND id IN (1, 26, 27, 28, 29, 30, 31, 40)') === 8 && (int) one('SELECT count(*) FROM departments') === 4, 'the run.sh full pass admitted the eight fixture members (seven people and Seamus) and the four departments');
ok(q('SELECT roles FROM members WHERE id = 27')[0]['roles'] === '{space_owner,user}' && q('SELECT roles FROM members WHERE id = 40')[0]['roles'] === '{user}' && q('SELECT roles FROM members WHERE id = 29')[0]['roles'] === '{guest}', 'access[] roles landed (Marco space_owner,user; Seamus user; Ann guest alone)');
$admitted = (int) one("SELECT count(*) FROM members m WHERE m.capability IS NOT NULL AND m.status = 'active' AND NOT sp_member_is_guest(m.id)");
ok($general > 0 && $admitted >= 8 && (int) one('SELECT count(*) FROM space_members WHERE space_id = :g', ['g' => $general]) === $admitted && (int) one('SELECT count(*) FROM space_members WHERE space_id = :g AND member_id = 29', ['g' => $general]) === 0, "everyone admitted but the guest is in General ($admitted, Seamus included; Ann is not)");
ok((int) one("SELECT count(*) FROM spaces s JOIN departments d ON d.id = s.department_id WHERE d.is_system AND s.kind = 'closed'") === 2, 'a closed space per standing department delivered (Accounting, IT)');
$st = $state();
ok($st['full_at'] !== null && $st['next_cursor'] !== null && $st['last_error'] === null, 'the sync state records the full pass (full_at, a cursor, no error)');
$out = sync('--full');
ok(str_contains($out, '(full)') && (int) one('SELECT count(*) FROM department_members WHERE left_at IS NULL') === 6, "running --full again changes nothing: $out");
ok(count(activity('directory.sync', 0)) >= 1 && activity('directory.sync', 0)[0]['source'] === 'cron', 'directory.sync is logged (source cron)');

echo "2. The incremental pass applies nothing and advances the cursor\n";
kernel_state(function ($s) { $s['feed']['next'] = '2026-03-01T00:00:00.000000Z'; unset($s['incremental']); return $s; });
$before = $state()['next_cursor'];
$out = sync();
ok(str_contains($out, 'applied 0 members, 0 departments, 0 memberships, 0 holdings') && !str_contains($out, '(full)'), "no change: $out");
ok($state()['next_cursor'] !== $before && str_starts_with($state()['next_cursor'], '2026-03-01'), 'the cursor moved to the kernel\'s next (' . $state()['next_cursor'] . ')');

echo "3. A role change reaches members.roles and the badge in one pass\n";
[$jm, ] = sign_on(26);
ok(badge_of($jm) === 'Member' && page($jm, '/admin/settings')['code'] === 403, 'Priya is a Member (admin settings 403)');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [['member_id' => 26, 'role' => 'space_owner', 'roles' => ['space_owner', 'user'], 'capability' => 'write', 'scopes' => []]]], '2026-03-02T00:00:00.000000Z'); return $s; });
$out = sync();
ok(str_contains($out, '1 holdings') && q('SELECT roles FROM members WHERE id = 26')[0]['roles'] === '{space_owner,user}', "one holding applied, roles now space_owner,user: $out");
ok(badge_of($jm) === 'Space owner' && right_of(26, 'space.manage') === true, 'her open session wears Space owner at once and holds space.manage — no new sign-on needed');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [['member_id' => 26, 'role' => 'admin', 'roles' => ['admin'], 'capability' => 'admin', 'scopes' => []]]], '2026-03-02T00:01:00.000000Z'); return $s; });
sync();
ok(badge_of($jm) === 'Spaces admin' && page($jm, '/admin/settings')['code'] === 200, 'made a Spaces admin by the feed: the badge and the Admin screens follow');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [['member_id' => 26, 'role' => 'user', 'roles' => ['user'], 'capability' => 'write', 'scopes' => []]]], '2026-03-03T00:00:00.000000Z'); return $s; });
sync();
ok(badge_of($jm) === 'Member' && page($jm, '/admin/settings')['code'] === 403 && q('SELECT roles FROM members WHERE id = 26')[0]['roles'] === '{user}', 'and back to Member: admin settings 403 again');

echo "4. Access withdrawn ends the member's sessions in the same pass\n";
[$jp, ] = sign_on(26);
ok(page($jp, '/')['code'] === 200, 'Priya is signed in');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [['member_id' => 26, 'role' => null, 'roles' => [], 'capability' => null, 'scopes' => []]]], '2026-03-04T00:00:00.000000Z'); return $s; });
sync();
$r = page($jp, '/');
ok($r['code'] === 302 && $r['location'] === $L && (int) one('SELECT count(*) FROM member_sessions WHERE member_id = 26 AND ended_at IS NULL') === 0, 'her next request goes to the launcher; no live session left');
ok(q("SELECT ended_by FROM member_sessions WHERE member_id = 26 ORDER BY created_at DESC LIMIT 1")[0]['ended_by'] === 'directory', "ended_by = 'directory'");
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [['member_id' => 26, 'role' => 'user', 'roles' => ['user'], 'capability' => 'write', 'scopes' => []]]], '2026-03-05T00:00:00.000000Z'); return $s; });
sync();
ok(q('SELECT capability FROM members WHERE id = 26')[0]['capability'] === 'write', 'granted again for the later proofs');

echo "5. A suspended member's sessions end in the same pass\n";
[$jl, ] = sign_on(28);
ok(page($jl, '/')['code'] === 200, 'Bea is signed in');
kernel_state(function ($s) { $s['incremental'] = incr(['members' => [['id' => 28, 'member_kind' => 'human', 'display_name' => 'SMOKE Bea', 'email' => 'bea@example.invalid', 'business_role' => 'user',
    'is_external' => false, 'status' => 'suspended', 'updated_at' => '2026-03-06T00:00:00Z', 'departments' => []]]], '2026-03-06T00:00:00.000000Z'); return $s; });
$out = sync();
ok(str_contains($out, '1 members'), "the member row applied: $out");
ok((int) one('SELECT count(*) FROM member_sessions WHERE member_id = 28 AND ended_at IS NULL') === 0 && q("SELECT ended_by FROM member_sessions WHERE member_id = 28 ORDER BY created_at DESC LIMIT 1")[0]['ended_by'] === 'directory', 'in that pass: her sessions ended (ended_by directory)');
ok(page($jl, '/')['code'] === 302 && q('SELECT status FROM members WHERE id = 28')[0]['status'] === 'inactive', 'her next request goes to the launcher and the mirror says inactive');
$r = req('GET', handoff(28, ['claims' => ['status' => 'suspended'] + fixture()['claims']['28']]), ['jar' => jar()]);
ok($r['code'] === 403, 'a hand-off for her is refused too');

echo "6. A department delivered seeds its space; a member admitted lands in General\n";
kernel_state(function ($s) { $s['incremental'] = incr(['departments' => [['id' => 2, 'name' => 'HR', 'description' => null, 'parent_id' => null, 'manager_member_id' => null, 'is_system' => true, 'system_key' => 'hr', 'archived_at' => null, 'updated_at' => '2026-03-07T00:00:00Z']]], '2026-03-07T00:00:00.000000Z'); return $s; });
$out = sync();
ok(str_contains($out, '1 departments') && (int) one("SELECT count(*) FROM spaces WHERE department_id = 2 AND kind = 'closed'") === 1, "a standing department delivered → its closed space seeded (db/006): $out");
kernel_state(function ($s) { $s['incremental'] = incr(['members' => [['id' => 32, 'member_kind' => 'human', 'display_name' => 'SMOKE Noor', 'email' => 'noor@example.invalid', 'business_role' => 'user', 'is_external' => false, 'status' => 'active',
    'updated_at' => '2026-03-08T00:00:00Z', 'departments' => [['id' => 2, 'name' => 'HR', 'is_admin' => false, 'is_primary' => true]]]],
    'memberships' => [['member_id' => 32, 'department_id' => 2, 'is_admin' => false, 'is_primary' => true, 'joined_at' => '2026-03-08T00:00:00Z', 'left_at' => null]],
    'access' => [['member_id' => 32, 'role' => 'user', 'roles' => ['user'], 'rights' => [], 'capability' => 'write', 'scopes' => []]]], '2026-03-08T00:00:00.000000Z'); return $s; });
$out = sync();
ok(q('SELECT capability, roles FROM members WHERE id = 32')[0] === ['capability' => 'write', 'roles' => '{user}'], "a new member with an access row is admitted: $out");
ok((int) one('SELECT count(*) FROM space_members WHERE space_id = :g AND member_id = 32', ['g' => $general]) === 1, 'and is in General');
ok((int) one("SELECT count(*) FROM sp_member_space_ids(32) x JOIN spaces s ON s.id = x WHERE s.department_id = 2") === 1, 'and in HR\'s space (their department — membership derived from the directory, db/006)');
[$jn, ] = sign_on(32, ['claims' => ['display_name' => 'SMOKE Noor', 'email' => 'noor@example.invalid', 'business_role' => 'user', 'is_external' => false, 'status' => 'active', 'departments' => [['id' => 2, 'name' => 'HR', 'is_admin' => false]], 'capability' => 'write', 'role' => 'user', 'roles' => ['user'], 'rights' => [], 'scopes' => [], 'scope' => null]]);
$h = page($jn, '/')['body'];
ok(str_contains($h, 'General') && str_contains($h, 'id="sidebar-space-' . (int) one('SELECT id FROM spaces WHERE department_id = 2') . '-caption"'), 'Noor\'s sidebar shows General and HR');

echo "7. A fixture (--from-file) and a kernel that fails\n";
$out = sync('--from-file ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/dev_directory.json'));
ok(str_contains($out, '(full)') && str_contains($out, '9 members') && str_contains($out, '8 holdings') && q('SELECT status FROM members WHERE id = 28')[0]['status'] === 'active', "--from-file applies the fixture's feed (Bea active again): $out");
$out = (string) shell_exec('OS_INTERNAL_URL=http://127.0.0.1:1 php ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/directory_sync.php') . ' 2>&1; echo "exit=$?"');
ok(str_contains($out, 'the kernel did not answer') && str_contains($out, 'exit=1'), 'an unreachable kernel: the run says so and exits 1');
ok(($state()['last_error'] ?? '') !== '' && $state()['last_error'] !== null, 'and directory_sync_state.last_error holds the reason (' . $state()['last_error'] . ')');
$h = json_decode(req('GET', '/api/v1/health')['body'], true);
ok(($h['directory']['error'] ?? '') !== '', 'health reports it: directory.error');
kernel_state(function ($s) { $s['incremental'] = incr([], '2026-03-09T00:00:00.000000Z'); return $s; });
sync();
ok($state()['last_error'] === null && str_starts_with($state()['next_cursor'], '2026-03-09'), 'the next good pass clears the error and moves the cursor');
kernel_state(function ($s) { unset($s['incremental']); return $s; });
finish();
