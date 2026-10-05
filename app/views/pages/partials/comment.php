<?php /** One discussion: the comment, its replies, Reply / Resolve / Edit / Delete by right. Data: c, me, may (comment, full), p, tz, block */ $cid = (string) $c['comment_id']; $resolved = $c['resolved_at'] !== null;
$one = static function (array $x, bool $isReply) use ($me, $may, $p, $tz): string {
    $id = (string) $x['comment_id']; $own = $x['author_member_id'] === $me; $deleted = $x['deleted_at'] !== null;
    $h = '<div class="sp-comment' . ($isReply ? ' sp-comment-reply' : '') . '" id="comment-' . e($id) . '"><div class="d-flex align-items-start gap-2">'
        . '<span class="avatar-text avatar-sm">' . e(mb_strtoupper(mb_substr((string) ($x['author_name'] ?? '?'), 0, 1))) . '</span><div class="min-w-0 flex-grow-1">'
        . '<div class="fs-12"><span class="fw-semibold">' . e($x['author_name'] ?? 'someone') . '</span>' . (!empty($x['author_is_agent']) ? ' <span class="badge bg-soft-info text-info">agent</span>' : '') . ' <span class="text-muted">' . e(format_ts($x['created_at'], $tz, 'M j, g:i A')) . ($x['edited_at'] ? ' · edited' : '') . '</span></div>'
        . '<div class="sp-comment-body">' . ($deleted ? '<span class="text-muted fst-italic">Deleted.</span>' : render_rich_text($x['body'])) . '</div>';
    if (!$deleted && ($own || $may['full'])) {
        $h .= '<div class="d-flex gap-2 mt-1 fs-12">';
        if ($own) { $h .= '<button type="button" class="btn btn-link btn-sm p-0 sp-comment-edit" data-comment="' . e($id) . '">Edit</button>'; }
        $h .= '<form method="post" action="/pages/comments/delete.php" class="sp-comment-form d-inline" data-confirm="Delete this comment?">' . csrf_field() . '<input type="hidden" name="comment" value="' . e($id) . '"><button type="submit" class="btn btn-link btn-sm p-0 text-danger">Delete</button></form></div>';
        if ($own) { $h .= '<form method="post" action="/pages/comments/edit.php" class="sp-comment-form sp-comment-edit-form d-none mt-1" id="comment-' . e($id) . '-edit">' . csrf_field() . '<input type="hidden" name="comment" value="' . e($id) . '"><textarea name="markdown" class="form-control form-control-sm" rows="2" maxlength="10000">' . e(rt_runs_markdown($x['body'])) . '</textarea><button type="submit" class="btn btn-primary btn-sm btn-touch mt-1">Save</button></form>'; }
    }
    return $h . '</div></div></div>';
}; ?>
<div class="card mb-2 sp-discussion<?= $resolved ? ' sp-resolved' : '' ?>" id="discussion-<?= e($cid) ?>" data-block="<?= e((string) ($c['block_id'] ?? '')) ?>">
    <div class="card-body p-2">
        <?php if ($c['block_id'] !== null): ?><div class="fs-11 text-muted mb-1"><a href="#block-<?= e((string) $c['block_id']) ?>" class="sp-comment-anchor" data-block="<?= e((string) $c['block_id']) ?>"><i class="feather-corner-down-right me-1"></i>on a block</a></div><?php endif; ?>
        <?= $one($c, false) ?>
        <?php foreach ($c['replies'] as $r): ?><?= $one($r, true) ?><?php endforeach; ?>
        <div class="d-flex flex-wrap gap-2 mt-2 align-items-center">
            <?php if ($may['comment'] && !$resolved): ?>
            <form method="post" action="/pages/comments/add.php" class="sp-comment-form d-flex gap-1 flex-grow-1" id="comment-<?= e($cid) ?>-reply"><?= csrf_field() ?><input type="hidden" name="page" value="<?= e((string) $p['page_id']) ?>"><input type="hidden" name="parent" value="<?= e($cid) ?>">
                <input type="text" name="markdown" class="form-control form-control-sm" placeholder="Reply…" maxlength="10000" required><button type="submit" class="btn btn-light btn-sm btn-touch">Reply</button></form>
            <?php endif; ?>
            <?php if ($may['comment']): ?>
            <form method="post" action="/pages/comments/resolve.php" class="sp-comment-form"><?= csrf_field() ?><input type="hidden" name="comment" value="<?= e($cid) ?>"><input type="hidden" name="resolved" value="<?= $resolved ? 'no' : 'yes' ?>"><button type="submit" class="btn btn-<?= $resolved ? 'light' : 'outline-success' ?> btn-sm btn-touch" id="comment-<?= e($cid) ?>-resolve-btn"><?= $resolved ? 'Reopen' : 'Resolve' ?></button></form>
            <?php endif; ?>
            <?php if ($resolved): ?><span class="badge bg-soft-success text-success">resolved</span><?php endif; ?>
        </div>
    </div>
</div>
