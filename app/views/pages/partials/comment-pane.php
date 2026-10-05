<?php /** The comment pane (the right pane at 1280, a full page at 375). Data: p, rows, block, me, may (comment, full), tz */ $pid = (string) $p['page_id']; ?>
<div id="comment-pane" data-page="<?= e($pid) ?>" data-block="<?= e((string) ($block ?? '')) ?>">
    <?php if ($block !== null): ?><div class="fs-12 text-muted mb-2">Comments on one block · <a href="#" id="comment-pane-all" data-block="">all of the page</a></div><?php endif; ?>
    <?php if ($may['comment']): ?>
    <form method="post" action="/pages/comments/add.php" class="sp-comment-form mb-3" id="comment-new"><?= csrf_field() ?><input type="hidden" name="page" value="<?= e($pid) ?>"><?php if ($block !== null): ?><input type="hidden" name="block" value="<?= e($block) ?>"><?php endif; ?>
        <textarea name="markdown" class="form-control" rows="2" maxlength="10000" placeholder="<?= $block !== null ? 'Comment on this block…' : 'Comment on the page…' ?>" required id="comment-new-field"></textarea>
        <button type="submit" class="btn btn-primary btn-sm btn-touch mt-1" id="comment-new-btn">Comment</button></form>
    <?php endif; ?>
    <?php if ($rows === []): ?><div class="text-muted fs-12" id="comment-pane-empty">No discussion yet.</div><?php endif; ?>
    <?php foreach ($rows as $c): ?><?= view('pages/partials/comment.php', ['c' => $c, 'me' => $me, 'may' => $may, 'p' => $p, 'tz' => $tz, 'block' => $block]) ?><?php endforeach; ?>
</div>
