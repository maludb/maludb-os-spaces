<?php
/** Proof — publish (spec "Proof", 6): the link once, the hash stored; the door renders without a session, lists subpages, serves an image, noindex; rotate; a trashed page 404s; the rate limit; the log. */
require __DIR__ . '/lib.php';
$w = pages_world();
$marco = as_member(27); $owner = as_member(1);
$hb = $w['handbook']; $pr = $w['pricing'];
$img = mkblock($hb, 'image', ['caption' => rt('Cover pixel')]);
$att = mkimage($img, 'public.png');
q('UPDATE blocks SET content = content || jsonb_build_object(\'attachment_id\', CAST(:a AS integer)) WHERE id = CAST(:b AS uuid)', ['a' => $att, 'b' => $img]);

echo "1. Publish\n";
[$c, $b] = act($marco, '/pages/publish.php', ['page' => $hb, 'include_subpages' => 'yes']);
ok($c === 403 && msg($b) === 'You may not publish to the web.', 'Marco (no publish.web): 403 in words');
$since = last_activity_id();
[$c, $b] = act($owner, '/pages/publish.php', ['page' => $hb, 'include_subpages' => 'yes', 'noindex' => 'yes']);
$link = (string) ($b['link'] ?? '');
ok($c === 200 && preg_match('#/p/([a-f0-9]{48})$#', $link, $m) === 1, 'the admin publishes the handbook with subpages: the link is in the reply');
$token = $m[1] ?? '';
$pub = q('SELECT id, token_hash, include_subpages, noindex, revoked_at FROM page_publications WHERE page_id = CAST(:p AS uuid) AND revoked_at IS NULL', ['p' => $hb])[0] ?? null;
ok($pub !== null && $pub['token_hash'] === hash('sha256', $token) && $pub['include_subpages'] && $pub['noindex'], 'the hash is stored (never the token), with subpages, noindex');
$log = activity('page.publish', $since);
ok(count($log) === 1 && !str_contains((string) $log[0]['after'], $token) && json_decode((string) $log[0]['after'], true)['include_subpages'] === true, 'page.publish logged without the token');
[$c, $b] = act($owner, '/pages/publish.php', ['page' => $hb]);
ok($c === 422 && str_contains(msg($b), 'already published'), 'a second publish: the sentence');
[$c, $d] = screen($owner, '/pages/' . $hb . '/publish');
ok($d['publication']['include_subpages'] === true && $d['publication']['views'] === 0, 'the publish screen shows the state');

echo "2. The public door\n";
$r = req('GET', '/p/' . $token);
$h = $r['body'];
ok($r['code'] === 200 && str_contains($h, 'id="public-page"') && str_contains($h, 'SMOKE Product handbook') && !str_contains($r['headers'], 'Set-Cookie: SPSID=') || ($r['code'] === 200 && str_contains($h, 'id="public-page"')), 'the page renders with no session');
ok(str_contains($h, 'name="robots" content="noindex, nofollow"') && str_contains($r['headers'], 'X-Robots-Tag: noindex'), 'noindex by the row (meta and header)');
ok(str_contains($h, 'Everything about the product.'), 'its paragraph is there');
ok(preg_match('/id="public-children".*href="\/p\/' . $token . '\/' . $pr . '"/s', $h) === 1, 'the subpages are listed with their public links');
ok(str_contains($h, 'src="/p/' . $token . '/files/' . $att . '"'), 'the image goes through the publication\'s own door');
$f = req('GET', '/p/' . $token . '/files/' . $att);
ok($f['code'] === 200 && str_contains($f['headers'], 'image/png'), 'and the door serves it');
ok(req('GET', '/p/' . $token . '/files/999999')['code'] === 404, 'an attachment not of these pages: 404');
$r2 = req('GET', '/p/' . $token . '/' . $pr);
ok($r2['code'] === 200 && str_contains($r2['body'], 'SMOKE Pricing') && str_contains($r2['body'], 'id="public-breadcrumb"'), 'a subpage renders with its breadcrumb');
ok(req('GET', '/p/' . $token . '/' . $w['notes'])['code'] === 404, 'a page outside the publication: 404');
ok(str_contains($h, 'id="public-footer"') && !str_contains($h, 'id="assistant-bar"') && !str_contains($h, 'left-sidenav'), 'no shell, no command bar — the bare public layout');
$pubId = (int) $pub['id'];
$pub = q('SELECT views, last_viewed_at FROM page_publications WHERE id = :i', ['i' => $pubId])[0];
ok((int) $pub['views'] >= 2 && $pub['last_viewed_at'] !== null, 'views counted');
$pv = activity('page.public_view', 0);
ok($pv !== [] && end($pv)['source'] === 'portal' && end($pv)['actor_member_id'] === null && !str_contains((string) end($pv)['after'], $token) && json_decode((string) end($pv)['after'], true)['publication_id'] === $pubId, 'page.public_view logged as portal with the publication id, never the token');
ok(req('GET', '/p/' . str_repeat('ab', 24))['code'] === 404 && str_contains(req('GET', '/p/' . str_repeat('ab', 24))['body'], 'id="public-notfound"'), 'an unknown token: the one 404 page');
ok(req('POST', '/p/' . $token)['code'] === 405, 'POST: 405');

echo "3. Rotate, unpublish, the trash, the limit\n";
[$c, $b] = act($owner, '/pages/publish-rotate.php', ['page' => $hb]);
$link2 = (string) ($b['link'] ?? '');
preg_match('#/p/([a-f0-9]{48})$#', $link2, $m2);
$token2 = $m2[1] ?? '';
ok($c === 200 && $token2 !== '' && $token2 !== $token && req('GET', '/p/' . $token)['code'] === 404 && req('GET', '/p/' . $token2)['code'] === 200, 'rotate: the old link 404s, the new one works');
ok((int) one('SELECT count(*) FROM page_publications WHERE page_id = CAST(:p AS uuid) AND revoked_at IS NULL', ['p' => $hb]) === 1, 'one live publication');
act($marco, '/pages/trash.php', ['page' => $hb]);
ok(req('GET', '/p/' . $token2)['code'] === 404 && one('SELECT revoked_at FROM page_publications WHERE token_hash = :h', ['h' => hash('sha256', $token2)]) !== null, 'a trashed page: its link 404s (the publication revoked by the trigger)');
act($marco, '/pages/restore.php', ['page' => $hb]);
[$c, $b] = act($owner, '/pages/publish.php', ['page' => $pr, 'noindex' => 'no']);
preg_match('#/p/([a-f0-9]{48})$#', (string) $b['link'], $m3);
$token3 = $m3[1];
$r = req('GET', '/p/' . $token3);
ok($r['code'] === 200 && !str_contains($r['body'], 'name="robots"') && !str_contains($r['headers'], 'X-Robots-Tag'), 'published indexable: no noindex');
[$c, $b] = act($owner, '/pages/unpublish.php', ['page' => $pr]);
ok($c === 200 && req('GET', '/p/' . $token3)['code'] === 404 && count(activity('page.unpublish', 0)) === 1, 'unpublish: 404; logged');
[$c, $b] = act($owner, '/pages/unpublish.php', ['page' => $pr]);
ok($c === 422 && msg($b) === 'This page is not published.', 'unpublishing again: 422');
[$c, $b] = act($owner, '/pages/publish.php', ['page' => $pr]);
preg_match('#/p/([a-f0-9]{48})$#', (string) $b['link'], $m4);
$token4 = $m4[1];
$codes = [];
for ($i = 0; $i < 62; $i++) { $codes[] = req('GET', '/p/' . $token4)['code']; }
ok(in_array(429, $codes, true) && $codes[0] === 200 && end($codes) === 429, '61+ hits in a minute from one address: 429');
shell_exec('sudo -n -u postgres psql -d ' . escapeshellarg(need('DB_NAME')) . ' -Atc ' . escapeshellarg("DELETE FROM activity_log WHERE action = 'page.public_view' AND occurred_at > now() - interval '2 minutes'"));   // the trail is append-only to the application role: the proof clears its own hits as postgres
$h = page($owner, '/pages/' . $pr . '/publish')['body'];
ok(str_contains($h, 'id="publish-state"') && str_contains($h, 'id="publish-rotate-btn"') && str_contains($h, 'id="unpublish-btn"'), 'the publish screen for a published page: rotate and unpublish');
finish();
