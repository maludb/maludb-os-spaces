<?php /** One channel as a card (channel-browse). Data: c, u (unread facts), here */ $id = (int) $c['channel_id']; $archived = $c['archived_at'] !== null; $n = (int) ($u['unread'] ?? 0); ?>
<div class="card h-100<?= $archived ? ' border-dark' : '' ?><?= $n > 0 ? ' sp-channel-unread' : '' ?>" id="channel-card-<?= $id ?>"><div class="card-body p-3 d-flex flex-column">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="fw-bold min-w-0 text-truncate" id="channel-card-<?= $id ?>-name"><?= hx_link(with_back('/channels/' . $id, $here), '<i class="feather-' . ($c['kind'] === 'private' ? 'lock' : 'hash') . ' me-1"></i>' . e($c['name']), 'text-dark') ?></div>
        <?php if ($n > 0): ?><span class="badge bg-<?= ($u['mentions'] ?? 0) > 0 ? 'danger' : 'primary' ?>" id="channel-card-<?= $id ?>-unread"><?= $n > 99 ? '99+' : $n ?></span><?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-1 mt-1">
        <span class="badge bg-soft-<?= $c['kind'] === 'private' ? 'dark' : 'secondary' ?> text-<?= $c['kind'] === 'private' ? 'dark' : 'secondary' ?>"><?= e($c['kind']) ?></span>
        <?php if ($c['is_default']): ?><span class="badge bg-soft-info text-info">default</span><?php endif; ?>
        <?php if ($c['i_follow']): ?><span class="badge bg-soft-primary text-primary" id="channel-card-<?= $id ?>-following"><?= $c['kind'] === 'private' ? 'member' : 'following' ?></span><?php endif; ?>
        <?php if ($c['starred']): ?><span class="badge bg-soft-warning text-warning">★</span><?php endif; ?>
        <?php if ($archived): ?><span class="badge bg-dark" id="channel-card-<?= $id ?>-archived">archived</span><?php endif; ?>
    </div>
    <?php if (($c['topic'] ?? '') !== ''): ?><div class="fs-12 text-muted mt-2 text-truncate" id="channel-card-<?= $id ?>-topic"><?= e($c['topic']) ?></div><?php endif; ?>
    <div class="fs-12 text-muted mt-2" id="channel-card-<?= $id ?>-facts"><?= (int) $c['member_count'] ?> member<?= (int) $c['member_count'] === 1 ? '' : 's' ?> · <?= (int) $c['message_count'] ?> message<?= (int) $c['message_count'] === 1 ? '' : 's' ?><?= $c['last_message_at'] ? ' · last ' . e(format_ts($c['last_message_at'], member_timezone(), 'M j')) : '' ?></div>
    <div class="mt-auto pt-2 d-flex gap-2">
        <?= hx_link(with_back('/channels/' . $id, $here), 'Open', 'btn btn-light btn-touch flex-grow-1', 'id="channel-card-' . $id . '-open-btn"') ?>
        <?php if (!$c['i_follow'] && $c['kind'] === 'public' && !$archived && !is_guest()): ?>
            <form method="post" action="/channels/join.php" hx-post="/channels/join.php" hx-target="#flash" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="channel" value="<?= $id ?>"><input type="hidden" name="return_to" value="/channels/<?= $id ?>"><button type="submit" class="btn btn-outline-primary btn-touch w-100" id="channel-card-<?= $id ?>-join-btn">Follow</button></form>
        <?php endif; ?>
    </div>
</div></div>
