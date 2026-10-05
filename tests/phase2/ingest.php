<?php
/**
 * Proof: the activity ingest bridge (mcp/activity_ingest.py) ships the log to the MaluDB API tagged "spaces", with space_id, channel_id,
 * message_id and entity_uuid keys where set, advances the checkpoint only past rows the API accepted, and health reads ingest_lag
 * (sso-shell.md "Proof: ingest"). The MaluDB API is a fake (tests/fake_maludb.php) — a scratch row must never reach the tenant's real
 * memory. Needs the asyncpg/httpx venv of /srv/apps/projects (read only). Run through tests/phase2/run.sh.
 */
require __DIR__ . '/lib.php';
$py = getenv('SP_PYTHON') ?: '/srv/apps/projects/mcp/venv/bin/python';
$log = need('FAKE_MALUDB_LOG');
$run = fn (string $env = '') => trim((string) shell_exec($env . ' ' . escapeshellarg($py) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/mcp/activity_ingest.py') . ' 2>&1; echo "exit=$?"'));
$state = fn () => (int) one('SELECT last_id FROM activity_ingest_state WHERE id = 1');
$max = fn () => (int) one('SELECT max(id) FROM activity_log');
$lines = fn () => array_values(array_filter(array_map(fn ($l) => json_decode($l, true), file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])));
$general = (int) one('SELECT id FROM spaces WHERE is_default LIMIT 1');
$uuid = '0f3a5b7c-1111-4222-8333-444455556666';

file_put_contents($log, '');
// a space-scoped row with a page key, as slice 2 will write them (no page exists yet: the keys are plain columns on activity_log)
pdo()->exec("INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_uuid, space_id, after) VALUES (27, 'web', 'page.create', 'page', '$uuid', $general, '{\"title\": \"Welcome\"}')");
$h = json_decode(req('GET', '/api/v1/health')['body'], true);
ok($state() === 0 && $h['ingest_lag'] === $max() && $max() > 50, "before: checkpoint 0, health ingest_lag {$h['ingest_lag']} = {$max()} rows waiting");
$out = $run('MALUDB_API_TOKEN=wrong-token');
ok(str_contains($out, 'episode POST failed') && str_contains($out, 'exit=1') && $state() === 0 && $lines() === [], 'the API refuses (401): the run reports it, exits 1 and the checkpoint does NOT move');
$total = $max();
$out = $run();
$runs = 1;
while (preg_match('/shipped (\d+)\/(\d+) activity rows; checkpoint now (\d+)/', $out, $m) && (int) $m[3] < $total && $runs < 10) { $out = $run(); $runs++; }   // batches of 500
ok(preg_match('/shipped (\d+)\/(\d+) activity rows; checkpoint now (\d+)/', $out, $m) && (int) $m[1] === (int) $m[2] && (int) $m[3] === $total && str_contains($out, 'exit=0'), "good runs ship every row ($runs run(s)): $out");
ok($state() === $total, "the checkpoint is at the last row ($total)");
$eps = $lines();
ok(count($eps) === $total, count($eps) . ' episodes reached the API, one per activity row');
ok(count(array_filter($eps, fn ($e) => ($e['kind'] ?? '') === 'activity' && ($e['payload']['application'] ?? '') === 'spaces')) === $total && array_keys($eps[0]['payload'])[0] === 'application', 'every one is an activity episode whose payload STARTS with "application": "spaces"');
$note = array_values(array_filter($eps, fn ($e) => ($e['payload']['action'] ?? '') === 'page.create'));
ok($note !== [] && ($note[0]['payload']['space_id'] ?? 0) === $general && ($note[0]['payload']['entity_uuid'] ?? '') === $uuid && str_starts_with($note[0]['title'], 'page.create by member #27'), 'a space-scoped episode carries space_id and entity_uuid so memory can answer "what happened in General / to that page"');
$signOns = array_values(array_filter($eps, fn ($e) => ($e['payload']['action'] ?? '') === 'member.sign_on'));
ok($signOns !== [] && !array_key_exists('space_id', $signOns[0]['payload']) && isset($signOns[0]['payload']['after']['capability']), 'a sign-on episode carries no space key (nulls are dropped) and its after.capability');
ok(preg_match('/mcp_[0-9a-f]{48}/', file_get_contents($log)) === 0, 'no episode carries a token value');
$out = $run();
ok($out === 'exit=0' && count($lines()) === $total, 'a further run has nothing to do (no duplicates)');
$h = json_decode(req('GET', '/api/v1/health')['body'], true);
ok($h['ingest_lag'] <= 1 && $h['maludb'] === 'ok', "health: ingest_lag {$h['ingest_lag']} (the health request's own row at most), maludb ok");
[$j, ] = sign_on(27);
$out = $run();
ok(preg_match('/shipped (\d+)\/(\d+)/', $out, $m) && (int) $m[1] >= 1 && $state() === $max(), 'new activity ships on the next run and the checkpoint follows: ' . $out);
finish();
