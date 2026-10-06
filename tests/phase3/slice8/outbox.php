<?php
/** Proof — the outbox (spec "Proof", 1): an email through MaluMail, a text through K6 (202 and each refusal), a 500 retried with backoff then failed, mail at-most-once, nothing but ids in the trail. */
require __DIR__ . '/lib.php';
$w = worker_world();
$marco = as_member(27);
$launch = $w['launch'];
drain_outbox();
$away = fn (array $ids) => pdo()->exec("UPDATE members SET last_seen_at = now() - interval '3 hours' WHERE id IN (" . implode(',', $ids) . ')');
$away([26, 28, 30]);
pdo()->exec("INSERT INTO notification_prefs (member_id) VALUES (26), (28), (30) ON CONFLICT (member_id) DO UPDATE SET email_enabled = true, text_enabled = false, digest = false, kinds = DEFAULT, text_kinds = DEFAULT");
pdo()->exec("UPDATE channel_members SET notify = 'all', muted_until = NULL WHERE channel_id = $launch");
k6(['mode' => 'ok']);

echo "1. An email\n";
$since = last_outbox_id(); $mails = mail_count(); $logSince = last_activity_id();
[$c, $b] = post($marco, $launch, 'SMOKE outbox hello @SMOKE Priya');
$mid = (int) $b['record_id'];
$rows = outbox_rows('mention', 26, $since);
ok(count($rows) === 1 && $rows[0]['channel'] === 'email' && $rows[0]['status'] === 'queued' && $rows[0]['to_email'] === 'priya@example.invalid', 'the mention queued one email row for Priya (her address on the row)');
$r = step('outbox');
ok(($r['sent'] ?? 0) >= 1 && ($r['failed'] ?? 1) === 0, 'the outbox step sent it: ' . json_encode($r));
$log = mail_log(); $last = end($log);
ok(mail_count() === $mails + 1 && $last['to'] === 'priya@example.invalid' && str_contains($last['subject'], 'SMOKE Marco') , 'the fake MaluMail got one mail to the mirror\'s address with the notice\'s subject');
ok(str_contains($last['text'], '/channels/' . $launch . '?message=' . $mid) && str_contains($last['html'], 'href="http://127.0.0.1:8401/channels/' . $launch . '?message=' . $mid . '"'), 'the text and the HTML carry the link to the message');
ok(($last['from'] ?? '') === 'spaces@example.invalid' && ($last['from_name'] ?? '') === 'Spaces' && str_contains($last['html'], 'Open in Spaces'), 'from the kit\'s MAIL_FROM, the body from the views');
$row = outbox_rows('mention', 26, $since)[0];
ok($row['status'] === 'sent' && str_starts_with((string) $row['provider_ref'], '<mm-') && (int) $row['attempts'] === 1, 'the row is sent with the provider\'s id in provider_ref');
$s = step('outbox');
ok(($s['sent'] ?? -1) === 0 && mail_count() === $mails + 1, 'a second pass sends nothing again');
$ev = q("SELECT action, source, after::text AS after FROM activity_log WHERE id > :s AND action LIKE 'notification.%' ORDER BY id", ['s' => $logSince]);
ok(count($ev) >= 1 && $ev[0]['action'] === 'notification.send' && $ev[0]['source'] === 'cron' && str_contains($ev[0]['after'], '"kind": "mention"') && str_contains($ev[0]['after'], '"channel": "email"'), 'notification.send logged by cron with the channel and the kind');
$blob = json_encode($ev);
ok(!str_contains($blob, 'priya@example') && !str_contains($blob, 'outbox hello') && !str_contains($blob, 'SMOKE Marco'), 'never an address, a subject or a body in the trail');

echo "2. A text through K6\n";
pdo()->exec("UPDATE notification_prefs SET text_enabled = true, text_kinds = '{mention,dm}' WHERE member_id = 26");
$since = last_outbox_id();
[$c, $b] = post($marco, $launch, 'SMOKE outbox text @SMOKE Priya');
$rows = outbox_rows('mention', 26, $since);
$chan = array_column($rows, 'status', 'channel');
ok(count($rows) === 2 && ($chan['email'] ?? '') === 'queued' && ($chan['text'] ?? '') === 'queued', 'a mention for someone with texts on queued an email row AND a text row');
$calls = count(sms_log());
$r = step('outbox');
$t = array_values(array_filter(outbox_rows('mention', 26, $since), fn ($x) => $x['channel'] === 'text'))[0];
$sl = sms_log(); $lastsms = end($sl);
ok(count($sl) === $calls + 1 && (int) $lastsms['member_id'] === 26 && str_starts_with($lastsms['text'], 'Spaces: ') && str_starts_with((string) $lastsms['reference'], 'mention:'), 'K6 was called once: the member, the text (≤ 480), a reference kind:record');
ok($t['status'] === 'sent' && (int) $t['provider_ref'] >= 9000, '202 → sent, with the kernel\'s notification id as provider_ref');

echo "3. Every refusal is a skip, and the email stands\n";
foreach (['no_sender', 'not_held', 'no_verified_phone', 'opted_out', 'rate_limited', 'invalid'] as $code) {
    k6(['mode' => $code]);
    $since = last_outbox_id();
    post($marco, $launch, "SMOKE outbox refusal $code @SMOKE Priya");
    step('outbox');
    $rows = array_column(outbox_rows('mention', 26, $since), null, 'channel');
    ok(($rows['text']['status'] ?? '') === 'skipped' && ($rows['text']['detail'] ?? '') === $code && ($rows['email']['status'] ?? '') === 'sent', "$code → the text is skipped with the code, the email row is sent");
}
$sk = q("SELECT count(*) AS n FROM activity_log WHERE action = 'notification.skip' AND after::text LIKE '%opted_out%'")[0]['n'];
ok((int) $sk >= 1, 'notification.skip logged with the code');

echo "4. A 500 is retried with backoff, then failed after five\n";
k6(['mode' => 'server_error']);
$since = last_outbox_id();
post($marco, $launch, 'SMOKE outbox outage @SMOKE Priya');
step('outbox');
$t = array_values(array_filter(outbox_rows('mention', 26, $since), fn ($x) => $x['channel'] === 'text'))[0];
$mins = (strtotime($t['send_after']) - time()) / 60;
ok($t['status'] === 'queued' && (int) $t['attempts'] === 1 && $mins > 1 && $mins <= 2.1, "attempt 1 failed: queued again, send_after +2 minutes (" . round($mins, 1) . ")");
$calls = count(sms_log());
step('outbox');
ok(count(sms_log()) === $calls, 'not due yet: the next pass does not call K6');
$want = [2 => 4, 3 => 8, 4 => 16];
foreach ($want as $n => $min) {
    pdo()->exec("UPDATE notification_outbox SET send_after = now() - interval '1 second' WHERE id = {$t['id']}");
    step('outbox');
    $t = q('SELECT status, attempts, send_after FROM notification_outbox WHERE id = :i', ['i' => $t['id']])[0] + ['id' => $t['id']];
    $m = (strtotime($t['send_after']) - time()) / 60;
    ok($t['status'] === 'queued' && (int) $t['attempts'] === $n && $m > $min - 1 && $m <= $min + 0.1, "attempt $n failed: +$min minutes (" . round($m, 1) . ')');
}
pdo()->exec("UPDATE notification_outbox SET send_after = now() - interval '1 second' WHERE id = {$t['id']}");
step('outbox');
$t = q('SELECT status, attempts, detail FROM notification_outbox WHERE id = :i', ['i' => $t['id']])[0];
ok($t['status'] === 'failed' && (int) $t['attempts'] === 5, 'the fifth failure is final: failed after five attempts');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'notification.fail' AND after::text LIKE '%attempts%'") >= 1, 'notification.fail logged');
k6(['mode' => 'ok']);

echo "5. Mail is at-most-once\n";
act($marco, '/spaces/members/add.php', ['space' => $w['product'], 'member' => 28]);
act($marco, '/channels/members/add.php', ['channel' => $launch, 'member' => 28]);
drain_outbox();
pdo()->exec("UPDATE members SET email = 'flaky@example.invalid' WHERE id = 28");
$away([28]);
$since = last_outbox_id();
post($marco, $launch, 'SMOKE outbox flaky @SMOKE Bea');
$rows = outbox_rows('mention', 28, $since);
ok(count($rows) === 1 && $rows[0]['to_email'] === 'flaky@example.invalid', 'a mail for an address the relay refuses is queued');
$mails = mail_count();
step('outbox');
$r = outbox_rows('mention', 28, $since)[0];
ok($r['status'] === 'failed' && mail_count() === $mails + 1, 'MaluMail answered 502: the row is FAILED (it was marked sent before the attempt and is never retried)');
step('outbox'); step('outbox');
ok(mail_count() === $mails + 1, 'no second attempt: a person is never mailed twice');
pdo()->exec("UPDATE members SET email = 'suppressed@example.invalid' WHERE id = 28");
$since = last_outbox_id();
post($marco, $launch, 'SMOKE outbox suppressed @SMOKE Bea');
step('outbox');
$r = outbox_rows('mention', 28, $since)[0];
ok($r['status'] === 'skipped' && $r['detail'] === 'suppressed', 'a suppressed address is skipped with the code');
pdo()->exec("UPDATE members SET email = 'bea@example.invalid' WHERE id = 28");
$blob = json_encode(q("SELECT after::text FROM activity_log WHERE action LIKE 'notification.%' ORDER BY id DESC LIMIT 40"));
ok(!str_contains($blob, 'flaky@') && !str_contains($blob, 'suppressed@') && !str_contains($blob, 'outbox flaky'), 'the trail still holds no address and no body');
finish();
