<?php /** The versions (screen `page-history`). Data: p, rows, here, tz */ $pid = (string) $p['page_id']; ?>
<?= view('shared/header.php', ['id' => 'page-history', 'title' => 'History', 'crumbs' => [['Home', '/'], ['Pages', '/pages/'], [$p['plain_title'] ?: 'Untitled', '/pages/' . $pid], ['History', null]], 'back' => back_link() ?? ['/pages/' . $pid, $p['plain_title'] ?: 'the page']]) ?>
<div class="main-content" id="page-history-content">
    <div class="card" id="page-history-card"><div class="table-responsive"><table class="table table-hover mb-0" id="version-table">
        <thead class="thead-light"><tr><th>Version</th><th>When</th><th>Why</th><th class="d-none d-md-table-cell">By</th><th class="d-none d-md-table-cell">Size</th><th></th></tr></thead>
        <tbody><?php if ($rows === []): ?><tr><td colspan="6" class="text-muted text-center py-4" id="version-empty">No version yet. The worker saves one after an edit settles; locking saves one now.</td></tr><?php endif; ?>
        <?php foreach ($rows as $v): ?><?= view('pages/partials/version-row.php', ['v' => $v, 'p' => $p, 'tz' => $tz]) ?><?php endforeach; ?></tbody>
    </table></div></div>
    <div class="fs-12 text-muted mt-2">Opening a version, comparing two and restoring one are slice 3's.</div>
</div>
