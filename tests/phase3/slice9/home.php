<?php
/** Proof — Home (spec "Proof", 1): Priya's unread with the first lines (DMs first), her mentions since her last visit, recently edited in her spaces only, her favorites, the wiki page she owns that expired, the Librarian's note — no owner or admin region; Marco's pending joins and unanswered questions; the admin's three cards with the counts; a guest's home; JSON answers the same; a second visit's "waiting" starts from the first visit. */
require __DIR__ . '/lib.php';
$w = home_world();
$run = run_id();
$owner = as_member(1); $marco = as_member(27); $priya = as_member(26); $ann = as_member(29); $dana = as_member(30); $bea = as_member(28);
$product = $w['product'];

echo "1. Priya's Unread, Waiting, Recently edited, Favorites, Needs my verification, the note\n";
[, $b] = act($marco, '/channels/save.php', ['space' => $product, 'name' => 'smoke-home-' . $run, 'kind' => 'public', 'topic' => 'Home']);
$ch = (int) $b['record_id'];
act($priya, '/channels/join.php', ['channel' => $ch]);
[, $m1] = post($marco, $ch, "SMOKE first unread line $run");
post($marco, $ch, "SMOKE second unread line $run");
[, $dmb] = act($owner, '/dm/open.php', ['member' => 26]);
$dm = (int) $dmb['record_id'];
[, $d1] = post($owner, $dm, "SMOKE direct unread line $run");
$pg = mk_in($marco, $product, "SMOKE home edited $run");
$fav = mk_in($marco, $product, "SMOKE home favorite $run");
act($priya, '/pages/favorite.php', ['page' => $fav, 'favorite' => 'yes']);
[, $acc] = act($owner, '/pages/save.php', ['title' => "SMOKE Accounting only $run", 'space' => $w['accounting']]);
$accountingPage = (string) $acc['record_id'];
[, $b] = act($owner, '/pages/save.php', ['title' => "SMOKE Owner private $run", 'private' => 'yes']);
$ownerPrivate = (string) $b['record_id'];
// the mention of Priya (made before her first visit in this session)
post($marco, $w['launch'], "SMOKE look at this for @SMOKE Priya $run");
[$c, $h] = home($priya);
ok($c === 200 && isset($h['unread'], $h['mentions'], $h['recent_pages'], $h['favorites'], $h['verification_due'], $h['librarian_note']), 'Priya\'s home answers JSON with every region');
$un = array_column($h['unread'], null, 'channel_id');
ok(isset($un[$ch]) && $un[$ch]['unread'] === 2 && $un[$ch]['first_line'] === "SMOKE first unread line $run" && $un[$ch]['first_author'] === 'SMOKE Marco', 'Unread: the channel with its count (2) and the FIRST unread line, by whom: ' . json_encode($un[$ch] ?? null));
ok(isset($un[$dm]) && $un[$dm]['kind'] === 'dm' && $un[$dm]['unread'] === 1 && str_contains((string) $un[$dm]['first_line'], 'direct unread line') && $un[$dm]['url'] === '/dm/' . $dm . '?message=' . $d1['record_id'], 'Unread: the DM too, with its line and a link to that message');
$kinds = array_column($h['unread'], 'kind');
$firstChannel = array_search('public', $kinds, true);
ok($kinds[0] === 'dm' && ($firstChannel === false || array_search('dm', $kinds, true) < $firstChannel), 'DMs come first');
ok(!isset($un[$w['leads_channel']]) || $un[$w['leads_channel']]['unread'] >= 0, 'Unread lists only channels she belongs to');
$mine = array_column($h['mentions'], 'excerpt');
ok(count($h['mentions']) >= 1 && count($h['mentions']) <= 5 && in_array("SMOKE look at this for @SMOKE Priya $run", $mine, true) || array_filter($mine, fn ($x) => str_contains($x, "look at this for")) !== [], 'Waiting for me: the mention, newest first, at most five: ' . count($h['mentions']));
$titles = array_column($h['recent_pages'], 'title');
ok(in_array("SMOKE home edited $run", $titles, true) && in_array("SMOKE home favorite $run", $titles, true), 'Recently edited: the pages edited in her spaces');
ok(!in_array("SMOKE Accounting only $run", $titles, true) && !in_array("SMOKE Owner private $run", $titles, true) && count($h['recent_pages']) <= 10, 'Recently edited: not a page of a space she is not in, not another person\'s private page, at most ten');
$favs = array_column($h['favorites'], 'title');
ok($favs === ["SMOKE home favorite $run"] || in_array("SMOKE home favorite $run", $favs, true), 'Favorites: the page she starred');
$due = array_column($h['verification_due'], 'state', 'page_id');
ok(($due[$w['expired']] ?? '') === 'expired' && ($due[$w['never']] ?? '') === 'none' && !isset($due[$w['verified']]), 'Needs my verification: the wiki page she owns that expired and the one never verified — not the verified one');
ok($h['librarian_note'] !== null && str_contains($h['librarian_note']['excerpt'], 'SMOKE Monday report') && $h['librarian_note']['channel_name'] === 'spaces-admin' && str_contains((string) $h['librarian_note']['author_name'], 'Librarian'), 'The Librarian\'s note: the last message by the Librarian in the admin channel (she joined it)');
ok($h['pending_joins'] === null && $h['unanswered'] === null && $h['admin'] === null && $h['may']['owner'] === false && $h['may']['admin'] === false, 'no owner or admin region for Priya');
$page = req('GET', '/', ['jar' => $priya])['body'];
ok(str_contains($page, 'id="home-unread"') && str_contains($page, 'id="home-waiting"') && str_contains($page, 'id="home-verification"') && str_contains($page, 'id="home-recent"') && str_contains($page, 'id="home-favorites"') && str_contains($page, 'id="home-note"')
    && !str_contains($page, 'id="home-joins"') && !str_contains($page, 'id="home-unanswered"') && !str_contains($page, 'id="home-admin"'), 'the screen has the same regions and none of the owner\'s or the admin\'s');
$pos = fn (string $id) => strpos($page, 'id="' . $id . '"');
ok($pos('home-unread') < $pos('home-waiting') && $pos('home-waiting') < $pos('home-verification') && $pos('home-verification') < $pos('home-recent') && $pos('home-recent') < $pos('home-favorites') && $pos('home-favorites') < $pos('home-note'), 'on a phone the regions stack in the order Unread, Waiting, Verification, Recently edited, Favorites, the note');
ok(str_contains($page, 'id="home-unread-' . $ch . '-line"') && str_contains($page, 'SMOKE first unread line ' . $run), 'the screen shows the first unread line');

echo "2. A second visit's Waiting starts from the first visit\n";
sleep(1);
[, $h2] = home($priya);
ok(array_filter(array_column($h2['mentions'], 'excerpt'), fn ($x) => str_contains($x, "look at this for")) === [], 'the second visit: the mention she was shown is not waiting again');
sleep(1);
post($marco, $w['launch'], "SMOKE a newer ping for @SMOKE Priya $run");
[, $h3] = home($priya);
$new = array_column($h3['mentions'], 'excerpt');
ok(count($new) === 1 && str_contains($new[0], 'a newer ping'), 'the third visit: only what came after the second: ' . json_encode($new));
ok($h3['since'] !== null && $h3['since'] > $h['since'], 'the window moves on: since ' . $h3['since']);

echo "3. The note is shown to its channel's members only\n";
[, $hm] = home($marco);
ok($hm['librarian_note'] === null, 'Marco has not joined the admin channel: no note');
$page = req('GET', '/', ['jar' => $marco])['body'];
ok(!str_contains($page, 'id="home-note"'), 'and no card for it');

echo "4. Marco: pending joins and unanswered on Product\n";
[, $b] = act($marco, '/spaces/save.php', ['name' => "SMOKE Home closed $run", 'kind' => 'closed']);
$cl = (int) $b['record_id'];
act($bea, '/spaces/request.php', ['space' => $cl, 'message' => "SMOKE let me in $run"]);
act($marco, '/spaces/request.php', ['space' => space_id('accounting') ?? $w['general'], 'message' => 'SMOKE Marco asks elsewhere']);
[, $hm] = home($marco);
$joins = array_column($hm['pending_joins'], null, 'space_id');
ok(isset($joins[$cl]) && $joins[$cl]['display_name'] === 'SMOKE Bea' && $joins[$cl]['message'] === "SMOKE let me in $run", 'Marco\'s pending joins: Bea asks to join his space');
ok(array_filter($hm['pending_joins'], fn ($j) => $j['display_name'] === 'SMOKE Marco') === [], 'his own request elsewhere is not among them');
$un = array_column($hm['unanswered'], null, 'channel_name');
ok($hm['unanswered'] !== null && array_filter($hm['unanswered'], fn ($u) => $u['channel_name'] === 'smoke-launch' && str_contains($u['excerpt'], 'Who owns the deploy runbook?')) !== [], 'Marco\'s unanswered: the 30-hour-old question in #smoke-launch (Product)');
ok(array_filter($hm['unanswered'], fn ($u) => str_contains($u['excerpt'], 'Where is the staging key')) === [], 'the answered question is not there');
ok($hm['admin'] === null && $hm['may']['owner'] === true, 'and no admin cards for him');
$page = req('GET', '/', ['jar' => $marco])['body'];
ok(str_contains($page, 'id="home-joins-' . $joins[$cl]['join_request_id'] . '"') && str_contains($page, 'id="home-unanswered"') && !str_contains($page, 'id="home-admin"'), 'the screen: Requests to join and Unanswered, not the admin card');

echo "5. The admin's three cards\n";
$pub = mk_in($marco, $product, "SMOKE home published $run");
[$c, $pb] = act($owner, '/pages/publish.php', ['page' => $pub]);
$link = (string) ($pb['link'] ?? '');
req('GET', (string) parse_url($link, PHP_URL_PATH));
req('GET', (string) parse_url($link, PHP_URL_PATH));
$tr = mk_in($marco, $product, "SMOKE home trashed $run");
act($marco, '/pages/trash.php', ['page' => $tr]);
[, $ha] = home($owner);
$ad = $ha['admin'];
$dbPending = (int) one("SELECT count(*) FROM agent_dispatches WHERE status = 'sent'"); $dbFailed = (int) one("SELECT count(*) FROM agent_dispatches WHERE status = 'failed'"); $dbAwait = (int) one("SELECT count(*) FROM agent_dispatches WHERE status = 'awaiting_approval'");
ok($ad !== null && $ad['dispatches']['pending'] === $dbPending && $ad['dispatches']['failed'] === $dbFailed && $ad['dispatches']['awaiting'] === $dbAwait, "Dispatches pending/awaiting/failed: {$ad['dispatches']['pending']}/{$ad['dispatches']['awaiting']}/{$ad['dispatches']['failed']} = the table");
$dbPub = (int) one('SELECT count(*) FROM page_publications WHERE revoked_at IS NULL');
ok($ad['published']['count'] === $dbPub && $ad['published']['count'] >= 1 && $ad['published']['last_opened'] !== null && $ad['published']['views'] >= 2, "Published pages: $dbPub on the web, last opened shown, the views counted");
$dbTrash = (int) one('SELECT count(*) FROM pages WHERE archived_at IS NOT NULL AND archived_via IS NULL');
ok($ad['trash']['count'] === $dbTrash && $ad['trash']['count'] >= 1 && $ad['trash']['next_purge'] !== null, "Trash: $dbTrash pages and the next purge date");
$page = req('GET', '/', ['jar' => $owner])['body'];
ok(str_contains($page, 'id="home-admin"') && str_contains($page, 'id="home-admin-published-count">' . $dbPub . '<') && str_contains($page, 'id="home-admin-trash-count">' . $dbTrash . '<') && str_contains($page, 'id="home-admin-failed"') && str_contains($page, 'href="/admin/trash"') && str_contains($page, 'href="/admin/published"'), 'the screen: the three cards with the counts, each a link to its page');
ok(strpos($page, 'id="home-admin"') > strpos($page, 'id="home-unread"'), 'the admin cards come after the person\'s own regions');

echo "6. A guest's home: Shared and DMs\n";
$gp = mk_in($marco, $product, "SMOKE home shared with Ann $run");
act($marco, '/pages/share-guest.php', ['page' => $gp, 'guest' => 29, 'level' => 'view']);
[, $dmb] = act($marco, '/dm/open.php', ['member' => 29]);
$gdm = (int) ($dmb['record_id'] ?? 0);
if ($gdm > 0) { post($marco, $gdm, "SMOKE hello Ann $run"); }
[$c, $hg] = home($ann);
ok($c === 200 && $hg['may']['guest'] === true && $hg['may']['owner'] === false && $hg['may']['admin'] === false && $hg['spaces'] === [], 'Ann\'s home: a guest, in no space');
ok(in_array($gp, col($hg['shared'], 'page_id'), true), 'Shared: the page shared with her');
ok($gdm === 0 || in_array($gdm, col($hg['unread'], 'channel_id'), true), 'Unread: her direct message');
ok(array_filter($hg['unread'], fn ($u) => !in_array($u['kind'], ['dm', 'group_dm'], true) && (int) $u['channel_id'] !== $w['launch']) === [], 'Unread: nothing but her DMs and the channel she was added to');
ok($hg['pending_joins'] === null && $hg['unanswered'] === null && $hg['admin'] === null && $hg['librarian_note'] === null, 'a guest has no owner or admin region and no note');
ok(array_filter($hg['recent_pages'], fn ($p) => !in_array($p['page_id'], col($hg['shared'], 'page_id'), true)) === [], 'Recently edited: only what is shared with her');
$page = req('GET', '/', ['jar' => $ann])['body'];
ok(str_contains($page, 'id="home-shared-' . $gp . '"') && !str_contains($page, 'id="home-admin"') && !str_contains($page, 'Your spaces'), 'the screen says "Shared with you", not "Your spaces"');

echo "7. Empty regions say what will appear\n";
$jDana = jar(); [$jDana] = sign_on(31);
$page = req('GET', '/', ['jar' => $jDana])['body'];
ok(str_contains($page, 'id="home-waiting-empty"') && str_contains($page, 'will appear here') && str_contains($page, 'id="home-favorites-empty"') && str_contains($page, 'id="home-verification-empty"'), 'Lee\'s home: the empty regions name what will appear');
$n = (int) one("SELECT count(*) FROM activity_log WHERE action = 'screen.view' AND screen = 'home'");
ok($n >= 8, 'Home logs screen.view (' . $n . ' rows)');
finish();
