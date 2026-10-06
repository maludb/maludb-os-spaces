<?php
/** Proof — the digest (spec "Proof", 2): one email at the hour listing the unread notices, none per event, never twice a day, in the member's time zone; a member without a digest gets per-event mail. */
require __DIR__ . '/lib.php';
$w = worker_world();
$marco = as_member(27);
$launch = $w['launch'];
drain_outbox();
pdo()->exec("UPDATE members SET last_seen_at = now() - interval '3 hours', timezone = 'UTC' WHERE id IN (26, 28, 30)");
pdo()->exec("INSERT INTO notification_prefs (member_id) VALUES (26), (30) ON CONFLICT (member_id) DO UPDATE SET email_enabled = true, text_enabled = false, digest = false, kinds = DEFAULT, text_kinds = DEFAULT");
pdo()->exec("UPDATE notification_prefs SET digest = true WHERE member_id = 26");
pdo()->exec("UPDATE notifications SET read_at = now() WHERE member_id IN (26, 30) AND read_at IS NULL");
pdo()->exec("UPDATE channel_members SET notify = 'all', muted_until = NULL WHERE channel_id = $launch");
$day = date('Y-m-d', strtotime('2040-01-01 +' . (time() % 100000) . ' days'));          // a day nothing has used (a rerun on the same database moves on)
$at = fn (string $hm, ?string $d = null) => ['SP_WORKER_NOW' => ($d ?? $day) . 'T' . $hm . ':00Z'];

echo "1. Three events, no per-event mail for a digest member\n";
$since = last_outbox_id();
foreach (['one', 'two', 'three'] as $n) { post($marco, $launch, "SMOKE digest $n @SMOKE Priya @SMOKE Dana"); }
ok(outbox_rows(null, 26, $since) === [], 'Priya (digest on) had no outbox row for three mentions');
ok(count(outbox_rows('mention', 30, $since)) === 3, 'Dana (digest off) has one email row per event: three');
ok(count(notes(26, 'mention', 0)) >= 3, 'the bell still has all three for Priya');
step('outbox');
ok(count(array_filter(mail_log(), fn ($m) => $m['to'] === 'dana@example.invalid' && str_contains($m['subject'], 'mentioned you in #smoke-launch'))) >= 3, 'Dana\'s three per-event mails went');

echo "2. The digest at the hour\n";
$mails = mail_count();
$r = step('digest', $at('07:30'));
ok(($r['digests'] ?? -1) === 0 && outbox_rows('digest', 26, $since) === [], 'at 07:30 (before the digest hour 8) nothing is made');
$n0 = last_note_id();
$r = step('digest', $at('08:05'));
ok(($r['digests'] ?? 0) === 1, 'at 08:05 one digest is made: ' . json_encode($r));
$d = outbox_rows('digest', 26, $since);
ok(count($d) === 1 && $d[0]['channel'] === 'email' && $d[0]['to_email'] === 'priya@example.invalid' && str_contains($d[0]['subject'], '3 unread notices'), 'one digest row of kind digest for Priya: ' . ($d[0]['subject'] ?? ''));
step('outbox');
$new = array_slice(mail_log(), $mails);
$dm = array_values(array_filter($new, fn ($m) => $m['to'] === 'priya@example.invalid'));
ok(count($dm) === 1, 'one email to Priya, not three');
$txt = $dm[0]['text'] ?? '';
ok(substr_count($txt, 'SMOKE Marco mentioned you') === 3 && substr_count($txt, '/channels/' . $launch . '?message=') === 3, 'it lists the three notices with their links');
ok(str_contains($dm[0]['html'] ?? '', 'Your Spaces digest') && substr_count($dm[0]['html'], 'SMOKE Marco mentioned you') === 3, 'and the HTML view lists them with who and when');
ok(count(notes(26, 'digest', $n0)) === 1, 'a digest notice on the bell marks it');
$r = step('digest', $at('08:20'));
ok(($r['digests'] ?? -1) === 0, 'not twice the same day (at 08:20 the dedupe key holds)');
$r = step('digest', $at('09:05'));
ok(($r['digests'] ?? -1) === 0, 'and not at 09:05 either: the hour has passed');

echo "3. Since the last digest, and the next day\n";
$r = step('digest', $at('08:05', date('Y-m-d', strtotime($day . ' +1 day'))));
ok(($r['digests'] ?? -1) === 0, 'the next morning with nothing new: nothing sent');
post($marco, $launch, 'SMOKE digest four @SMOKE Priya');
$since2 = last_outbox_id();
$r = step('digest', $at('08:05', date('Y-m-d', strtotime($day . ' +2 days'))));
$d2 = outbox_rows('digest', 26, $since2);
ok(($r['digests'] ?? 0) === 1 && count($d2) === 1 && str_contains($d2[0]['subject'], '1 unread notice'), 'two days on, with one new notice: a digest of ONE (only what is unread since the last digest): ' . ($d2[0]['subject'] ?? ''));

echo "4. In the member's time zone\n";
pdo()->exec("UPDATE members SET timezone = 'Asia/Tokyo' WHERE id = 26");
$day2 = date('Y-m-d', strtotime($day . ' +10 days'));
post($marco, $launch, 'SMOKE digest tokyo @SMOKE Priya');
$since3 = last_outbox_id();
$r = step('digest', $at('08:05', $day2));
ok(($r['digests'] ?? -1) === 0, '08:05 UTC is 17:05 in Tokyo: not her hour');
$prev = date('Y-m-d', strtotime($day2 . ' -1 day'));
$r = step('digest', $at('23:05', $prev));
$d3 = outbox_rows('digest', 26, $since3);
ok(($r['digests'] ?? 0) === 1 && count($d3) === 1, '23:05 UTC is 08:05 in Tokyo: her digest');
pdo()->exec("UPDATE members SET timezone = 'UTC' WHERE id = 26");
pdo()->exec("UPDATE notification_prefs SET digest = false WHERE member_id = 26");
echo "5. A member who did not choose a digest never gets one\n";
$r = step('digest', $at('08:05', date('Y-m-d', strtotime($day . ' +20 days'))));
ok(outbox_rows('digest', 30, 0) === [], 'Dana has no digest row: she gets mail per event');
finish();
