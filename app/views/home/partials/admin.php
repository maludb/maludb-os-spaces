<?php /** Home — the admin's three cards (`home-admin`): dispatches pending or failed, published pages, the trash. Data: s, tz, here */ $a = $s['admin']; $dp = $a['dispatches']; ?>
<div class="card" id="home-admin"><div class="card-header"><h5 class="card-title mb-0">For the admin</h5></div>
    <div class="card-body"><div class="row g-3">
        <div class="col-12 col-md-4" id="home-admin-dispatches">
            <div class="fw-semibold mb-1"><i class="feather-cpu me-1"></i>Dispatches</div>
            <div class="d-flex flex-wrap gap-1">
                <?= hx_link('/admin/dispatches?status=pending', 'pending <b>' . $dp['pending'] . '</b>', 'badge bg-soft-secondary text-secondary', 'id="home-admin-pending"') ?>
                <?= hx_link('/admin/dispatches?status=awaiting_approval', 'awaiting approval <b>' . $dp['awaiting'] . '</b>', 'badge bg-soft-warning text-warning', 'id="home-admin-awaiting"') ?>
                <?= hx_link('/admin/dispatches?status=failed', 'failed <b>' . $dp['failed'] . '</b>', 'badge bg-soft-' . ($dp['failed'] > 0 ? 'danger text-danger' : 'secondary text-secondary'), 'id="home-admin-failed"') ?>
            </div>
        </div>
        <div class="col-12 col-md-4" id="home-admin-published">
            <div class="fw-semibold mb-1"><i class="feather-globe me-1"></i><?= hx_link('/admin/published', 'Published pages', 'text-dark') ?></div>
            <div class="fs-12"><b id="home-admin-published-count"><?= $a['published']['count'] ?></b> on the web<?= $a['published']['last_opened'] !== null ? ' · last opened ' . e(format_ts($a['published']['last_opened'], $tz, 'M j, g:i A')) : ($a['published']['count'] === 0 ? ' — nothing published' : ' · never opened') ?></div>
        </div>
        <div class="col-12 col-md-4" id="home-admin-trash">
            <div class="fw-semibold mb-1"><i class="feather-trash-2 me-1"></i><?= hx_link('/admin/trash', 'Trash', 'text-dark') ?></div>
            <div class="fs-12"><b id="home-admin-trash-count"><?= $a['trash']['count'] ?></b> page<?= $a['trash']['count'] === 1 ? '' : 's' ?><?= $a['trash']['next_purge'] !== null ? ' · next purge ' . e(format_date($a['trash']['next_purge'])) : ' — nothing waiting' ?></div>
        </div>
    </div></div>
</div>
