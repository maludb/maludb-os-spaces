<?php /** One version (screen `page-version`). Data: p, v, bodyHtml, diff, changed, otherLabel, against, may (restore), here, tz */ $pid = (string) $p['page_id']; $vn = (int) $v['version_no']; ?>
<?= view('shared/header.php', ['id' => 'page-version', 'title' => 'Version ' . $vn, 'crumbs' => [['Home', '/'], ['Pages', '/pages/'], [$p['plain_title'] ?: 'Untitled', '/pages/' . $pid], ['History', '/pages/' . $pid . '/history'], ['v' . $vn, null]], 'back' => back_link() ?? ['/pages/' . $pid . '/history', 'History'],
    'action' => $may['restore'] ? '<form method="post" action="/pages/versions/restore.php" hx-post="/pages/versions/restore.php" hx-target="#flash" hx-confirm="Restore version ' . $vn . '? The present is saved as a version first.">' . csrf_field() . '<input type="hidden" name="version" value="' . (int) $v['version_id'] . '"><button type="submit" class="btn btn-primary btn-touch" id="version-restore-btn"><i class="feather-rotate-ccw me-1"></i>Restore this version</button></form>' : '']) ?>
<div class="main-content" id="page-version-content">
    <div class="fs-12 text-muted mb-2" id="version-facts">Version <?= $vn ?> · <?= e(str_replace('_', ' ', (string) $v['reason'])) ?> · <?= e($v['saved_by_name'] ?? 'the worker') ?> · <?= e(format_ts($v['created_at'], $tz, 'M j, Y g:i A')) ?> · compared with <strong><?= e($otherLabel) ?></strong>: <span id="version-changed"><?= (int) $changed ?> line<?= $changed === 1 ? '' : 's' ?> differ</span></div>
    <div class="row g-3">
        <div class="col-xl-6"><div class="card h-100" id="version-rendered"><div class="card-header"><h5 class="card-title mb-0">As it was</h5></div><div class="card-body sp-page"><div class="sp-body"><?= $bodyHtml ?></div></div></div></div>
        <div class="col-xl-6"><?= view('pages/diff.php', ['diff' => $diff, 'otherLabel' => $otherLabel]) ?></div>
    </div>
</div>
