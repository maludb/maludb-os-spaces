<?php
/*
 * Proof: the deploy vhost's URL rules (deploy/apache-spaces.conf). Meaningful under SP_APP=apache tests/phase2/run.sh, where a real
 * Apache serves the template rendered as the installer renders it; under php -S the same checks run against tests/dev_router.php,
 * which mirrors the rewrites. Nothing outside html/ is reachable, /api is blocked but for health, the MCP proxies answer 502 until
 * Phase 4 (under Apache), the public door /p/{token} has no handler yet (404), the attachment door needs a session (401).
 */
require __DIR__ . '/lib.php';
[$j, ] = sign_on(27);
echo "URL rules\n";
foreach (['/sso' => 403, '/sso/logout' => 405] as $path => $want) {
    ok(req('GET', $path)['code'] === $want, "GET $path → $want (the receivers answer at their extension-less names)");
}
ok(page($j, '/settings/tokens/')['code'] === 200 && page($j, '/settings/tokens')['code'] === 200, '/settings/tokens/ and /settings/tokens both reach settings/tokens/index.php');
ok(page($j, '/spaces/')['code'] === 200 && page($j, '/spaces')['code'] === 200, '/spaces/ and /spaces both reach spaces/index.php (the placeholder)');
ok(page($j, '/channels/')['code'] === 200 && page($j, '/dm/')['code'] === 200 && page($j, '/pages/')['code'] === 200, '/channels/, /dm/ and /pages/ reach their placeholders');
ok(page($j, '/trail')['code'] === 200 && page($j, '/saved')['code'] === 200 && page($j, '/search')['code'] === 200 && page($j, '/activity')['code'] === 200, '/trail (real), /saved, /search and /activity (placeholders) resolve to their own files');
ok(page($j, '/admin/settings')['code'] === 403 && page($j, '/exports/')['code'] === 403 && page($j, '/proposals/')['code'] === 403, '/admin/settings, /exports/ and /proposals/ resolve (403 for a Space owner, by the right)');
ok(page($j, '/channels/1/members')['code'] === 404 && page($j, '/databases/0f3a5b7c-1111-4222-8333-444455556666')['code'] === 404, '/channels/1/members and /databases/{uuid} are rewritten to files their slices (4, 5) have not built: 404');
ok(page($j, '/pages/new')['code'] === 200 && page($j, '/spaces/1')['code'] === 200, '/pages/new reaches pages/form.php and /spaces/1 spaces/view.php (slices 2 and 1)');
ok(page($j, '/pages/' . '0f3a5b7c-1111-4222-8333-444455556666')['code'] === 404, 'a UUID record (/pages/{uuid}) is rewritten to pages/view.php: 404 for a page that is not there');
ok(req('GET', '/nosuchscreen', ['jar' => $j])['code'] === 404, 'an unknown path: 404');
$tok = str_repeat('ab', 24);
ok(req('GET', "/p/$tok")['code'] === 404 && req('GET', "/p/$tok/files/1")['code'] === 404 && req('GET', "/p/$tok/0f3a5b7c-1111-4222-8333-444455556666")['code'] === 404, 'the public door /p/{token}, its files and subpages are rewritten to p.php (slice 2) and answer 404 until then');
ok(req('GET', '/files/1')['code'] === 401, '/files/1 without a session: 401 (never a redirect — a file URL sits in an <img>)');
ok(page($j, '/files/1')['code'] === 404 && page($j, '/files/1/thumb')['code'] === 404, '/files/1 with a session: 404 (no attachment yet); /files/1/thumb the same');
$h = req('GET', '/api/v1/health');
ok($h['code'] === 200 && json_decode($h['body'], true)['application'] === 'spaces', '/api/v1/health answers');
ok(req('GET', '/api/v1/health.php')['code'] === 200, '/api/v1/health.php answers too');
ok(req('GET', '/api/v1/other')['code'] === 404 && req('GET', '/api/')['code'] === 404, 'anything else under /api is 404');
if (getenv('SP_APP') === 'apache') {
    ok(in_array(req('GET', '/mcp/records')['code'], [502, 503], true) && in_array(req('GET', '/mcp/activity')['code'], [502, 503], true), 'the MCP proxies are in the vhost and answer 502 until Phase 4 starts the servers');
}
$m = req('GET', '/manifest.webmanifest');
ok($m['code'] === 200 && preg_match('/^Content-Type: application\/(manifest\+)?json/mi', $m['headers']) === 1 && json_decode($m['body'], true)['name'] === 'Spaces', '/manifest.webmanifest is served as JSON and names Spaces');
$s = req('GET', '/assets/css/theme.min.css');
ok($s['code'] === 200 && preg_match('/^Content-Type: text\/css/mi', $s['headers']) === 1, 'static assets are served with their type');
foreach (['/config/.env', '/db/001_roles_and_identity.sql', '/app/bootstrap.php', '/bin/directory_sync.php', '/mcp/db.py', '/CLAUDE.md', '/maludb-os.json', '/.git/config', '/tests/phase2/lib.php', '/deploy/ROOT_STEPS.sh', '/storage/x'] as $p) {
    $r = req('GET', $p);
    ok(in_array($r['code'], [403, 404], true) && !str_contains($r['body'], 'ACTION_TOKEN_KEY') && !str_contains($r['body'], 'CREATE TABLE'), "$p is not served ({$r['code']})");
}
finish();
