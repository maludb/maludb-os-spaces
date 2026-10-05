<?php
/** Proof — comments (spec "Proof", 6): inline with a mention notifies; replies one level; resolve; who edits, who deletes; the pane. */
require __DIR__ . '/lib.php';
$w = editor_world();
$marco = as_member(27); $priya = as_member(26); $ann = as_member(29); $dana = as_member(30); $bea = as_member(28);
[, $b] = act($marco, '/pages/save.php', ['title' => 'SMOKE Discussed', 'space' => $w['product'], 'markdown' => 'a line to discuss']);
$dp = (string) $b['record_id'];
$blk = root_blocks($dp)[0]['id'];
act($marco, '/pages/share-guest.php', ['page' => $dp, 'guest' => 29, 'level' => 'comment']);
pdo()->exec("SELECT set_config('app.member_id', '27', false)");   // the proof's own reads through the MCP views are Marco's

echo "1. An inline comment with a mention\n";
$since = last_activity_id();
$before = (int) one("SELECT count(*) FROM notifications WHERE member_id = 27 AND kind = 'comment'");
[$c, $b] = act($priya, '/pages/comments/add.php', ['page' => $dp, 'block' => $blk, 'markdown' => 'Is this right, @SMOKE Marco?']);
$c1 = (string) ($b['record_id'] ?? '');
ok($c === 200 && is_uuid($c1) && ($b['did'] ?? '') === 'Started a discussion' && ($b['refresh'] ?? '') === 'commentChanged', 'comment_add on a block: a discussion');
$row = q('SELECT block_id::text AS block_id, body::text AS body, plain_text FROM comments WHERE id = CAST(:c AS uuid)', ['c' => $c1])[0];
ok($row['block_id'] === $blk && str_contains($row['body'], '"mention"') && $row['plain_text'] === 'Is this right, @SMOKE Marco?', 'on the block; the body holds a member mention');
ok((int) one("SELECT count(*) FROM notifications WHERE member_id = 27 AND kind = 'comment'") === $before + 1 && (int) one("SELECT count(*) FROM comment_mentions WHERE comment_id = CAST(:c AS uuid) AND principal_id = 27", ['c' => $c1]) === 1, 'Marco is told (the mention row and the notification)');
$log = activity('comment.add', $since);
$after = json_decode((string) $log[0]['after'], true);
ok(count($log) === 1 && $log[0]['entity_uuid'] === $c1 && $after['block_id'] === $blk && $after['length'] === 28 && !str_contains((string) $log[0]['after'], 'right'), 'comment.add logged with the length, never the words');
ok((int) one("SELECT open_comment_count FROM mcp_pages WHERE page_id = CAST(:p AS uuid)", ['p' => $dp]) === 1, 'the page counts one open discussion');

echo "2. Replies, one level\n";
[$c, $b] = act($marco, '/pages/comments/add.php', ['page' => $dp, 'parent' => $c1, 'markdown' => 'Yes.']);
$r1 = (string) ($b['record_id'] ?? '');
ok($c === 200 && ($b['did'] ?? '') === 'Replied' && one('SELECT parent_comment_id::text FROM comments WHERE id = CAST(:c AS uuid)', ['c' => $r1]) === $c1, 'a reply joins the discussion');
[$c, $b] = act($priya, '/pages/comments/add.php', ['page' => $dp, 'parent' => $r1, 'markdown' => 'Deeper?']);
ok($c === 422 && str_contains(msg($b), 'one level'), 'a reply to a reply: refused in words');
[$c, $b] = act($ann, '/pages/comments/add.php', ['page' => $dp, 'markdown' => 'A guest comments.']);
$g1 = (string) ($b['record_id'] ?? '');
ok($c === 200 && is_uuid($g1), 'Ann (comment level) comments on the page');
[$c, $b] = act($ann, '/pages/comments/edit.php', ['comment' => $c1, 'markdown' => 'hacked']);
ok($c === 403 && msg($b) === 'Only the author edits a comment.', 'she cannot edit Priya\'s');
[$c, $b] = act($ann, '/pages/comments/edit.php', ['comment' => $g1, 'markdown' => 'A guest comments, edited.']);
ok($c === 200 && one('SELECT plain_text FROM comments WHERE id = CAST(:c AS uuid)', ['c' => $g1]) === 'A guest comments, edited.' && one('SELECT edited_at FROM comments WHERE id = CAST(:c AS uuid)', ['c' => $g1]) !== null, 'she edits her own (edited_at set)');
[$c, $b] = act($bea, '/pages/comments/add.php', ['page' => $dp, 'markdown' => 'x']);
ok($c === 404, 'Bea: 404');
[$c, $b] = act($priya, '/pages/comments/add.php', ['page' => $dp, 'markdown' => '']);
ok($c === 422 && isset(fields($b)['markdown']), 'nothing said: 422');

echo "3. Resolve\n";
[$c, $b] = act($marco, '/pages/comments/resolve.php', ['comment' => $c1]);
ok($c === 200 && one('SELECT resolved_at FROM comments WHERE id = CAST(:c AS uuid)', ['c' => $c1]) !== null && (int) one("SELECT open_comment_count FROM mcp_pages WHERE page_id = CAST(:p AS uuid)", ['p' => $dp]) === 1, 'resolved — the page counts one open discussion (Ann\'s)');
[$c, $b] = act($priya, '/pages/comments/add.php', ['page' => $dp, 'parent' => $c1, 'markdown' => 'Too late?']);
ok($c === 422 && str_contains(msg($b), 'resolved'), 'a reply to a resolved discussion: refused in words');
[$c, $b] = act($marco, '/pages/comments/resolve.php', ['comment' => $c1]);
ok($c === 422 && msg($b) === 'That discussion is resolved already.', 'resolving twice: the sentence');
[$c, $b] = act($priya, '/pages/comments/resolve.php', ['comment' => $c1, 'resolved' => 'no']);
ok($c === 200 && one('SELECT resolved_at FROM comments WHERE id = CAST(:c AS uuid)', ['c' => $c1]) === null, 'reopened');
[$c, $b] = act($marco, '/pages/comments/resolve.php', ['comment' => $r1]);
ok($c === 422 && msg($b) === 'Resolve the discussion, not a reply', 'resolving a reply: the database\'s words');

echo "4. Delete: own only; full deletes any\n";
[$c, $b] = act($ann, '/pages/comments/delete.php', ['comment' => $c1]);
ok($c === 403, 'Ann cannot delete Priya\'s');
[$c, $b] = act($ann, '/pages/comments/delete.php', ['comment' => $g1]);
ok($c === 200 && one('SELECT deleted_at FROM comments WHERE id = CAST(:c AS uuid)', ['c' => $g1]) !== null, 'she deletes her own (a tombstone)');
[$c, $b] = act($dana, '/pages/comments/delete.php', ['comment' => $r1]);
ok($c === 403, 'Dana (edit, not full) cannot delete Marco\'s reply');
[$c, $b] = act($marco, '/pages/comments/delete.php', ['comment' => $r1]);
ok($c === 200, 'Marco (full) deletes any');
[$c, $b] = act($marco, '/pages/comments/delete.php', ['comment' => $r1]);
ok($c === 404 || $c === 422, 'twice: refused');

echo "5. The pane\n";
$r = page($priya, '/pages/comments.php?page=' . $dp);
ok($r['code'] === 200 && str_contains($r['body'], 'id="comment-pane"') && str_contains($r['body'], 'id="discussion-' . $c1 . '"') && str_contains($r['body'], 'Is this right') && str_contains($r['body'], 'id="comment-new"') && str_contains($r['headers'] ?? '', 'X-Pane-Title: Comments'), 'the pane lists the discussion with the new-comment form');
ok(!str_contains($r['body'], 'A guest comments'), 'a deleted discussion is not shown');
$r = page($priya, '/pages/comments.php?page=' . $dp . '&block=' . $blk);
ok($r['code'] === 200 && str_contains($r['body'], 'Comments on one block'), 'the pane for one block');
[$c, $d] = screen($priya, '/pages/comments.php?page=' . $dp);
ok($c === 200 && count($d['comments']) === 1 && ($d['comments'][0]['markdown'] ?? '') === 'Is this right, @SMOKE Marco?' && count($d['comments'][0]['replies']) === 1 && ($d['comments'][0]['replies'][0]['deleted'] ?? false) === true, 'as JSON: the discussion, its reply a tombstone');
$r = page($ann, '/pages/comments.php?page=' . $dp);
ok($r['code'] === 200 && !str_contains($r['body'], 'sp-comment-edit-form'), 'Ann sees no edit form on others\' comments');
$r = page($bea, '/pages/comments.php?page=' . $dp);
ok($r['code'] === 404, 'Bea: 404');
$r = page($marco, '/pages/' . $dp);
ok(str_contains($r['body'], 'id="page-comments-btn"') && str_contains($r['body'], '1 open discussion') && str_contains($r['body'], 'comment_counts'), 'the page shows the open count and the editor knows the markers');
finish();
