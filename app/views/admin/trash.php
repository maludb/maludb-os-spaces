<?php /** Everyone's trash (screen `admin-trash`). Data: t (admin_trash), space, may (purge), here, tz, notice */
$rows = $t['rows']; ?>
<?= view('shared/header.php', ['id' => 'admin-trash', 'title' => 'Everyone\'s trash', 'crumbs' => [['Home', '/'], ['Everyone\'s trash', null]], 'back' => back_link()]) ?>
<div class="main-content" id="admin-trash-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <form method="get" action="/admin/trash" id="admin-trash-filters"><select name="space" class="form-select w-auto btn-touch" id="admin-trash-filter-space" aria-label="Space" onchange="this.form.submit()"><option value="">Every space</option><?php foreach ($t['spaces'] as $sid => $n): ?><option value="<?= (int) $sid ?>" <?= $space === $sid ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></form>
        <?php if ($may['purge'] && $rows !== []): ?>
        <form method="post" action="/pages/trash-purge.php" hx-post="/pages/trash-purge.php" hx-target="#flash" hx-confirm="Delete <?= (int) $t['count'] ?> page<?= $t['count'] === 1 ? '' : 's' ?> in the trash for good? This cannot be undone." class="ms-auto"><?= csrf_field() ?><?= $space !== null ? '<input type="hidden" name="space" value="' . (int) $space . '">' : '' ?><input type="hidden" name="return_to" value="/admin/trash">
            <button type="submit" class="btn btn-outline-danger btn-touch" id="admin-trash-purge-all-btn">Purge all (<?= (int) $t['count'] ?>)</button></form>
        <?php endif; ?>
    </div>
    <div class="alert alert-light border fs-12" id="admin-trash-preview"><?= $rows === [] ? 'The trash is empty.' : 'Purge all would delete <b id="admin-trash-count">' . (int) $t['count'] . '</b> page' . ($t['count'] === 1 ? '' : 's') . ' for good' . ($space !== null ? ' from this space' : '') . ', with their subpages and files.' ?></div>
    <?php if ($rows === []): ?><div class="card"><div class="card-body"><div class="empty-state" id="admin-trash-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-trash-2"></i></span><div><div class="fw-semibold">Nothing in the trash</div><div class="fs-12 text-muted">Trashed pages wait here until they are restored or purged.</div></div></div></div></div><?php endif; ?>
    <div class="row g-3" id="admin-trash-list">
    <?php foreach ($rows as $r): echo view('admin/partials/trash-row.php', ['t' => $r, 'may' => $may, 'here' => $here, 'tz' => $tz]); endforeach; ?>
    </div>
</div>
