<?php /** Retention (screen `admin-retention`). Data: o (retention_overview), may (retention), here, notice */ ?>
<?= view('shared/header.php', ['id' => 'admin-retention', 'title' => 'Retention', 'crumbs' => [['Home', '/'], ['Retention', null]], 'back' => back_link()]) ?>
<div class="main-content" id="admin-retention-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-6"><div class="card h-100" id="retention-versions"><div class="card-body"><div class="fw-semibold">Page versions</div><div class="fs-12 text-muted">Kept for <b id="retention-versions-days"><?= (int) $o['versions_days'] ?></b> days.</div><?= $may['settings'] ? hx_link('/admin/settings#settings-form-version_retention_days', 'Change it in the settings', 'fs-12', 'id="retention-versions-link"') : '' ?></div></div></div>
        <div class="col-12 col-md-6"><div class="card h-100" id="retention-trash"><div class="card-body"><div class="fw-semibold">The trash</div><div class="fs-12 text-muted">Kept for <b id="retention-trash-days"><?= (int) $o['trash_days'] ?></b> days, then purged.</div><?= $may['settings'] ? hx_link('/admin/settings#settings-form-trash_retention_days', 'Change it in the settings', 'fs-12', 'id="retention-trash-link"') : '' ?></div></div></div>
    </div>
    <div class="fs-12 text-muted mb-2" id="retention-summary"><?= (int) $o['with'] ?> channel<?= $o['with'] === 1 ? '' : 's' ?> with a retention, <?= (int) $o['without'] ?> that keep everything.</div>
    <?php foreach ($o['spaces'] as $sp): ?>
    <div class="card mb-3" id="retention-space-<?= (int) $sp['space_id'] ?>"><div class="card-header"><h5 class="card-title mb-0"><?= hx_link(with_back('/spaces/' . $sp['space_id'], $here), e($sp['name']), 'text-dark') ?></h5></div>
        <div class="list-group list-group-flush">
        <?php foreach (array_merge($sp['with'], $sp['without']) as $c): $id = $c['channel_id']; ?>
            <div class="list-group-item" id="retention-row-<?= $id ?>" data-retention="<?= $c['retention_days'] === null ? '' : (int) $c['retention_days'] ?>">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div class="fw-semibold min-w-0 text-truncate"><?= hx_link(with_back('/channels/' . $id, $here), '#' . e($c['name']), 'text-dark') ?></div>
                    <span class="badge <?= $c['retention_days'] === null ? 'bg-soft-secondary text-secondary' : 'bg-soft-warning text-warning' ?>" id="retention-row-<?= $id ?>-state"><?= $c['retention_days'] === null ? 'keeps everything' : 'deletes after ' . (int) $c['retention_days'] . ' days' ?></span>
                    <span class="fs-11 text-muted ms-auto"><?= (int) $c['message_count'] ?> messages</span>
                </div>
                <form method="post" action="/channels/retention.php" hx-post="/channels/retention.php" hx-target="#flash" hx-confirm="Messages older than this are deleted for good. Set it?" class="d-flex gap-2 mt-2"><?= csrf_field() ?><input type="hidden" name="channel" value="<?= $id ?>"><input type="hidden" name="return_to" value="/admin/retention">
                    <input type="number" inputmode="numeric" min="1" max="3650" class="form-control btn-touch" name="days" id="retention-row-<?= $id ?>-days" value="<?= $c['retention_days'] === null ? '' : (int) $c['retention_days'] ?>" placeholder="days, empty keeps everything" aria-label="Days">
                    <button type="submit" class="btn btn-light btn-touch" id="retention-row-<?= $id ?>-set-btn">Set</button></form>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
