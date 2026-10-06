<?php /** One space for the admin (`admin-space-row-{id}`): a card; the private ones marked `feather-lock` dark. Data: s, here */ $id = (int) $s['space_id']; $private = $s['kind'] === 'private'; $arch = $s['archived_at'] !== null; ?>
<div class="col-12 col-md-6 col-xl-4"><div class="card h-100<?= $private ? ' border-dark' : '' ?>" id="admin-space-row-<?= $id ?>" data-kind="<?= e($s['kind']) ?>"><div class="card-body p-3 d-flex flex-column">
    <div class="d-flex align-items-center gap-2 mb-1">
        <div class="fw-bold min-w-0 text-truncate"><?= hx_link(with_back('/spaces/' . $id, $here), e((($s['icon'] ?? '') !== '' ? $s['icon'] . ' ' : '') . $s['name']), 'text-dark', 'id="admin-space-row-' . $id . '-name"') ?></div>
        <?php if ($private): ?><span class="badge bg-dark ms-auto" id="admin-space-row-<?= $id ?>-lock" title="Private"><i class="feather-lock"></i> private</span><?php else: ?><span class="badge bg-soft-secondary text-secondary ms-auto"><?= e($s['kind']) ?></span><?php endif; ?>
    </div>
    <div class="fs-12 text-muted"><?= (int) $s['member_count'] ?> member<?= (int) $s['member_count'] === 1 ? '' : 's' ?> · <?= (int) $s['page_count'] ?> page<?= (int) $s['page_count'] === 1 ? '' : 's' ?> · <?= (int) $s['channel_count'] ?> channel<?= (int) $s['channel_count'] === 1 ? '' : 's' ?><?= ($s['owner_names'] ?? '') !== '' ? ' · owned by ' . e($s['owner_names']) : '' ?></div>
    <?php if ($arch): ?><div class="mt-1"><span class="badge bg-soft-warning text-warning" id="admin-space-row-<?= $id ?>-archived">archived</span></div><?php endif; ?>
    <div class="d-flex flex-wrap gap-2 mt-auto pt-2">
        <?php if ($arch): ?>
        <form method="post" action="/spaces/restore.php" hx-post="/spaces/restore.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="space" value="<?= $id ?>"><input type="hidden" name="return_to" value="/admin/spaces?archived=1"><button type="submit" class="btn btn-light btn-touch" id="admin-space-row-<?= $id ?>-restore-btn">Restore</button></form>
        <form method="post" action="/spaces/delete.php" hx-post="/spaces/delete.php" hx-target="#flash" hx-confirm="Delete <?= e($s['name']) ?> and everything in it for good?"><?= csrf_field() ?><input type="hidden" name="space" value="<?= $id ?>"><input type="hidden" name="return_to" value="/admin/spaces?archived=1"><button type="submit" class="btn btn-outline-danger btn-touch" id="admin-space-row-<?= $id ?>-delete-btn">Delete</button></form>
        <?php elseif (!$s['is_default']): ?>
        <form method="post" action="/spaces/archive.php" hx-post="/spaces/archive.php" hx-target="#flash" hx-confirm="Archive <?= e($s['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="space" value="<?= $id ?>"><input type="hidden" name="return_to" value="/admin/spaces"><button type="submit" class="btn btn-light btn-touch" id="admin-space-row-<?= $id ?>-archive-btn">Archive</button></form>
        <?php endif; ?>
    </div>
</div></div></div>
