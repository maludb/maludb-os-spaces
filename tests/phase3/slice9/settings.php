<?php
/** Proof — Settings (spec "Proof", 2): every field saved and read back; a value outside a CHECK is a 422 naming the field; MB to bytes; embed hosts one per line, lower-cased; settings.save logged with the changed fields; the agent's settings_save is in the registry as `other`; emoji added, replaced, removed, listed in the picker. */
require __DIR__ . '/lib.php';
$w = home_world();
$run = run_id();
$owner = as_member(1); $marco = as_member(27); $priya = as_member(26);
$orig = q('SELECT * FROM sp_settings WHERE id = 1')[0];
$get = function () use ($owner): array { [$c, $d] = screen($owner, '/admin/settings'); return $d['settings'] ?? []; };

echo "1. Every field saved and read back\n";
[$c, $d] = screen($owner, '/admin/settings');
ok($c === 200 && array_key_exists('business_name', $d['settings'] ?? []) && isset($d['settings']['max_attachment_mb'], $d['settings']['allowed_embed_hosts']) && is_array($d['emoji']) && count($d['emoji']) > 40, 'the screen answers JSON: the settings (the attachment limit in MB too) and the emoji list');
$all = ['business_name' => "SMOKE Business $run", 'default_space_kind' => 'open', 'default_member_level' => 'comment', 'default_everyone_level' => 'comment', 'version_snapshot_minutes' => '15', 'version_retention_days' => '120',
        'trash_retention_days' => '45', 'stale_page_days' => '60', 'unanswered_hours' => '12', 'wiki_default_verify_months' => '3', 'max_attachment_mb' => '10', 'allowed_embed_hosts' => "WWW.Example.com\nplayer.Foo.io",
        'public_pages_noindex' => 'no', 'public_base_url' => 'https://spaces.example.com/', 'digest_hour' => '7', 'week_start_dow' => '0', 'timezone' => 'America/Chicago', 'group_dm_max_members' => '12', 'away_minutes' => '20',
        'admin_channel_name' => 'smoke-admin-' . $run, 'agents_join_default_space' => 'no'];
$log0 = last_activity_id();
[$c, $b] = act($owner, '/admin/settings.php', $all);
ok($c === 200 && ($b['ok'] ?? false) === true && array_key_exists('record_id', $b) && isset($b['location'], $b['did']) && ($b['refresh'] ?? '') === 'settingsChanged' && count($b['changed']) >= 15, 'settings_save: the contract {ok, did, record_id, location, refresh}; changed ' . count($b['changed'] ?? []) . ' fields');
$s = $get();
$expect = ['business_name' => "SMOKE Business $run", 'default_space_kind' => 'open', 'default_member_level' => 'comment', 'default_everyone_level' => 'comment', 'version_snapshot_minutes' => 15, 'version_retention_days' => 120, 'trash_retention_days' => 45,
           'stale_page_days' => 60, 'unanswered_hours' => 12, 'wiki_default_verify_months' => 3, 'max_attachment_mb' => 10, 'public_pages_noindex' => false, 'public_base_url' => 'https://spaces.example.com', 'digest_hour' => 7, 'week_start_dow' => 0,
           'timezone' => 'America/Chicago', 'group_dm_max_members' => 12, 'away_minutes' => 20, 'admin_channel_name' => 'smoke-admin-' . $run, 'agents_join_default_space' => false];
$bad = [];
foreach ($expect as $k => $v) { if (($s[$k] ?? null) !== $v) { $bad[] = "$k: " . json_encode($s[$k] ?? null); } }
ok($bad === [], 'every field reads back as saved' . ($bad ? ': ' . implode('; ', $bad) : ''));
ok((int) one('SELECT max_attachment_bytes FROM sp_settings') === 10485760 && $s['max_attachment_bytes'] === 10485760, 'MB become bytes: 10 MB = 10485760 in the row');
ok($s['allowed_embed_hosts'] === ['www.example.com', 'player.foo.io'], 'embed hosts: one per line, lower-cased: ' . json_encode($s['allowed_embed_hosts']));
$ev = activity_after('settings.save', $log0);
$after = json_decode((string) ($ev[0]['after'] ?? '{}'), true) ?: []; $before = json_decode((string) ($ev[0]['before'] ?? '{}'), true) ?: [];
ok(count($ev) === 1 && $ev[0]['source'] === 'web' && ($after['digest_hour'] ?? null) === 7 && ($before['digest_hour'] ?? null) === (int) $orig['digest_hour'] && isset($after['allowed_embed_hosts']), 'settings.save is logged once with before and after of the changed fields: ' . substr((string) ($ev[0]['after'] ?? ''), 0, 120));
$log1 = last_activity_id();
[$c, $b] = act($owner, '/admin/settings.php', ['digest_hour' => '7']);
ok($c === 200 && $b['changed'] === [] && activity_after('settings.save', $log1) === [], 'saving what is already saved changes and logs nothing');
[$c, $b] = act($owner, '/admin/settings.php', ['stale_page_days' => '61']);
$s2 = $get();
ok($c === 200 && $s2['stale_page_days'] === 61 && $s2['digest_hour'] === 7 && $s2['business_name'] === "SMOKE Business $run" && $s2['allowed_embed_hosts'] === $s['allowed_embed_hosts'], 'a field left out stays as it was (one field saved)');

echo "2. A value outside a CHECK is a 422 naming the field\n";
foreach ([['version_snapshot_minutes', '0', 'Save a version'], ['trash_retention_days', '400', 'Keep the trash'], ['digest_hour', '24', 'Morning digest'], ['stale_page_days', '3', 'stale'], ['group_dm_max_members', '2', 'group message'], ['max_attachment_mb', '2000', 'attachment']] as [$k, $v, $word]) {
    [$c, $b] = act($owner, '/admin/settings.php', [$k => $v]);
    $f = fields($b);
    ok($c === 422 && (isset($f[$k]) || isset($f['max_attachment_mb'])) && str_contains(implode(' ', $f), $word), "$k = $v: 422 naming the field (" . implode(' ', $f) . ')');
}
[$c, $b] = act($owner, '/admin/settings.php', ['wiki_default_verify_months' => '5']);
ok($c === 422 && isset(fields($b)['wiki_default_verify_months']), 'a verify window that is not 1, 3, 6 or 12: 422');
[$c, $b] = act($owner, '/admin/settings.php', ['timezone' => 'Mars/Olympus']);
ok($c === 422 && isset(fields($b)['timezone']), 'a time zone that does not exist: 422');
[$c, $b] = act($owner, '/admin/settings.php', ['allowed_embed_hosts' => "good.example.com\nnot a host"]);
ok($c === 422 && isset(fields($b)['allowed_embed_hosts']), 'a bad embed host: 422');
[$c, $b] = act($owner, '/admin/settings.php', ['public_base_url' => 'ftp://nope']);
ok($c === 422 && isset(fields($b)['public_base_url']), 'a base URL that is not http(s): 422');
[$c, $b] = act($owner, '/admin/settings.php', ['digest_hour' => '24', 'stale_page_days' => '90']);
ok($c === 422 && $get()['stale_page_days'] === 61, 'a refusal changes nothing, not even the valid fields beside it');
[$c, $b] = act($owner, '/admin/settings.php', ['max_attachment_bytes' => '2097152']);
ok($c === 200 && $get()['max_attachment_bytes'] === 2097152 && $get()['max_attachment_mb'] === 2, 'an agent\'s way: max_attachment_bytes in bytes');
[$c, $b] = act($owner, '/admin/settings.php', ['max_attachment_bytes' => '100']);
ok($c === 422, 'bytes below 1 MB: 422');

echo "3. Who may, and what an agent's call is\n";
[$c, $b] = act($marco, '/admin/settings.php', ['digest_hour' => '9']);
ok($c === 403 && str_contains(msg($b), 'You may not change the workspace settings') && $get()['digest_hour'] === 7, 'a space owner: 403 "' . msg($b) . '"');
[$c] = screen($priya, '/admin/settings');
ok($c === 403, 'a Member: the screen is 403');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['settings_save']['approval'] ?? '') === 'other' && ($reg['actions']['settings_save']['log_event'] ?? '') === 'settings.save' && ($reg['actions']['emoji_save']['built'] ?? false) === true, 'the agent\'s settings_save pauses on the hook path: category `other` in the registry (Phase 4 wires the hook)');
$seamus = as_agent(run_token(40, 4801));
kernel_state(function ($s) { $s['facts']['4801'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 4801, 'request_id' => 'req-4801', 'trigger' => 'chat', 'endpoints' => [['name' => 'Actions MCP']]]; return $s; });
[$c, $b] = act_token('/admin/settings.php', ['digest_hour' => '9'], $seamus);
ok($c === 403, 'an agent that is a Member holds no settings.manage: 403');

echo "4. The emoji list\n";
$code = 'smokeship' . $run;
$log0 = last_activity_id();
[$c, $b] = act($owner, '/admin/emoji.php', ['shortcode' => ":$code:", 'emoji' => '🚢', 'keywords' => 'ship, release']);
$row = q('SELECT emoji, keywords::text AS k, sort_order FROM emoji_shortcodes WHERE shortcode = :s', ['s' => $code])[0] ?? [];
ok($c === 200 && ($b['refresh'] ?? '') === 'emojiChanged' && ($row['emoji'] ?? '') === '🚢' && $row['k'] === '{ship,release}', 'emoji_save adds one (the colons are dropped), with keywords');
$ev = activity_after('emoji.save', $log0);
ok(count($ev) === 1 && str_contains((string) $ev[0]['after'], $code), 'emoji.save is logged with the shortcode');
[$c, $d] = screen($owner, '/pages/mentions.php?kind=emoji&q=' . $code);
$cands = $d['candidates'] ?? [];
ok(($cands[0]['id'] ?? '') === $code && ($cands[0]['name'] ?? '') === '🚢', 'it is listed in the picker (by shortcode)');
[, $d] = screen($owner, '/pages/mentions.php?kind=emoji&q=release');
ok(in_array($code, array_column($d['candidates'] ?? [], 'id'), true), 'and found by a keyword');
[$c, $b] = act($owner, '/admin/emoji.php', ['shortcode' => $code, 'emoji' => '⛴️', 'keywords' => 'ferry']);
ok($c === 200 && one('SELECT emoji FROM emoji_shortcodes WHERE shortcode = :s', ['s' => $code]) === '⛴️' && (int) one('SELECT count(*) FROM emoji_shortcodes WHERE shortcode = :s', ['s' => $code]) === 1, 'the same shortcode again replaces it');
[$c, $b] = act($owner, '/admin/emoji.php', ['shortcode' => 'tada', 'emoji' => '🥳', 'keywords' => 'party']);
ok($c === 200 && one("SELECT emoji FROM emoji_shortcodes WHERE shortcode = 'tada'") === '🥳', 'a seeded one may be replaced');
act($owner, '/admin/emoji.php', ['shortcode' => 'tada', 'emoji' => '🎉', 'keywords' => 'party,celebrate']);
[$c, $b] = act($owner, '/admin/emoji.php', ['shortcode' => 'Bad Code', 'emoji' => '🚢']);
ok($c === 422 && isset(fields($b)['shortcode']), 'a bad shortcode: 422');
[$c, $b] = act($owner, '/admin/emoji.php', ['shortcode' => 'toolong' . $run, 'emoji' => str_repeat('x', 17)]);
ok($c === 422 && isset(fields($b)['emoji']), 'more than 16 characters of emoji: 422');
[$c, $b] = act($marco, '/admin/emoji.php', ['shortcode' => 'nope', 'emoji' => '🚫']);
ok($c === 403, 'a space owner may not add one: 403');
$html = req('GET', '/admin/settings', ['jar' => $owner])['body'];
ok(str_contains($html, 'id="emoji-row-' . $code . '"') && str_contains($html, 'id="settings-form"') && str_contains($html, 'id="settings-form-field-max_attachment_mb"'), 'the screen lists it (emoji-row-…) beside the form');
$log0 = last_activity_id();
[$c, $b] = act($owner, '/admin/emoji-delete.php', ['shortcode' => $code]);
ok($c === 200 && (int) one('SELECT count(*) FROM emoji_shortcodes WHERE shortcode = :s', ['s' => $code]) === 0 && count(activity_after('emoji.delete', $log0)) === 1, 'emoji_delete removes it and logs emoji.delete');
[$c, $b] = act($owner, '/admin/emoji-delete.php', ['shortcode' => $code]);
ok($c === 404, 'removing it again: 404');
[, $d] = screen($owner, '/pages/mentions.php?kind=emoji&q=' . $code);
ok(($d['candidates'] ?? []) === [], 'and it leaves the picker');

echo "5. Put the settings back\n";
$cols = ['business_name', 'default_space_kind', 'default_member_level', 'default_everyone_level', 'version_snapshot_minutes', 'version_retention_days', 'trash_retention_days', 'stale_page_days', 'unanswered_hours', 'wiki_default_verify_months', 'max_attachment_bytes', 'public_pages_noindex', 'public_base_url', 'digest_hour', 'week_start_dow', 'timezone', 'group_dm_max_members', 'away_minutes', 'admin_channel_name', 'agents_join_default_space'];
$sets = []; $args = [];
foreach ($cols as $c) { $sets[] = "$c = :$c"; $args[$c] = is_bool($orig[$c]) ? ($orig[$c] ? 't' : 'f') : $orig[$c]; }
$sets[] = 'allowed_embed_hosts = CAST(:h AS text[])'; $args['h'] = $orig['allowed_embed_hosts'];
q('UPDATE sp_settings SET ' . implode(', ', $sets) . ' WHERE id = 1 RETURNING id', $args);
ok(one('SELECT digest_hour FROM sp_settings') == $orig['digest_hour'], 'restored for the proofs that follow');
finish();
