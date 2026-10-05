<?php
/** Proof — Saved (spec "Proof", 5): her saved message rendered with its channel and Unsave; her reminders, due and coming, with Done; the two as tabs on a phone; only her own. */
require __DIR__ . '/lib.php';
$w = wiki_world();
$marco = as_member(27); $priya = as_member(26); $dana = as_member(30);
$launch = $w['launch']; $mine = $w['priya_msg'];

echo "1. Saved messages\n";
[$c, $d] = screen($priya, '/saved');
$ids = array_column($d['saved'] ?? [], 'message_id');
ok($c === 200 && in_array($mine, $ids, true), 'the JSON lists her saved message');
$r = page($priya, '/saved');
ok($r['code'] === 200 && str_contains($r['body'], 'id="saved-row-' . $mine . '"') && str_contains($r['body'], 'id="saved-row-' . $mine . '-where"') && str_contains($r['body'], '#smoke-launch'), 'the row names its channel and links to it');
ok(str_contains($r['body'], 'SMOKE Is the rate limit per key?') && str_contains($r['body'], 'id="saved-row-' . $mine . '-unsave-btn"'), 'it shows the words and Remove from Saved');
ok(!str_contains(page($dana, '/saved')['body'], 'id="saved-row-' . $mine . '"'), 'Dana does not see it: Saved is one\'s own');
[, $b] = post($marco, $launch, 'SMOKE **bold** saved thing');
$other = (int) $b['record_id'];
act($priya, '/channels/messages/save.php', ['message' => $other, 'saved' => 'yes']);
$r = page($priya, '/saved');
ok(str_contains($r['body'], '<strong>bold</strong>') || str_contains($r['body'], 'rt-bold') || str_contains($r['body'], 'font-weight'), 'the words are rendered (the rich text, not its Markdown)');
$pos = strpos($r['body'], 'id="saved-row-' . $other . '"'); $pos2 = strpos($r['body'], 'id="saved-row-' . $mine . '"');
ok($pos !== false && $pos2 !== false && $pos < $pos2, 'newest saved first');
[$c, $b] = act($priya, '/channels/messages/save.php', ['message' => $other, 'saved' => 'no', 'return_to' => '/saved?notice=unsaved']);
ok($c === 200 && !str_contains(page($priya, '/saved')['body'], 'id="saved-row-' . $other . '"'), 'Unsave: it goes');
$r = page($priya, '/saved?notice=unsaved');
ok(str_contains($r['body'], 'Removed from Saved.'), 'the notice says so');

echo "2. Reminders, due and coming, with Done\n";
$due = (int) act($priya, '/reminders/save.php', ['text' => 'SMOKE due now', 'remind_at' => date('Y-m-d\TH:i', time() + 7200)])[1]['record_id'];
pdo()->exec("UPDATE reminders SET remind_at = now() - interval '5 minutes' WHERE id = $due");
$coming = (int) one("SELECT id FROM reminders WHERE member_id = 26 AND text = 'SMOKE look at the limits' ORDER BY id LIMIT 1");
$r = page($priya, '/saved?tab=reminders');
ok(str_contains($r['body'], 'id="saved-reminder-' . $due . '"') && str_contains($r['body'], 'id="saved-reminder-' . $due . '-state">due<') && str_contains($r['body'], 'id="saved-reminder-' . $coming . '-state">coming<'), 'one due, one coming');
ok(str_contains($r['body'], 'id="saved-reminder-' . $due . '-done-btn"'), 'each with Done');
ok(str_contains($r['body'], 'id="saved-tab-saved"') && str_contains($r['body'], 'id="saved-tab-reminders"') && str_contains($r['body'], 'id="saved-pane-reminders"') && !preg_match('/class="[^"]*d-none[^"]*" id="saved-pane-reminders"/', $r['body']) && preg_match('/class="[^"]*d-none d-md-block" id="saved-pane-saved"/', $r['body']) === 1,
    'the two are tabs on a phone: ?tab=reminders shows the reminders pane and hides the saved one below 768 px');
$r = page($priya, '/saved');
ok(preg_match('/class="[^"]*d-none d-md-block" id="saved-pane-reminders"/', $r['body']) === 1 && !preg_match('/class="[^"]*d-none[^"]*" id="saved-pane-saved"/', $r['body']), 'and the default is the saved pane');
[$c, $d] = screen($priya, '/saved');
$rem = array_column($d['reminders'], 'state', 'reminder_id');
ok(($rem[$due] ?? '') === 'due' && ($rem[$coming] ?? '') === 'coming', 'the JSON carries the states');
$since = last_activity_id();
[$c, $b] = act($priya, '/reminders/done.php', ['reminder' => $due, 'return_to' => '/saved?tab=reminders']);
ok($c === 200 && $b['ok'] && str_contains($b['location'], '/saved?tab=reminders') && count(activity('reminder.done', $since)) === 1, 'Done: the contract, back to the tab, logged reminder.done');
ok(!str_contains(page($priya, '/saved?tab=reminders')['body'], 'id="saved-reminder-' . $due . '"') && one('SELECT done_at FROM reminders WHERE id = :i', ['i' => $due]) !== null, 'a done reminder leaves the list');
[$c, $b] = act($dana, '/reminders/done.php', ['reminder' => $coming]);
ok($c === 404 && one('SELECT done_at FROM reminders WHERE id = :i', ['i' => $coming]) === null, 'someone else\'s reminder: 404, untouched');
ok(!str_contains(page($dana, '/saved?tab=reminders')['body'], 'id="saved-reminder-' . $coming . '"'), 'and not on their screen');
finish();
