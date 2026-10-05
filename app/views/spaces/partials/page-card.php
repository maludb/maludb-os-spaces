<?php /** A root page as a card (space-view). Data: p, here */ $pid = (string) $p['page_id']; ?>
<div class="card h-100" id="space-page-<?= e($pid) ?>"><div class="card-body p-3">
    <div class="fw-semibold text-truncate"><?= hx_link(with_back('/pages/' . $pid, $here), e(($p['icon'] ?? '') !== '' ? $p['icon'] : (($p['kind'] ?? '') === 'database' ? '▦' : '▫')) . ' ' . e($p['title'] !== '' ? $p['title'] : 'Untitled'), 'text-dark') ?></div>
    <div class="fs-12 text-muted mt-1"><?= e(ucfirst((string) $p['kind'])) ?><?= ($p['child_count'] ?? 0) > 0 ? ' · ' . (int) $p['child_count'] . ' inside' : '' ?><?= !empty($p['last_edited_at']) ? ' · ' . e(format_date($p['last_edited_at'])) : '' ?></div>
</div></div>
