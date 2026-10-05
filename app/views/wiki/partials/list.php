<?php /** One list of the report (wiki-list-{name}) as cards, its count in the heading. Data: key, label, icon, tone, hint, rows, sid, may, me, here, tz */
$ret = '/spaces/' . $sid . '/wiki/report';
$nudge = static function (string $page, ?int $owner, string $reason, string $idx) use ($may, $me, $ret): string {
    if (!$may['nudge'] || $owner === null || $owner === $me) { return ''; }
    return '<form method="post" action="/spaces/wiki/nudge.php" hx-post="/spaces/wiki/nudge.php" hx-target="#flash" class="m-0">' . csrf_field() . '<input type="hidden" name="page" value="' . e($page) . '"><input type="hidden" name="reason" value="' . e($reason) . '"><input type="hidden" name="return_to" value="' . e($ret) . '">'
        . '<button type="submit" class="btn btn-light btn-touch" id="' . e($idx) . '"><i class="feather-bell me-1"></i>Nudge</button></form>';
}; ?>
<section class="mb-4" id="wiki-list-<?= e($key) ?>">
    <h6 class="fw-bold mb-1"><i class="<?= e($icon) ?> me-1"></i><?= e($label) ?> <span class="badge bg-soft-<?= e($tone) ?> text-<?= e($tone) ?>" id="wiki-list-<?= e($key) ?>-count"><?= count($rows) ?></span></h6>
    <div class="fs-12 text-muted mb-2"><?= e($hint) ?></div>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted fs-12" id="wiki-list-<?= e($key) ?>-empty">None.</div></div><?php endif; ?>
    <?php foreach ($rows as $r): ?>
        <?php if ($key === 'broken'): ?>
        <div class="card mb-2"><div class="card-body py-3 d-flex flex-wrap align-items-center gap-2" id="wiki-broken-<?= e($r['from_block_id'] ?? $r['from_page_id']) ?>">
            <div class="min-w-0 flex-grow-1"><div class="fw-semibold text-break"><?= hx_link(with_back('/pages/' . $r['from_page_id'], $here), e($r['from_title'] ?: 'Untitled'), 'text-dark') ?></div>
                <div class="fs-12 text-muted">points to <?= $r['to_title'] !== null ? e($r['to_title'] ?: 'Untitled') : 'a page that is gone' ?> <span class="badge bg-soft-danger text-danger"><?= e($r['reason']) ?></span></div></div>
        </div></div>
        <?php elseif ($key === 'duplicates'): ?>
        <div class="card mb-2"><div class="card-body py-3" id="wiki-duplicate-<?= e(md5($r['title'])) ?>">
            <div class="fw-semibold mb-1"><?= e($r['title']) ?> <span class="badge bg-soft-secondary text-secondary"><?= (int) $r['n'] ?> pages</span></div>
            <?php foreach ($r['pages'] as $p): ?><div class="fs-12"><?= hx_link(with_back('/pages/' . $p['page_id'], $here), e($p['title'] ?: 'Untitled'), 'text-dark') ?> <span class="text-muted">in <?= e((string) ($p['space_name'] ?? 'a space')) ?></span></div><?php endforeach; ?>
        </div></div>
        <?php elseif ($key === 'unanswered'): ?>
        <div class="card mb-2"><div class="card-body py-3" id="wiki-question-<?= (int) $r['message_id'] ?>">
            <div class="fw-semibold text-break"><?= hx_link(with_back('/channels/' . (int) $r['channel_id'] . '?message=' . (int) $r['message_id'], $here), e($r['excerpt']), 'text-dark') ?></div>
            <div class="fs-12 text-muted">#<?= e((string) $r['channel_name']) ?> · <?= e((string) ($r['author_name'] ?? 'someone')) ?> · open <?= (int) round($r['hours_open']) ?> hours</div>
        </div></div>
        <?php else: $pid = (string) $r['page_id']; $owner = ($r['owner_member_id'] ?? null) === null ? null : (int) $r['owner_member_id']; ?>
        <div class="card mb-2"><div class="card-body py-3 d-flex flex-wrap align-items-center gap-2" id="wiki-row-<?= e($key) ?>-<?= e($pid) ?>">
            <div class="min-w-0 flex-grow-1"><div class="fw-semibold text-break"><?= hx_link(with_back('/pages/' . $pid, $here), e($r['title'] ?: 'Untitled'), 'text-dark', 'id="wiki-link-' . e($key) . '-' . e($pid) . '"') ?></div>
                <div class="fs-12 text-muted"><?php if ($key === 'expired'): ?>expired <?= e(format_date($r['verify_until'])) ?> · <?php endif; ?><?= e((string) ($r['owner_name'] ?? 'no owner')) ?><?= isset($r['days_since_edit']) ? ' · edited ' . (int) $r['days_since_edit'] . ' days ago' : '' ?></div></div>
            <?php if ($key === 'expired' || $key === 'unverified' || $key === 'stale'): ?>
                <?= $nudge($pid, $owner, $key === 'expired' ? 'expired' : ($key === 'unverified' ? 'never' : 'stale'), ($key === 'stale' ? 'nudge-stale-' : 'nudge-') . $pid) ?>
                <?php if ($key !== 'stale'): ?><?= hx_link(with_back('/pages/' . $pid, $here), 'Open', 'btn btn-light btn-touch') ?><?php endif; ?>
            <?php endif; ?>
        </div></div>
        <?php endif; ?>
    <?php endforeach; ?>
</section>
