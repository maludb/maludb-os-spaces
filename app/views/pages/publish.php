<?php /** The publication (screen `page-publish`). Data: p, pub, token (just made, once), base, here, tz, notice */ $pid = (string) $p['page_id']; ?>
<?= view('shared/header.php', ['id' => 'page-publish', 'title' => 'Publish', 'crumbs' => [['Home', '/'], ['Pages', '/pages/'], [$p['plain_title'] ?: 'Untitled', '/pages/' . $pid], ['Publish', null]], 'back' => back_link() ?? ['/pages/' . $pid, $p['plain_title'] ?: 'the page']]) ?>
<div class="main-content" id="page-publish-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($token !== null): ?><div class="alert alert-success" id="publish-link"><div class="fw-bold mb-1">The public link — copy it now; it is shown once.</div><code class="user-select-all text-break" id="publish-link-value"><?= e($base . '/p/' . $token) ?></code></div><?php endif; ?>
    <?php if ($pub === null): ?>
    <form method="post" action="/pages/publish.php" hx-post="/pages/publish.php" hx-target="#flash" hx-confirm="Put <?= e($p['plain_title'] ?: 'this page') ?> on the public web?" id="publish-form" class="card"><div class="card-header"><h5 class="card-title mb-0">Publish to the web</h5></div><div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="page" value="<?= e($pid) ?>"><input type="hidden" name="return_to" value="/pages/<?= e($pid) ?>/publish">
        <input type="hidden" name="include_subpages" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="publish-field-subpages"><input type="checkbox" class="form-check-input mt-0" name="include_subpages" value="yes" id="publish-field-subpages">With its subpages</label>
        <input type="hidden" name="noindex" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="publish-field-noindex"><input type="checkbox" class="form-check-input mt-0" name="noindex" value="yes" id="publish-field-noindex" checked>Ask search engines not to index it</label>
        <?php if ($p['kind'] === 'database'): ?><label class="form-label fs-12 text-muted" for="publish-field-layout">Layout</label><select name="layout" id="publish-field-layout" class="form-select btn-touch mb-2"><option value="table">Table</option><option value="gallery">Gallery</option></select><?php endif; ?>
        <button type="submit" class="btn btn-primary btn-touch w-100 mt-2" id="publish-btn"><i class="feather-globe me-1"></i>Publish</button>
    </div></form>
    <?php else: ?>
    <div class="card" id="publish-state"><div class="card-header"><h5 class="card-title mb-0">Published</h5></div><div class="card-body">
        <div class="fs-12 text-muted mb-3">By <?= e($pub['published_by_name'] ?? '') ?> on <?= e(format_ts($pub['published_at'], $tz, 'M j, Y')) ?> · <?= $pub['include_subpages'] ? 'with subpages' : 'this page alone' ?> · <?= $pub['noindex'] ? 'not indexed' : 'indexable' ?> · <span id="publish-views"><?= (int) $pub['views'] ?> view<?= $pub['views'] === 1 ? '' : 's' ?></span><?= $pub['last_viewed_at'] ? ', last ' . e(format_ts($pub['last_viewed_at'], $tz, 'M j, g:i A')) : '' ?></div>
        <div class="d-flex flex-wrap gap-2">
            <form method="post" action="/pages/publish-rotate.php" hx-post="/pages/publish-rotate.php" hx-target="#flash" hx-confirm="Make a new link? The old one stops working now."><?= csrf_field() ?><input type="hidden" name="page" value="<?= e($pid) ?>"><input type="hidden" name="return_to" value="/pages/<?= e($pid) ?>/publish"><button type="submit" class="btn btn-light btn-touch" id="publish-rotate-btn"><i class="feather-refresh-cw me-1"></i>New link</button></form>
            <form method="post" action="/pages/unpublish.php" hx-post="/pages/unpublish.php" hx-target="#flash" hx-confirm="Take <?= e($p['plain_title'] ?: 'this page') ?> off the web?"><?= csrf_field() ?><input type="hidden" name="page" value="<?= e($pid) ?>"><input type="hidden" name="return_to" value="/pages/<?= e($pid) ?>/publish"><button type="submit" class="btn btn-light btn-touch text-danger" id="unpublish-btn"><i class="feather-eye-off me-1"></i>Unpublish</button></form>
        </div>
    </div></div>
    <?php endif; ?>
</div>
