<?php
/** Proof — JSON mode and the whole manifest: the three actions under action tokens answer {ok, did, record_id, location, refresh}; every screen of the slice answers data; settings and tokens are whole; the registry reads 58 screens and 118 actions built and no placeholder is left. */
require __DIR__ . '/lib.php';
$w = home_world();
$run = run_id();
$ownerT = ['X-Action-Token: ' . person_token(1)]; $marcoT = ['X-Action-Token: ' . person_token(27)]; $priyaT = ['X-Action-Token: ' . person_token(26)];
$owner = as_member(1); $marco = as_member(27); $priya = as_member(26);
$contract = fn (array $b) => ($b['ok'] ?? false) === true && array_key_exists('record_id', $b) && isset($b['location'], $b['did']) && array_key_exists('refresh', $b);
$digest = (int) one('SELECT digest_hour FROM sp_settings');

echo "1. The three actions under action tokens\n";
[$c, $b] = act_token('/admin/settings.php', ['digest_hour' => (string) (($digest + 1) % 24)], $ownerT);
ok($c === 200 && $contract($b) && $b['refresh'] === 'settingsChanged' && $b['changed'] === ['digest_hour'], 'settings_save: the contract, refresh settingsChanged');
act_token('/admin/settings.php', ['digest_hour' => (string) $digest], $ownerT);
[$c, $b] = act_token('/admin/settings.php', ['digest_hour' => '24'], $ownerT);
ok($c === 422 && isset(fields($b)['digest_hour']), 'a bad value: 422 with the field');
[$c, $b] = act_token('/admin/settings.php', ['digest_hour' => '9'], $marcoT);
ok($c === 403, 'a space owner: 403');
[$c, $b] = act_token('/admin/emoji.php', ['shortcode' => 'smokejson' . $run, 'emoji' => '🧪', 'keywords' => 'test,json'], $ownerT);
ok($c === 200 && $contract($b) && $b['refresh'] === 'emojiChanged', 'emoji_save: the contract');
[$c, $b] = act_token('/admin/emoji-delete.php', ['shortcode' => 'smokejson' . $run], $ownerT);
ok($c === 200 && $contract($b), 'emoji_delete: the contract');
[$c, $b] = act_token('/admin/emoji-delete.php', ['shortcode' => 'smokejson' . $run], $ownerT);
ok($c === 404, 'and again: 404');
[$c, $b] = act_token('/admin/emoji.php', ['shortcode' => 'x', 'emoji' => 'x'], $priyaT);
ok($c === 403, 'a Member: 403');
$log0 = last_activity_id();
act_token('/admin/settings.php', ['stale_page_days' => '91'], $ownerT);
$ev = activity_after('settings.save', $log0);
ok(count($ev) === 1 && $ev[0]['source'] === 'assistant', 'under a person\'s action token the log source is `assistant`');
act_token('/admin/settings.php', ['stale_page_days' => '90'], $ownerT);

echo "2. Every screen of the slice answers data\n";
foreach (['/' => ['unread', 'mentions', 'recent_pages', 'favorites', 'verification_due', 'librarian_note', 'may'], '/trail' => ['rows', 'page', 'more'], '/admin/settings' => ['settings', 'emoji'], '/admin/spaces' => ['spaces'], '/admin/published' => ['published', 'count'],
          '/admin/retention' => ['spaces', 'versions_days', 'trash_days'], '/admin/trash' => ['trash', 'count'], '/settings/' => ['notify', 'kinds', 'timezone'], '/settings/tokens/' => ['tokens', 'records_url']] as $path => $keys) {
    [$c, $d] = screen($owner, $path);
    $miss = array_filter($keys, fn ($k) => !array_key_exists($k, $d));
    ok($c === 200 && $miss === [], "$path answers JSON with " . implode(', ', $keys) . ($miss ? ' — missing ' . implode(', ', $miss) : ''));
}
foreach (['/admin/settings', '/admin/spaces', '/admin/published', '/admin/retention', '/admin/trash'] as $path) {
    $r = req('GET', $path, ['jar' => $priya, 'headers' => JSONH]);
    ok($r['code'] === 403 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'forbidden', "$path for a Member: 403 forbidden");
}
$r = req('GET', '/admin/trash', ['headers' => JSONH]);
ok($r['code'] === 401, 'no session: 401');

echo "3. settings and tokens are whole\n";
$html = req('GET', '/settings/', ['jar' => $priya])['body'];
ok(str_contains($html, 'id="settings-timezone-value"') && str_contains($html, 'HR keeps it') && str_contains($html, 'id="settings-timezone-link"') && !str_contains($html, 'id="settings-tab-admin"'), 'settings: the time zone with HR\'s note; a Member has no link to the workspace settings');
ok(str_contains(req('GET', '/settings/', ['jar' => $owner])['body'], 'id="settings-tab-admin"'), 'the admin has the link');
[$c, $b] = act($priya, '/settings/tokens/mint.php', ['label' => "SMOKE token $run", 'scope' => 'mcp']);
ok($c === 200 && str_starts_with((string) ($b['token'] ?? ''), '') && ($b['token'] ?? '') !== '' && isset($b['id']), 'tokens: a mint shows the value once (in JSON)');
$raw = $b['token']; $id = $b['id'];
[, $d] = screen($priya, '/settings/tokens/');
$row = array_column($d['tokens'], null, 'id')[$id] ?? array_column($d['tokens'], null, 'token_id')[$id] ?? null;
ok($row !== null && !str_contains(json_encode($d), $raw) && isset($row['label']) && $row['label'] === "SMOKE token $run", 'the list never shows the value again: label, scope, last used');
$html = req('GET', '/settings/tokens/', ['jar' => $priya])['body'];
ok(str_contains($html, 'id="token-row-' . $id . '"') && str_contains($html, 'last used') || str_contains($html, 'Last used') || str_contains($html, 'never used') || str_contains($html, 'Never used'), 'the screen: a row with its last use');
[$c, $b] = act($priya, '/settings/tokens/revoke.php', ['token' => $id]);
ok($c === 200, 'revoke');
[, $d] = screen($priya, '/settings/tokens/');
$row = array_column($d['tokens'], null, 'id')[$id] ?? array_column($d['tokens'], null, 'token_id')[$id] ?? null;
ok($row !== null && $row['revoked'] === true, 'a revoked token is marked revoked on the list');

echo "4. The whole manifest is built\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$sb = count(array_filter($reg['screens'], fn ($s) => $s['built'] ?? false)); $ab = count(array_filter($reg['actions'], fn ($a) => $a['built'] ?? false));
ok(count($reg['screens']) === 58 && $sb === 58, "the registry reads $sb of " . count($reg['screens']) . ' screens built');
ok(count($reg['actions']) === 118 && $ab === 118, "the registry reads $ab of " . count($reg['actions']) . ' actions built');
$unbuilt = array_merge(array_keys(array_filter($reg['screens'], fn ($s) => !($s['built'] ?? false))), array_keys(array_filter($reg['actions'], fn ($a) => !($a['built'] ?? false))));
ok($unbuilt === [], 'nothing reads as unbuilt' . ($unbuilt ? ': ' . implode(', ', $unbuilt) : ''));
$stubs = trim((string) shell_exec('grep -rl "render_nav_stub(\|render_module_stub(" ' . escapeshellarg(dirname(__DIR__, 3) . '/html') . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/app') . ' 2>/dev/null | wc -l'));
ok($stubs === '0', 'no placeholder is left in html/ or app/');
$missing = [];
foreach (['settings_save' => 'admin/settings.php', 'emoji_save' => 'admin/emoji.php', 'emoji_delete' => 'admin/emoji-delete.php'] as $act => $f) { if (!is_file(dirname(__DIR__, 3) . '/html/' . $f) || ($reg['actions'][$act]['endpoint'] ?? '') !== '/' . $f) { $missing[] = $act; } }
ok($missing === [], 'each action of the spec is its file: settings_save, emoji_save, emoji_delete');
finish();
