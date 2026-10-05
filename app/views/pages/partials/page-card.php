<?php /** One page as a card (page-list). Data: p, here, tz */ $pid = (string) $p['page_id']; $where = $p['is_private'] ? 'Private' : ($p['space_name'] ?? 'Shared with me'); ?>
<div class="card h-100" id="page-card-<?= e($pid) ?>"><div class="card-body p-3 d-flex flex-column">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="fw-bold min-w-0 text-truncate" id="page-card-<?= e($pid) ?>-title"><?= hx_link(with_back('/pages/' . $pid, $here), e(($p['icon'] ?? '') !== '' ? $p['icon'] . ' ' : '') . e($p['plain_title'] !== '' ? $p['plain_title'] : 'Untitled'), 'text-dark') ?></div>
        <?php if ($p['kind'] === 'database'): ?><span class="badge bg-soft-info text-info">database</span><?php elseif ($p['is_row']): ?><span class="badge bg-soft-light text-dark">row</span><?php endif; ?>
    </div>
    <div class="fs-12 text-muted mt-1 text-truncate"><?= e($where) ?><?php foreach ($p['breadcrumb'] ?? [] as $c): ?> › <?= e($c['title'] ?: 'Untitled') ?><?php endforeach; ?></div>
    <div class="d-flex flex-wrap gap-1 mt-1">
        <?php if ($p['verification_state'] === 'verified'): ?><span class="badge bg-soft-success text-success">verified<?= $p['verify_until'] ? ' until ' . e(format_date($p['verify_until'])) : '' ?></span><?php elseif ($p['verification_state'] === 'expired'): ?><span class="badge bg-soft-warning text-warning">verification expired</span><?php endif; ?>
        <?php if ($p['is_locked']): ?><span class="badge bg-dark"><i class="feather-lock"></i></span><?php endif; ?>
        <?php if ($p['is_published']): ?><span class="badge bg-soft-primary text-primary"><i class="feather-globe"></i> published</span><?php endif; ?>
        <?php if ($p['is_favorite']): ?><span class="badge bg-soft-warning text-warning">★</span><?php endif; ?>
    </div>
    <div class="fs-12 text-muted mt-auto pt-2">Edited <?= e(format_ts($p['last_edited_at'], $tz, 'M j, g:i A')) ?><?= $p['editor_name'] ? ' by ' . e($p['editor_name']) : '' ?><?= $p['child_count'] > 0 ? ' · ' . (int) $p['child_count'] . ' inside' : '' ?></div>
</div></div>
