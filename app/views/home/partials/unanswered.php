<?php /** Home — Unanswered in my spaces (`home-unanswered`), for a space owner. Data: s, tz, here */ $rows = $s['unanswered']; ?>
<div class="card h-100" id="home-unanswered"><div class="card-header"><h5 class="card-title mb-0">Unanswered in my spaces</h5></div>
    <?php if ($rows === []): ?><div class="card-body text-muted fs-12" id="home-unanswered-empty">Questions in your spaces' channels that nobody has answered will appear here.</div><?php endif; ?>
    <div class="list-group list-group-flush" id="home-unanswered-list">
    <?php foreach ($rows as $u): ?>
        <div class="list-group-item" id="home-unanswered-<?= $u['message_id'] ?>">
            <?= hx_link(with_back('/channels/' . $u['channel_id'] . '?message=' . $u['message_id'], $here), e($u['excerpt']), 'text-dark d-block text-truncate') ?>
            <div class="fs-11 text-muted"><?= e((string) $u['author_name']) ?> in #<?= e((string) $u['channel_name']) ?> · <?= e((string) $u['space_name']) ?> · open <?= (int) round($u['hours_open']) ?> h</div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
