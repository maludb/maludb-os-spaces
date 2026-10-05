<?php
/** Proof — preferences (spec "Proof", 2): email off queues no email; a text for a kind is a "Spaces: …" row of at most 480 characters; digest on queues no per-event email; away minutes against last_seen_at; an unknown kind and a text kind outside the told kinds are 422; a guest saves prefs; the form works without JavaScript. */
require __DIR__ . '/lib.php';
$w = wiki_world();
$marco = as_member(27); $dana = as_member(30); $ann = as_member(29);
$launch = $w['launch'];
pdo()->exec("UPDATE channel_members SET notify = 'all', muted_until = NULL WHERE channel_id = $launch");
$away = static function (int $minutes = 180): void { pdo()->exec("UPDATE members SET last_seen_at = now() - interval '$minutes minutes' WHERE id = 30"); };
$mention = static function (string $tag) use ($marco, $launch): int { [, $b] = post($marco, $launch, "SMOKE prefs $tag for @SMOKE Dana"); return (int) $b['record_id']; };
$outbox = static fn (int $m, string $channel): int => (int) one("SELECT count(*) FROM notification_outbox WHERE member_id = 30 AND channel = :c AND record_id = :m AND kind = 'mention'", ['c' => $channel, 'm' => $m]);
$defaults = ['email_enabled' => 'yes', 'text_enabled' => 'no', 'digest' => 'no', 'away_minutes' => '', 'kinds' => 'mention,reply,comment,share,verification,reminder,dm,join_request,join_decided,proposal,agent_replied', 'text_kinds' => 'dm,mention'];
$save = static fn (string $jar, array $f) => act($jar, '/settings/prefs.php', $f);

echo "1. Email\n";
$save($dana, $defaults); $away();
$m = $mention('on');
ok($outbox($m, 'email') === 1 && $outbox($m, 'text') === 0, 'defaults, away: one email, no text');
$save($dana, ['email_enabled' => 'no']); $away();
$m = $mention('off');
ok($outbox($m, 'email') === 0 && (int) one("SELECT count(*) FROM notifications WHERE member_id = 30 AND message_id = :m", ['m' => $m]) === 1, 'email off: no email queued, the bell row is still there');

echo "2. Text\n";
$save($dana, ['email_enabled' => 'yes', 'text_enabled' => 'yes', 'text_kinds' => 'mention']); $away();
$m = $mention('text');
$t = q("SELECT body FROM notification_outbox WHERE member_id = 30 AND channel = 'text' AND record_id = :m", ['m' => $m]);
ok(count($t) === 1 && str_starts_with($t[0]['body'], 'Spaces: ') && mb_strlen($t[0]['body']) <= 480, 'text on for mention: one text row, starts "Spaces: ", ' . mb_strlen($t[0]['body'] ?? '') . ' characters');
[, $b] = post($marco, $launch, 'SMOKE ' . str_repeat('long words ', 80) . '@SMOKE Dana');
$t = q("SELECT body FROM notification_outbox WHERE member_id = 30 AND channel = 'text' AND record_id = :m", ['m' => (int) $b['record_id']]);
ok(count($t) === 1 && mb_strlen($t[0]['body']) <= 480, 'a long message: the text is cut to 480 or less (' . mb_strlen($t[0]['body'] ?? '') . ')');
$save($dana, ['text_kinds' => 'dm']); $away();
$m = $mention('nottext');
ok($outbox($m, 'text') === 0 && $outbox($m, 'email') === 1, 'text on but only for dm: a mention is emailed, not texted');
$save($dana, ['text_enabled' => 'no', 'text_kinds' => 'dm,mention']);

echo "3. Digest\n";
$save($dana, ['digest' => 'yes']); $away();
$m = $mention('digest');
ok($outbox($m, 'email') === 0 && (int) one("SELECT count(*) FROM notifications WHERE member_id = 30 AND message_id = :m", ['m' => $m]) === 1, 'digest on: no per-event email (the worker sends one in the morning), the bell row stays');
$save($dana, ['digest' => 'no']);

echo "4. Away minutes\n";
$save($dana, ['away_minutes' => '1']);
pdo()->exec("UPDATE members SET last_seen_at = now() WHERE id = 30");
$m = $mention('here');
ok($outbox($m, 'email') === 0, 'away minutes 1 and seen just now: not away, no email');
$away(10);
$m = $mention('gone');
ok($outbox($m, 'email') === 1, '10 minutes later (fixture time): away, an email');
[$c, $d] = screen($dana, '/settings/');
ok($c === 200 && $d['notify']['away_minutes'] === 1, 'the screen shows what was saved');
$save($dana, ['away_minutes' => '']);
[$c, $d] = screen($dana, '/settings/');
ok($d['notify']['away_minutes'] === null, 'empty returns to the workspace\'s');
[$c, $b] = $save($dana, ['away_minutes' => '5000']);
ok($c === 422 && str_contains(msg($b), '1 to 1440'), 'away minutes out of range: 422 in words');

echo "5. Refusals\n";
$since = last_activity_id();
[$c, $b] = $save($dana, ['kinds' => 'mention,telepathy']);
ok($c === 422 && str_contains(msg($b), 'telepathy'), '422 naming the unknown kind: "' . msg($b) . '"');
[$c, $b] = $save($dana, ['kinds' => 'mention', 'text_kinds' => 'mention,dm']);
ok($c === 422 && str_contains(msg($b), 'dm'), 'a text kind not in kinds: 422 naming it: "' . msg($b) . '"');
[$c, $b] = $save($dana, ['text_kinds' => 'reaction']);
ok($c === 422, 'a text kind outside the kinds already saved: 422');
[$c, $b] = $save($dana, ['digest' => 'maybe']);
ok($c === 422 && str_contains(msg($b), 'yes or no'), 'digest says yes or no');
ok(activity('prefs.save', $since) === [], 'nothing was logged for a refusal');

echo "6. Saved, logged, a guest\n";
$since = last_activity_id();
[$c, $b] = $save($dana, ['email_enabled' => 'yes', 'digest' => 'yes', 'kinds' => 'mention,dm', 'text_kinds' => 'dm']);
$l = activity('prefs.save', $since);
$after = json_decode((string) ($l[0]['after'] ?? '{}'), true);
ok($c === 200 && $b['ok'] && $b['refresh'] === 'prefsChanged' && count($l) === 1 && $after['digest'] === true && $after['kinds'] === ['mention', 'dm'], 'saved: the contract, logged prefs.save with only what changed');
$row = q('SELECT kinds, text_kinds, digest FROM notification_prefs WHERE member_id = 30')[0];
ok($row['kinds'] === '{mention,dm}' && $row['text_kinds'] === '{dm}' && $row['digest'] === true, 'the row holds the choices');
$save($dana, $defaults);
[$c, $b] = $save($ann, ['text_enabled' => 'yes', 'text_kinds' => 'mention', 'kinds' => 'mention,dm']);
ok($c === 200 && one('SELECT text_enabled FROM notification_prefs WHERE member_id = 29') === true, 'a guest saves prefs like anyone');
[$c, $d] = screen($ann, '/settings/');
ok($c === 200 && $d['notify']['text_enabled'] === true, 'and reads them back');
$r = page($dana, '/settings/');
ok(str_contains($r['body'], 'id="prefs-field-digest"') && str_contains($r['body'], 'id="prefs-field-away"') && str_contains($r['body'], 'id="prefs-field-kind-mention"') && str_contains($r['body'], 'id="prefs-field-text-kind-dm"'), 'the form: digest, away minutes, a checkbox per kind for both lists');
$r = req('POST', '/settings/prefs.php', ['jar' => $dana, 'form' => ['csrf_token' => page_csrf($dana), 'digest' => 'no', 'email_enabled' => 'yes', 'return_to' => '/settings/?tab=notify']]);
ok($r['code'] === 302 && str_contains($r['location'], '/settings/') && str_contains($r['location'], 'notice=prefs_saved'), 'JavaScript off: the plain form posts and lands back with its notice');
$save($dana, $defaults);
pdo()->exec("UPDATE members SET last_seen_at = now() WHERE id IN (26, 30)");
finish();
