<?php /** Home — Waiting for me (`home-waiting`): mentions and replies since the last visit, newest five. Data: s, tz, here */ $rows = $s['mentions']; ?>
<div class="card h-100" id="home-waiting"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">Waiting for me</h5><?= hx_link('/activity', 'All activity', 'ms-auto fs-12', 'id="home-waiting-all"') ?></div>
    <?php if ($rows === []): ?><div class="card-body text-muted fs-12" id="home-waiting-empty">Mentions of you and replies in your threads since you last looked will appear here.</div><?php endif; ?>
    <div class="list-group list-group-flush" id="home-waiting-list">
    <?php foreach ($rows as $i => $r): $url = activity_url($r); ?>
        <div class="list-group-item" id="home-waiting-<?= $i + 1 ?>" data-kind="<?= e($r['kind']) ?>">
            <div class="fs-13"><span class="fw-semibold"><?= e((string) ($r['actor_name'] ?? 'Someone')) ?></span><?= $r['actor_is_agent'] ? ' <span class="badge bg-soft-info text-info">agent</span>' : '' ?> <?= e(activity_words($r)) ?></div>
            <?php if (($r['excerpt'] ?? '') !== ''): ?><div class="fs-12 text-muted text-truncate"><?= $url !== null ? hx_link(with_back($url, $here), e($r['excerpt']), 'text-muted') : e($r['excerpt']) ?></div><?php endif; ?>
            <div class="fs-11 text-muted"><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
