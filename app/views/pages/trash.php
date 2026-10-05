<?php /** The trash as cards (screen `page-trash`). Data: rows, space, may (purge), here, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'page-trash', 'title' => 'Trash', 'crumbs' => [['Home', '/'], ['Pages', '/pages/'], ['Trash', null]], 'back' => back_link() ?? ['/pages/', 'Pages'],
    'action' => $may['purge'] && $rows !== [] ? '<form method="post" action="/pages/trash-purge.php" hx-post="/pages/trash-purge.php" hx-target="#flash" hx-confirm="Delete everything in the trash for good?">' . csrf_field() . ($space !== null ? '<input type="hidden" name="space" value="' . (int) $space . '">' : '') . '<button type="submit" class="btn btn-outline-danger btn-touch" id="trash-empty-btn">Empty the trash</button></form>' : '']) ?>
<div class="main-content" id="page-trash-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($rows === []): ?><div class="card"><div class="card-body"><div class="empty-state" id="trash-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-trash-2"></i></span><div><div class="fw-semibold">The trash is empty</div><div class="fs-12 text-muted">A trashed page stays here for the retention period, then goes for good.</div></div></div></div></div><?php endif; ?>
    <div class="row g-3" id="trash-cards">
        <?php foreach ($rows as $t): $pid = (string) $t['page_id']; ?>
        <div class="col-12 col-md-6 col-xl-4"><div class="card h-100 border-dark" id="trash-card-<?= e($pid) ?>"><div class="card-body p-3 d-flex flex-column">
            <div class="fw-bold text-truncate"><?= hx_link(with_back('/pages/' . $pid, $here), e(($t['icon'] ?? '') !== '' ? $t['icon'] . ' ' : '') . e($t['plain_title'] !== '' ? $t['plain_title'] : 'Untitled'), 'text-dark') ?></div>
            <div class="fs-12 text-muted mt-1"><?= e($t['space_name'] ?? 'Private') ?> · trashed by <?= e($t['archived_by_name'] ?? 'someone') ?> <?= e(format_ts($t['archived_at'], $tz, 'M j')) ?> · <span class="badge bg-dark">purged on <?= e(format_date($t['purge_at'])) ?></span></div>
            <div class="d-flex gap-2 mt-auto pt-2">
                <form method="post" action="/pages/restore.php" hx-post="/pages/restore.php" hx-target="#flash" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="page" value="<?= e($pid) ?>"><input type="hidden" name="return_to" value="/pages/trash"><button type="submit" class="btn btn-light btn-touch w-100" id="trash-card-<?= e($pid) ?>-restore-btn">Restore</button></form>
                <form method="post" action="/pages/purge.php" hx-post="/pages/purge.php" hx-target="#flash" hx-confirm="Delete <?= e($t['plain_title'] ?: 'this page') ?> for good?" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="page" value="<?= e($pid) ?>"><input type="hidden" name="return_to" value="/pages/trash"><button type="submit" class="btn btn-outline-danger btn-touch w-100" id="trash-card-<?= e($pid) ?>-purge-btn">Delete for good</button></form>
            </div>
        </div></div></div>
        <?php endforeach; ?>
    </div>
</div>
