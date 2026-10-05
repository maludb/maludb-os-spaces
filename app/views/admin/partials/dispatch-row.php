<?php /** One dispatch (`dispatch-row-{id}`): a card, so it reads at 375. Data: d, here, tz */ $id = (int) $d['dispatch_id']; [$word, $tone] = dispatch_chip($d);
$where = $d['channel_id'] === null ? null : (in_array($d['channel_kind'] ?? '', ['dm', 'group_dm'], true) ? '/dm/' : '/channels/') . (int) $d['channel_id'] . ($d['message_id'] !== null ? '?message=' . (int) $d['message_id'] : ''); ?>
<div class="card mb-2" id="dispatch-row-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
        <span class="badge bg-<?= $tone === 'dark' ? 'dark' : 'soft-' . $tone ?> text-<?= $tone === 'dark' ? 'white' : $tone ?>" id="dispatch-row-<?= $id ?>-status"><?= e($word) ?></span>
        <span class="badge bg-soft-secondary text-secondary" id="dispatch-row-<?= $id ?>-kind"><?= e($d['kind']) ?></span>
        <span class="fs-11 text-muted ms-auto"><?= e(format_ts($d['created_at'], $tz, 'M j, g:i A')) ?></span>
    </div>
    <div class="fs-13"><span class="fw-semibold" id="dispatch-row-<?= $id ?>-agent"><?= e((string) $d['agent_name']) ?></span> <span class="text-muted">asked by</span> <span id="dispatch-row-<?= $id ?>-asker"><?= e((string) ($d['asker_name'] ?? 'someone')) ?></span>
        <?php if ($d['channel_id'] !== null): ?><span class="text-muted">in</span> <span id="dispatch-row-<?= $id ?>-channel"><?= in_array($d['channel_kind'] ?? '', ['dm', 'group_dm'], true) ? 'a direct message' : '#' . e((string) $d['channel_name']) ?></span><?php endif; ?></div>
    <?php if (($d['first_line'] ?? '') !== ''): ?><div class="fs-12 mt-1 text-truncate" id="dispatch-row-<?= $id ?>-line"><?= $where !== null ? hx_link(with_back($where, $here), e((string) $d['first_line']), 'text-dark') : e((string) $d['first_line']) ?></div><?php endif; ?>
    <?php if (($d['reply_excerpt'] ?? '') !== ''): ?><div class="fs-12 text-muted mt-1" id="dispatch-row-<?= $id ?>-reply"><i class="feather-corner-down-right me-1"></i><?= e($d['reply_excerpt']) ?></div><?php endif; ?>
    <?php if (($d['detail'] ?? '') !== '' && $d['status'] !== 'answered'): ?><div class="fs-12 text-<?= $d['status'] === 'failed' ? 'danger' : 'muted' ?> mt-1" id="dispatch-row-<?= $id ?>-detail"><?= e($d['detail']) ?></div><?php endif; ?>
    <div class="fs-11 text-muted mt-1" id="dispatch-row-<?= $id ?>-facts">attempt<?= (int) $d['attempts'] === 1 ? '' : 's' ?> <?= (int) $d['attempts'] ?><?php if ($d['run_id'] !== null): ?> · run <?php $run = os_run_url((int) $d['run_id']); ?><?= $run !== null ? '<a href="' . e($run) . '" id="dispatch-row-' . $id . '-run">' . (int) $d['run_id'] . '</a>' : (int) $d['run_id'] ?><?php endif; ?></div>
    <?php if ($d['status'] === 'awaiting_approval'): $mins = max(0, (int) round((time() - strtotime((string) $d['created_at'])) / 60)); ?>
    <div class="fs-12 text-warning mt-1" id="dispatch-row-<?= $id ?>-age">Waiting for a person's approval for <?= $mins < 60 ? $mins . ' min' : (int) floor($mins / 60) . ' h ' . ($mins % 60) . ' min' ?> — the worker asks the kernel again until it is decided.</div>
    <?php endif; ?>
    <?php if (in_array($d['status'], ['failed', 'awaiting_approval'], true)): ?>
    <form method="post" action="/admin/dispatches/retry.php" hx-post="/admin/dispatches/retry.php" hx-target="#flash" class="mt-2"><?= csrf_field() ?><input type="hidden" name="dispatch" value="<?= $id ?>"><input type="hidden" name="return_to" value="/admin/dispatches">
        <button type="submit" class="btn btn-outline-primary btn-touch" id="dispatch-row-<?= $id ?>-retry-btn"><?= $d['status'] === 'failed' ? 'Retry' : 'Give up waiting and retry' ?></button></form>
    <?php endif; ?>
</div></div>
