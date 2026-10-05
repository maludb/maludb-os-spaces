<?php /** The dispatches (polled every 20 s: 204 when nothing changed). Data: rows, total, page, pages, status, agent, hash, here, tz */
$q = static fn (int $p): string => '/admin/dispatches?' . http_build_query(array_filter(['status' => $status, 'agent' => $agent, 'page' => $p > 1 ? $p : null], static fn ($v): bool => $v !== '' && $v !== null)); ?>
<div id="dispatch-list" hx-get="/admin/dispatches?list=1&amp;status=<?= e($status) ?>&amp;agent=<?= (int) $agent ?>&amp;page=<?= (int) $page ?>&amp;h=<?= e($hash) ?>" hx-trigger="every 20s [document.visibilityState=='visible'], dispatchChanged from:body" hx-swap="outerHTML" hx-target="this">
    <div class="fs-12 text-muted mb-2" id="dispatch-list-count"><?= (int) $total ?> dispatch<?= (int) $total === 1 ? '' : 'es' ?></div>
    <?php if ($rows === []): ?><div class="card" id="dispatch-list-empty"><div class="card-body text-muted">No dispatch matches. A mention of an agent, or a message in a DM with one, makes one.</div></div><?php endif; ?>
    <?php foreach ($rows as $d): ?><?= view('admin/partials/dispatch-row.php', ['d' => $d, 'here' => $here, 'tz' => $tz]) ?><?php endforeach; ?>
    <?php if ($pages > 1): ?><div class="d-flex gap-2 justify-content-center mt-2" id="dispatch-pager">
        <?= $page > 1 ? hx_link($q($page - 1), 'Newer', 'btn btn-light btn-touch', 'id="dispatch-prev"') : '' ?><span class="align-self-center fs-12 text-muted">Page <?= (int) $page ?> of <?= (int) $pages ?></span><?= $page < $pages ? hx_link($q($page + 1), 'Older', 'btn btn-light btn-touch', 'id="dispatch-next"') : '' ?></div><?php endif; ?>
</div>
