<?php /** The results (search-results), grouped Pages · Rows · Messages · Comments. Data: groups, total, page, parsed, dead, tz, here */
$size = SEARCH_PAGE; $from = ($page - 1) * $size + 1; $to = min($total, $page * $size); ?>
<div id="search-results">
    <div class="fs-12 text-muted mb-2" id="search-count"><?= $dead ? 'Nothing matches.' : ($total === 0 ? 'No results.' : 'Results ' . $from . '–' . $to . ' of ' . (int) $total) ?></div>
    <?php if ($total === 0 && !$dead): ?><div class="card" id="search-empty"><div class="card-body text-muted">Nothing found that you may read. Try fewer words, or take a modifier off.</div></div><?php endif; ?>
    <?php foreach (SEARCH_GROUPS as $kind => [$label, $icon]): $rows = $groups[$kind] ?? []; if ($rows === []) { continue; } ?>
    <section class="mb-3" id="search-group-<?= $kind ?>">
        <h6 class="fw-bold mb-2"><i class="<?= e($icon) ?> me-1"></i><?= e($label) ?> <span class="badge bg-soft-primary text-primary"><?= count($rows) ?></span></h6>
        <?php foreach ($rows as $i => $r): ?>
        <div class="card mb-2" id="search-result-<?= $kind ?>-<?= $i + 1 ?>"><div class="card-body py-3">
            <div class="fw-semibold text-break"><?= $r['url'] !== null ? hx_link(with_back($r['url'], $here), e($r['title']), 'text-dark', 'id="search-result-' . $kind . '-' . ($i + 1) . '-link"') : e($r['title']) ?></div>
            <?php if ($r['excerpt'] !== ''): ?><div class="fs-12 text-break search-excerpt"><?= $r['excerpt_html'] ?></div><?php endif; ?>
            <div class="fs-11 text-muted mt-1"><?php if ($kind === 'message'): ?><?php else: ?><?= e(implode(' › ', $r['where'])) ?> · <?php endif; ?><?= $r['who'] !== null ? e((string) ($r['who']['display_name'] ?? 'someone')) . ' · ' : '' ?><?= e(format_ts($r['at'], $tz, 'M j, Y g:i A')) ?></div>
        </div></div>
        <?php endforeach; ?>
    </section>
    <?php endforeach; ?>
    <?php if ($total > $size): ?>
    <div class="d-flex gap-2 mt-3" id="search-paging">
        <?php if ($page > 1): ?><?= hx_link(search_url($parsed, [], $page - 1 > 1 ? ['page' => $page - 1] : []), 'Previous', 'btn btn-light btn-touch', 'id="search-prev"') ?><?php endif; ?>
        <?php if ($to < $total): ?><?= hx_link(search_url($parsed, [], ['page' => $page + 1]), 'Next', 'btn btn-light btn-touch ms-auto', 'id="search-next"') ?><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
