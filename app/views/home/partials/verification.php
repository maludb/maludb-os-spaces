<?php /** Home — Needs my verification (`home-verification`): wiki pages I own that expired or were never verified. Data: s, tz, here */ $rows = $s['verification_due']; ?>
<div class="card h-100" id="home-verification"><div class="card-header"><h5 class="card-title mb-0">Needs my verification</h5></div>
    <?php if ($rows === []): ?><div class="card-body text-muted fs-12" id="home-verification-empty">Wiki pages you own whose verification has expired, or that were never verified, will appear here.</div><?php endif; ?>
    <div class="list-group list-group-flush" id="home-verification-list">
    <?php foreach ($rows as $p): ?>
        <div class="list-group-item" id="home-verification-<?= e($p['page_id']) ?>">
            <div class="d-flex align-items-center gap-2"><?= hx_link(with_back('/pages/' . $p['page_id'], $here), e($p['title'] !== '' ? $p['title'] : 'Untitled'), 'fw-semibold text-dark min-w-0 text-truncate') ?>
                <span class="badge ms-auto bg-soft-<?= $p['verification_state'] === 'expired' ? 'warning text-warning' : 'secondary text-secondary' ?>"><?= $p['verification_state'] === 'expired' ? 'expired' : 'never verified' ?></span></div>
            <div class="fs-11 text-muted"><?= e((string) $p['space_name']) ?><?= $p['verify_until'] !== null ? ' · was due ' . e(format_ts($p['verify_until'], $tz, 'M j')) : '' ?></div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
