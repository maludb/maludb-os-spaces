<?php
/**
 * Helpers for the slice 9 proofs (docs/build-specs/home-admin.md, "Proof"). Builds on slice 8's lib (the chain: 8 → 7 → 6 → 5 → 4 → 3 → 2 → 1 → Phase 2). home_world() composes the worlds of slices 1–8 — the agents
 * (Seamus 40, the Librarian 42), the wiki's pages of every state (Priya owns the expired and the never-verified), the channel `#smoke-launch` with a question 30 hours old — and adds what Home reads: the admin channel
 * (`spaces-admin`, in General, Priya in it, the Librarian's note), a private space Marco does not share with the admin, a published page, a trashed page. Everything a proof makes is named "SMOKE …".
 */
require dirname(__DIR__) . '/slice8/lib.php';

/** A fresh run suffix: names made by a proof never collide with an earlier run's. */
function run_id(): string { static $r = null; return $r ??= substr(md5((string) microtime(true) . getmypid()), 0, 6); }

/** The members' home as JSON data: [status, data]. */
function home(string $jar): array { [$c, $d] = screen($jar, '/'); return [$c, $d]; }
/** The ids of a list of rows under a key. */
function col(array $rows, string $k): array { return array_values(array_map(fn ($r) => $r[$k], $rows)); }

/** The Librarian's run token (facts for the fake kernel set once). */
function librarian_token(): array
{
    kernel_state(function ($s) { $s['facts']['4901'] = ['valid' => true, 'is_agent' => true, 'member_id' => 42, 'run_id' => 4901, 'request_id' => 'req-4901', 'trigger' => 'duty', 'endpoints' => [['name' => 'Actions MCP']]]; return $s; });
    return as_agent(run_token(42, 4901));
}

function home_world(): array
{
    $w = worker_world();
    $k = wiki_world();
    $owner = as_member(1); $marco = as_member(27); $priya = as_member(26);
    $admin = channel_by_name($w['general'], 'spaces-admin');
    if ($admin === null) {
        [, $b] = act($owner, '/channels/save.php', ['space' => $w['general'], 'name' => 'spaces-admin', 'kind' => 'public', 'topic' => 'The Librarian reports here']);
        $admin = (int) $b['record_id'];
        act($priya, '/channels/join.php', ['channel' => $admin]);
        act($owner, '/channels/members/add.php', ['channel' => $admin, 'member' => 42]);
        if (!in_array(42, array_map('intval', array_column(q('SELECT member_id FROM space_members WHERE space_id = :s', ['s' => $w['general']]), 'member_id')), true)) {
            act($owner, '/spaces/members/add.php', ['space' => $w['general'], 'member' => 42]);
        }
        act_token('/channels/messages/post.php', ['channel' => $admin, 'markdown' => 'SMOKE Monday report: 2 pages expired, 1 orphan, 3 questions unanswered.'], librarian_token());
    }
    return $w + $k + ['admin_channel' => $admin];
}

/** The ids the browser proof drives (several members' homes). */
function home_browser_world(): array
{
    $w = home_world();
    $owner = as_member(1); $marco = as_member(27); $priya = as_member(26); $bea = as_member(28); $dana = as_member(30);
    $run = run_id();
    // Priya's unread: a fresh channel, three messages, a DM from the admin
    [, $b] = act($marco, '/channels/save.php', ['space' => $w['product'], 'name' => 'smoke-home-' . $run, 'kind' => 'public', 'topic' => 'Home proof']);
    $ch = (int) $b['record_id'];
    act($priya, '/channels/join.php', ['channel' => $ch]);
    foreach (['one', 'two', 'three'] as $n) { post($marco, $ch, "SMOKE browser unread $n $run"); }
    [, $b] = act($owner, '/dm/open.php', ['member' => 26]);
    post($owner, (int) $b['record_id'], "SMOKE browser DM $run");
    post($marco, $w['launch'], "SMOKE browser mention for @SMOKE Priya $run");
    $pg = mk_in($marco, $w['product'], "SMOKE browser favorite $run");
    act($priya, '/pages/favorite.php', ['page' => $pg, 'favorite' => 'yes']);
    // a request to join (Marco's) and a published and a trashed page (the admin's)
    $closed = $w['product'];
    [, $b] = act($marco, '/spaces/save.php', ['name' => "SMOKE Closed $run", 'kind' => 'closed']);
    $cl = (int) $b['record_id'];
    act($dana, '/spaces/request.php', ['space' => $cl, 'message' => "SMOKE let me in $run"]);
    $pub = mk_in($marco, $w['product'], "SMOKE browser published $run");
    [, $pb] = act($owner, '/pages/publish.php', ['page' => $pub, 'include_subpages' => 'yes']);
    $tr = mk_in($marco, $w['product'], "SMOKE browser trashed $run");
    act($marco, '/pages/trash.php', ['page' => $tr]);
    return ['product' => $w['product'], 'launch' => $w['launch'], 'admin_channel' => $w['admin_channel'], 'channel' => $ch, 'closed' => $cl, 'published' => $pub, 'trashed' => $tr, 'expired' => $w['expired'], 'run' => $run, 'public_link' => $pb['link'] ?? ''];
}
