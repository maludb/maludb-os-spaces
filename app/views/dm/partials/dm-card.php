<?php /** One conversation as a card (dm-list). Data: d, here, tz */ $id = (int) $d['channel_id']; $n = (int) $d['unread_count']; ?>
<div class="card h-100<?= $n > 0 ? ' sp-channel-unread' : '' ?>" id="dm-card-<?= $id ?>"><div class="card-body p-3 d-flex flex-column">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="fw-bold min-w-0 text-truncate" id="dm-card-<?= $id ?>-names"><?= hx_link(with_back('/dm/' . $id, $here), '<i class="feather-' . ($d['kind'] === 'dm' ? 'user' : 'users') . ' me-1"></i>' . e((string) ($d['names'] ?? 'Conversation')), 'text-dark') ?><?= $d['has_agent'] ? ' <span class="badge bg-soft-info text-info">agent</span>' : '' ?></div>
        <?php if ($n > 0): ?><span class="badge bg-primary" id="dm-card-<?= $id ?>-unread"><?= $n > 99 ? '99+' : $n ?></span><?php endif; ?>
    </div>
    <div class="fs-12 text-muted mt-2 text-truncate" id="dm-card-<?= $id ?>-last"><?= e((string) ($d['last_line'] ?? 'Nothing said yet.')) ?></div>
    <div class="fs-12 text-muted mt-1"><?= $d['last_message_at'] ? e(format_ts($d['last_message_at'], $tz, 'M j, g:i A')) : '' ?></div>
    <div class="mt-auto pt-2"><?= hx_link(with_back('/dm/' . $id, $here), 'Open', 'btn btn-light btn-touch w-100', 'id="dm-card-' . $id . '-open-btn"') ?></div>
</div></div>
