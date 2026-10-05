<?php
/**
 * Helpers for the slice 6 proofs (docs/build-specs/notify-search-wiki.md, "Proof"). Builds on slice 5's lib (its chain: slice 4 → 3 → 2 → 1 → Phase 2). wiki_world() adds, in "SMOKE Product" (closed; Marco its
 * owner, Priya and Dana members), made a wiki: pages of every state — "SMOKE Wiki Verified" (verified today), "SMOKE Wiki Expired" (verified, the date past), "SMOKE Wiki Never" (never), "SMOKE Wiki Stale"
 * (not edited for 200 days), "SMOKE Wiki Hidden" (stale too, but restricted to Marco), "SMOKE Wiki Orphan" (under "SMOKE Wiki Home", nothing links to it), "SMOKE Wiki Linker" (a link to "SMOKE Wiki Target", which is in the trash: a broken
 * link) and two "SMOKE How we deploy"; in #smoke-launch a question 30 hours old and an answered one; and what happened to Priya: Marco's message mentioning her (about the "rate limit"), a reply of Marco's in a thread
 * she is in, Dana's 👍 on her message, Marco's comment on her page, a page of Marco's shared with her. Everything a proof makes is named "SMOKE …".
 */
require dirname(__DIR__) . '/slice5/lib.php';

function age_page(string $uuid, string $what, string $interval): void { pdo()->exec("UPDATE pages SET $what = now() - interval '$interval' WHERE id = '$uuid'"); }
function mk_in(string $jar, int $space, string $title, array $more = []): string
{
    $have = page_by_title($title);
    if ($have !== null) { return $have; }
    [, $b] = act($jar, '/pages/save.php', ['title' => $title, 'space' => $space] + $more);
    return (string) ($b['record_id'] ?? '');
}
function wiki_world(): array
{
    $w = channel_world();
    $marco = as_member(27); $priya = as_member(26); $dana = as_member(30);
    $product = $w['product']; $launch = $w['launch'];
    $w += ['_seen' => true];
    if (!(bool) one('SELECT is_wiki FROM spaces WHERE id = :s', ['s' => $product])) {
        act($marco, '/spaces/members/add.php', ['space' => $product, 'member' => 30]);
        act($marco, '/spaces/wiki.php', ['space' => $product, 'wiki' => 'yes', 'verify_months' => '6']);
    }
    if (page_by_title('SMOKE Wiki Verified') === null) {
        $verified = mk_in($marco, $product, 'SMOKE Wiki Verified');
        $expired = mk_in($marco, $product, 'SMOKE Wiki Expired');
        $never = mk_in($marco, $product, 'SMOKE Wiki Never');
        $stale = mk_in($marco, $product, 'SMOKE Wiki Stale');
        $hidden = mk_in($marco, $product, 'SMOKE Wiki Hidden');
        $orphan = mk_in($marco, $product, 'SMOKE Wiki Orphan', ['parent' => $dup0 = mk_in($marco, $product, 'SMOKE Wiki Home')]);
        $linker = mk_in($marco, $product, 'SMOKE Wiki Linker');
        $target = mk_in($marco, $product, 'SMOKE Wiki Target');
        $dup1 = mk_in($marco, $product, 'SMOKE How we deploy');
        if (one('SELECT count(*) FROM pages WHERE plain_title = :t AND archived_at IS NULL', ['t' => 'SMOKE How we deploy']) < 2) {
            [, $b] = act($marco, '/pages/save.php', ['title' => 'SMOKE How we deploy', 'space' => $product]);
        }
    }
    $verified = page_by_title('SMOKE Wiki Verified'); $expired = page_by_title('SMOKE Wiki Expired'); $never = page_by_title('SMOKE Wiki Never'); $stale = page_by_title('SMOKE Wiki Stale'); $hidden = page_by_title('SMOKE Wiki Hidden');
    if (one("SELECT verification_state FROM pages WHERE id = CAST(:p AS uuid)", ['p' => $verified]) !== 'verified') {
        act($marco, '/pages/verify.php', ['page' => $verified, 'months' => '6']);
        act($marco, '/pages/verify.php', ['page' => $expired, 'months' => '3']);
        pdo()->exec("UPDATE pages SET verify_until = now() - interval '2 days', verified_at = now() - interval '95 days' WHERE id = '$expired'");
        foreach ([$expired, $never, $stale] as $pg) { act($marco, '/pages/owner.php', ['page' => $pg, 'member' => 26]); }       // Priya owns them: someone for the nudge to reach
        age_page($stale, 'last_edited_at', '200 days');
        act($marco, '/pages/restrict.php', ['page' => $hidden]);                                           // in the space, but only Marco reaches it
        age_page($hidden, 'last_edited_at', '200 days');
        $orphan = page_by_title('SMOKE Wiki Orphan'); $linker = page_by_title('SMOKE Wiki Linker'); $target = page_by_title('SMOKE Wiki Target');
        pdo()->exec("DELETE FROM page_links WHERE to_page_id = '$orphan'");
        mkblock($linker, 'link_to_page', ['page_id' => $target]);
        act($marco, '/pages/trash.php', ['page' => $target]);
        // the questions: one 30 hours old with no reply, one answered
        [, $b] = post($dana, $launch, 'SMOKE Who owns the deploy runbook?');
        pdo()->exec("UPDATE messages SET sent_at = now() - interval '30 hours' WHERE id = " . (int) $b['record_id']);
        [, $b] = post($dana, $launch, 'SMOKE Where is the staging key?');
        $answered = (int) $b['record_id'];
        reply($marco, $answered, 'SMOKE In the vault.');
        pdo()->exec("UPDATE messages SET sent_at = now() - interval '30 hours' WHERE id = $answered");
        // what happened to Priya
        post($marco, $launch, 'SMOKE The rate limit ships Friday @SMOKE Priya https://example.com/limits');
        [, $b] = post($priya, $launch, 'SMOKE Is the rate limit per key?');
        $mine = (int) $b['record_id'];
        reply($marco, $mine, 'SMOKE Per key, yes.');
        act($dana, '/channels/messages/react.php', ['message' => $mine, 'emoji' => '👍']);
        $pp = page_by_title('SMOKE Pricing 2027');
        act($marco, '/pages/comments/add.php', ['page' => $pp, 'markdown' => 'SMOKE Marco on the rate limit page']);
        mk_in($marco, $product, 'SMOKE Rate limits', ['markdown' => "The rate limit is 100 a minute per key.\n\nSee https://example.com/docs/limits for the details."]);
        $shared = mk_in($marco, $product, 'SMOKE Shared with Priya', ['private' => 'yes']);
        act($marco, '/pages/share.php', ['page' => $shared, 'member' => 26, 'level' => 'view']);
        // a saved message and a reminder for Priya
        act($priya, '/channels/messages/save.php', ['message' => $mine, 'saved' => 'yes']);
        act($priya, '/reminders/save.php', ['text' => 'SMOKE look at the limits', 'remind_at' => date('Y-m-d\TH:i', time() + 86400 * 2), 'message' => $mine]);
    }
    return $w + ['verified' => page_by_title('SMOKE Wiki Verified'), 'expired' => page_by_title('SMOKE Wiki Expired'), 'never' => page_by_title('SMOKE Wiki Never'), 'stale' => page_by_title('SMOKE Wiki Stale'),
        'hidden' => page_by_title('SMOKE Wiki Hidden'), 'orphan' => page_by_title('SMOKE Wiki Orphan'), 'linker' => page_by_title('SMOKE Wiki Linker'), 'target_id' => (string) one("SELECT id::text FROM pages WHERE plain_title = 'SMOKE Wiki Target' ORDER BY created_at LIMIT 1"),
        'shared' => page_by_title('SMOKE Shared with Priya'), 'priya_msg' => (int) one("SELECT id FROM messages WHERE plain_text = 'SMOKE Is the rate limit per key?' ORDER BY id LIMIT 1")];
}
/** The ids the browser proof drives: the wiki's pages, and two fresh wiki pages (Priya owns them, never verified, never nudged) so a nudge from the browser is a first one. */
function notify_browser_world(): array
{
    $w = wiki_world();
    $marco = as_member(27);
    $fresh = [];
    pdo()->exec("UPDATE channel_members SET notify = 'all', muted_until = NULL WHERE channel_id = " . (int) $w['launch']);
    for ($i = 1; $i <= 3; $i++) { post($marco, $w['launch'], "SMOKE browser mention $i for @SMOKE Priya"); }       // unread notices for her bell, whatever ran before
    foreach (['a' => 'SMOKE Wiki Browser A', 'b' => 'SMOKE Wiki Browser B'] as $k => $t) {
        $title = $t . ' ' . date('His');
        $id = mk_in($marco, $w['product'], $title);
        act($marco, '/pages/owner.php', ['page' => $id, 'member' => 26]);
        $fresh[$k] = $id;
    }
    return ['product' => $w['product'], 'launch' => $w['launch'], 'expired' => $w['expired'], 'never' => $w['never'], 'stale' => $w['stale'], 'priya_msg' => $w['priya_msg'], 'fresh_a' => $fresh['a'], 'fresh_b' => $fresh['b']];
}
/** The pages (by uuid) in a report list of the JSON screen. */
function ids_of(array $list): array { return array_column($list, 'page_id'); }
