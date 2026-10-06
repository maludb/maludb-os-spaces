<?php /** Home — Unread (`home-unread`): channels with unread messages, DMs first, the first unread line. Data: s, tz, here */ $rows = $s['unread']; ?>
<div class="card h-100" id="home-unread"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">Unread</h5></div>
    <?php if ($rows === []): ?><div class="card-body text-muted fs-12" id="home-unread-empty">Channels and direct messages with new messages will appear here, with the first line you have not read.</div><?php endif; ?>
    <div class="list-group list-group-flush" id="home-unread-list">
    <?php foreach ($rows as $u): $dm = in_array($u['kind'], ['dm', 'group_dm'], true); $url = ($dm ? '/dm/' : '/channels/') . $u['channel_id'] . ($u['first_message_id'] !== null ? '?message=' . $u['first_message_id'] : ''); ?>
        <div class="list-group-item" id="home-unread-<?= $u['channel_id'] ?>" data-kind="<?= e($u['kind']) ?>">
            <div class="d-flex align-items-center gap-2"><i class="<?= $dm ? 'feather-mail' : 'feather-hash' ?> text-muted"></i>
                <?= hx_link(with_back($url, $here), e(($dm ? '' : '#') . (string) $u['name']), 'fw-semibold text-dark min-w-0 text-truncate') ?>
                <span class="badge bg-primary ms-auto" id="home-unread-<?= $u['channel_id'] ?>-count"><?= $u['unread'] ?></span><?php if ($u['mentions'] > 0): ?><span class="badge bg-danger"><?= $u['mentions'] ?> @</span><?php endif; ?></div>
            <?php if (($u['first_line'] ?? '') !== ''): ?><div class="fs-12 text-muted text-truncate" id="home-unread-<?= $u['channel_id'] ?>-line"><?= e((string) $u['first_author']) ?>: <?= e($u['first_line']) ?></div><?php endif; ?>
            <?php if (!$dm && ($u['space_name'] ?? '') !== ''): ?><div class="fs-11 text-muted"><?= e($u['space_name']) ?></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>
