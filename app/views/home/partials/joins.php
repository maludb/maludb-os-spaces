<?php /** Home — Pending joins (`home-joins`), for a space owner. Data: s, tz, here */ $rows = $s['pending_joins']; ?>
<div class="card h-100" id="home-joins"><div class="card-header"><h5 class="card-title mb-0">Requests to join</h5></div>
    <?php if ($rows === []): ?><div class="card-body text-muted fs-12" id="home-joins-empty">People asking to join a space you own will appear here.</div><?php endif; ?>
    <div class="list-group list-group-flush" id="home-joins-list">
    <?php foreach ($rows as $r): ?>
        <div class="list-group-item" id="home-joins-<?= $r['join_request_id'] ?>">
            <div><span class="fw-semibold"><?= e($r['display_name']) ?></span> <span class="text-muted">asks to join</span> <?= hx_link(with_back('/spaces/' . $r['space_id'] . '/requests', $here), e((string) $r['space_name']), 'text-dark fw-semibold') ?></div>
            <?php if (($r['message'] ?? '') !== ''): ?><div class="fs-12 text-muted text-truncate"><?= e($r['message']) ?></div><?php endif; ?>
            <div class="fs-11 text-muted"><?= e(format_ts($r['created_at'], $tz, 'M j, g:i A')) ?></div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
