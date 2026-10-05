<?php /** The versions (screen `page-history`). Data: p, rows, maySave, here, tz, notice */ $pid = (string) $p['page_id']; ?>
<?= view('shared/header.php', ['id' => 'page-history', 'title' => 'History', 'crumbs' => [['Home', '/'], ['Pages', '/pages/'], [$p['plain_title'] ?: 'Untitled', '/pages/' . $pid], ['History', null]], 'back' => back_link() ?? ['/pages/' . $pid, $p['plain_title'] ?: 'the page'],
    'action' => $maySave ? '<form method="post" action="/pages/versions/save.php" hx-post="/pages/versions/save.php" hx-target="#flash">' . csrf_field() . '<input type="hidden" name="page" value="' . e($pid) . '"><input type="hidden" name="return_to" value="/pages/' . e($pid) . '/history"><button type="submit" class="btn btn-primary btn-touch" id="version-save-btn"><i class="feather-save me-1"></i>Save a version now</button></form>' : '']) ?>
<div class="main-content" id="page-history-content">
    <?= view('shared/notice.php', ['notice' => $notice ?? null]) ?>
    <div class="card" id="page-history-card"><div class="table-responsive"><table class="table table-hover mb-0" id="version-table">
        <thead class="thead-light"><tr><th>Version</th><th>When</th><th>Why</th><th class="d-none d-md-table-cell">By</th><th class="d-none d-md-table-cell">Size</th><th></th></tr></thead>
        <tbody><?php if ($rows === []): ?><tr><td colspan="6" class="text-muted text-center py-4" id="version-empty">No version yet. The worker saves one after an edit settles; locking saves one now.</td></tr><?php endif; ?>
        <?php foreach ($rows as $v): ?><?= view('pages/partials/version-row.php', ['v' => $v, 'p' => $p, 'tz' => $tz]) ?><?php endforeach; ?></tbody>
    </table></div></div>
    <div class="fs-12 text-muted mt-2">Open a version to read it as it was and see what differs; Compare sets it against the one before; Restore saves the present first.</div>
</div>
