<?php /** Every published page (screen `admin-published`). Data: rows, here, notice, tz */ ?>
<?= view('shared/header.php', ['id' => 'admin-published', 'title' => 'Published pages', 'crumbs' => [['Home', '/'], ['Published pages', null]], 'back' => back_link()]) ?>
<div class="main-content" id="admin-published-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($rows === []): ?><div class="card"><div class="card-body"><div class="empty-state" id="admin-published-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-globe"></i></span><div><div class="fw-semibold">Nothing is on the web</div><div class="fs-12 text-muted">A page someone publishes appears here with how often it was opened.</div></div></div></div></div><?php endif; ?>
    <div class="row g-3" id="admin-published-list">
    <?php foreach ($rows as $p): $id = (int) $p['publication_id']; $page = e($p['page_id']); ?>
        <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="published-row-<?= $id ?>"><div class="card-body p-3 d-flex flex-column">
            <div class="fw-bold text-truncate"><?= hx_link(with_back('/pages/' . $p['page_id'], $here), e((($p['icon'] ?? '') !== '' ? $p['icon'] . ' ' : '') . (($p['title'] ?? '') !== '' ? $p['title'] : 'Untitled')), 'text-dark', 'id="published-row-' . $id . '-title"') ?></div>
            <div class="fs-12 text-muted"><?= e((string) ($p['space_name'] ?? 'Private')) ?> · by <?= e((string) ($p['published_by_name'] ?? 'someone')) ?> · <?= e(format_ts($p['published_at'], $tz, 'M j, Y')) ?></div>
            <div class="d-flex flex-wrap gap-1 mt-1">
                <?php if ($p['noindex']): ?><span class="badge bg-soft-secondary text-secondary" id="published-row-<?= $id ?>-noindex">noindex</span><?php endif; ?>
                <?php if ($p['include_subpages']): ?><span class="badge bg-soft-secondary text-secondary">with subpages</span><?php endif; ?>
            </div>
            <div class="fs-12 mt-1" id="published-row-<?= $id ?>-views"><b><?= (int) $p['views'] ?></b> view<?= $p['views'] === 1 ? '' : 's' ?><?= $p['last_viewed_at'] !== null ? ' · last opened ' . e(format_ts($p['last_viewed_at'], $tz, 'M j, g:i A')) : ' · never opened' ?></div>
            <div class="d-flex flex-wrap gap-2 mt-auto pt-2">
                <form method="post" action="/pages/publish-rotate.php" hx-post="/pages/publish-rotate.php" hx-target="#flash" hx-confirm="Make a new link? The old one stops working."><?= csrf_field() ?><input type="hidden" name="page" value="<?= $page ?>"><input type="hidden" name="return_to" value="/admin/published"><button type="submit" class="btn btn-light btn-touch" id="published-row-<?= $id ?>-rotate-btn">Rotate</button></form>
                <form method="post" action="/pages/unpublish.php" hx-post="/pages/unpublish.php" hx-target="#flash" hx-confirm="Take this page off the web?"><?= csrf_field() ?><input type="hidden" name="page" value="<?= $page ?>"><input type="hidden" name="return_to" value="/admin/published"><button type="submit" class="btn btn-outline-danger btn-touch" id="published-row-<?= $id ?>-unpublish-btn">Unpublish</button></form>
            </div>
        </div></div></div>
    <?php endforeach; ?>
    </div>
</div>
