<?php /** Home — Favorites (`home-favorites`). Data: s, tz, here */ $rows = $s['favorites']; ?>
<div class="card h-100" id="home-favorites"><div class="card-header"><h5 class="card-title mb-0">Favorites</h5></div>
    <?php if ($rows === []): ?><div class="card-body text-muted fs-12" id="home-favorites-empty">Pages you star will appear here.</div><?php endif; ?>
    <div class="list-group list-group-flush" id="home-favorites-list">
    <?php foreach ($rows as $p): ?>
        <div class="list-group-item" id="home-favorites-<?= e($p['page_id']) ?>">
            <?= hx_link(with_back('/pages/' . $p['page_id'], $here), e((($p['icon'] ?? '') !== '' ? $p['icon'] . ' ' : '') . ($p['title'] !== '' ? $p['title'] : 'Untitled')), 'fw-semibold text-dark d-block text-truncate') ?>
            <?php if (($p['space_name'] ?? '') !== ''): ?><div class="fs-11 text-muted"><?= e($p['space_name']) ?></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>
