<?php /** One Activity row (activity-row-{n}). Data: r, n, tz, here */
$icons = ['mention' => 'feather-at-sign', 'reply' => 'feather-corner-down-right', 'reaction' => 'feather-smile', 'comment' => 'feather-message-circle']; $url = activity_url($r); ?>
<div class="card mb-2" id="activity-row-<?= (int) $n ?>" data-kind="<?= e($r['kind']) ?>">
    <div class="card-body d-flex align-items-start gap-3 py-3">
        <span class="avatar-text avatar-md rounded bg-soft-primary text-primary"><i class="<?= e($icons[$r['kind']] ?? 'feather-activity') ?>"></i></span>
        <div class="min-w-0 flex-grow-1">
            <div><span class="fw-semibold"><?= e((string) ($r['actor_name'] ?? 'Someone')) ?></span><?= $r['actor_is_agent'] ? ' <span class="badge bg-soft-secondary text-secondary">agent</span>' : '' ?> <?= e(activity_words($r)) ?></div>
            <?php if (($r['excerpt'] ?? '') !== ''): ?><div class="fs-12 text-muted text-break"><?= $url !== null ? hx_link(with_back($url, '/activity'), e($r['excerpt']), 'text-muted', 'id="activity-row-' . (int) $n . '-link"') : e($r['excerpt']) ?></div><?php endif; ?>
            <div class="fs-11 text-muted"><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></div>
        </div>
        <?php if ($url !== null): ?><?= hx_link(with_back($url, '/activity'), 'Open', 'btn btn-light btn-touch d-none d-md-inline-flex', 'id="activity-row-' . (int) $n . '-open"') ?><?php endif; ?>
    </div>
</div>
