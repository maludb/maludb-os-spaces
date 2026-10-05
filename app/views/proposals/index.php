<?php /** The Librarian's proposals (screen `proposal-list`). Data: rows, status, kind, counts, dest, here, tz, notice */
$q = static fn (array $over): string => '/proposals/?' . http_build_query(array_filter(['status' => $over['status'] ?? $status, 'kind' => $over['kind'] ?? $kind], static fn ($v): bool => $v !== '' && $v !== 'proposed')); ?>
<?= view('shared/header.php', ['id' => 'proposal-list', 'title' => 'Librarian proposals', 'crumbs' => [['Home', '/'], ['Librarian proposals', null]], 'back' => back_link()]) ?>
<div class="main-content" id="proposal-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-2" id="proposal-tabs">
        <?php foreach (PROPOSAL_STATUSES as $k => $label): ?>
            <?= hx_link($q(['status' => $k]), e($label) . ' <span class="badge bg-soft-' . ($k === 'proposed' ? 'info text-info' : ($k === 'accepted' ? 'success text-success' : 'secondary text-secondary')) . '">' . (int) ($counts[$k] ?? 0) . '</span>', 'btn btn-touch ' . ($status === $k ? 'btn-primary' : 'btn-light'), 'id="proposal-tab-' . $k . '"') ?>
        <?php endforeach; ?>
    </div>
    <div class="d-flex flex-wrap gap-1 mb-3" id="proposal-kinds">
        <?= hx_link($q(['kind' => '']), 'Every kind', 'btn btn-touch ' . ($kind === '' ? 'btn-primary' : 'btn-light'), 'id="proposal-kind-all"') ?>
        <?php foreach (PROPOSAL_KINDS as $k => [$icon, $label]): ?>
            <?= hx_link($q(['kind' => $k]), '<i class="' . e($icon) . ' me-1"></i>' . e($label), 'btn btn-touch ' . ($kind === $k ? 'btn-primary' : 'btn-light'), 'id="proposal-kind-' . e($k) . '"') ?>
        <?php endforeach; ?>
    </div>
    <div id="proposal-cards">
        <?php if ($rows === []): ?>
            <div class="card" id="proposal-list-empty"><div class="card-body"><div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="feather-inbox"></i></span>
                <div><div class="fw-semibold">Nothing <?= $status === 'proposed' ? 'waits' : 'here' ?></div><div class="fs-12 text-muted">The Librarian proposes on Mondays: a thread worth a page, a page to verify, an orphan, a duplicate.</div></div></div></div></div>
        <?php endif; ?>
        <?php foreach ($rows as $p): ?><?= view('proposals/partials/card.php', ['p' => $p, 'dest' => $dest, 'here' => $here, 'tz' => $tz]) ?><?php endforeach; ?>
    </div>
</div>
