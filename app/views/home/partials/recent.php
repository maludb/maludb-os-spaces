<?php /** Home — Recently edited (`home-recent`): pages changed in the last 7 days in my spaces. Data: s, tz, here */ $rows = $s['recent_pages']; ?>
<div class="card h-100" id="home-recent"><div class="card-header"><h5 class="card-title mb-0">Recently edited</h5></div>
    <?php if ($rows === []): ?><div class="card-body text-muted fs-12" id="home-recent-empty">Pages edited this week in your spaces will appear here.</div><?php endif; ?>
    <div class="list-group list-group-flush" id="home-recent-list">
    <?php foreach ($rows as $p): ?>
        <div class="list-group-item" id="home-recent-<?= e($p['page_id']) ?>">
            <?= hx_link(with_back('/pages/' . $p['page_id'], $here), e($p['title'] !== '' ? $p['title'] : 'Untitled'), 'fw-semibold text-dark d-block text-truncate') ?>
            <div class="fs-11 text-muted"><?= e((string) ($p['space_name'] ?? 'Private')) ?> · <?= e((string) ($p['editor_name'] ?? 'someone')) ?> · <?= e(format_ts($p['last_edited_at'], $tz, 'M j, g:i A')) ?></div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
