<?php /** What sibling applications read of ours (screen `connection-list`). Data: shares, reads, os, tz */ ?>
<?= view('shared/header.php', ['id' => 'connection-list', 'title' => 'Connections', 'crumbs' => [['Home', '/'], ['Connections', null]], 'back' => back_link()]) ?>
<div class="main-content" id="connection-list-content">
    <div class="alert alert-light border fs-12" id="connection-list-note">A sibling application reads these only over a connection a super-admin approved in the OS<?php if ($os !== null): ?>: <a href="<?= e($os) ?>" id="connection-list-os-link">the applications</a><?php endif; ?>. There is no approving it here.</div>
    <h6 class="fw-bold mb-2" id="connection-shares-title">What Spaces shares</h6>
    <div class="row g-2 mb-3" id="connection-shares">
        <?php foreach ($shares as $s): ?><div class="col-12 col-md-6"><div class="card h-100" id="share-<?= e($s['tool']) ?>"><div class="card-body p-3"><div class="fw-bold"><i class="feather-share-2 me-1"></i><?= e($s['tool']) ?></div><div class="fs-12 text-muted mt-1"><?= e($s['description']) ?></div></div></div></div><?php endforeach; ?>
    </div>
    <h6 class="fw-bold mb-2" id="connection-reads-title">Read lately</h6>
    <div id="share-reads">
        <?php if ($reads === []): ?><div class="card" id="share-reads-empty"><div class="card-body text-muted fs-12">No sibling has read anything yet.</div></div><?php endif; ?>
        <?php foreach ($reads as $r): ?><div class="card mb-2" id="share-read-<?= (int) $r['activity_id'] ?>"><div class="card-body p-3 d-flex flex-wrap gap-2 align-items-center">
            <span class="fw-semibold"><?= e($r['application'] !== '' ? $r['application'] : 'an application') ?></span><span class="badge bg-soft-primary text-primary"><?= e($r['tool']) ?></span>
            <span class="fs-12 text-muted ms-auto"><?= $r['rows'] !== null ? (int) $r['rows'] . ' row' . ($r['rows'] === 1 ? '' : 's') . ' · ' : '' ?><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></span></div></div><?php endforeach; ?>
    </div>
</div>
