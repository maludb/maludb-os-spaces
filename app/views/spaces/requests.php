<?php /** The requests to join a closed space (screen `space-requests`). Data: s, rows, here, tz, notice */ $id = (int) $s['space_id']; $st = ['pending' => 'warning', 'approved' => 'success', 'declined' => 'secondary', 'withdrawn' => 'light']; ?>
<?= view('shared/header.php', ['id' => 'space-requests', 'title' => $s['name'] . ' · Requests', 'crumbs' => [['Home', '/'], ['Spaces', '/spaces/'], [$s['name'], '/spaces/' . $id], ['Requests', null]], 'back' => back_link() ?? ['/spaces/' . $id, $s['name']]]) ?>
<div class="main-content" id="space-requests-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="space-requests-empty">Nobody has asked to join <?= e($s['name']) ?>.</div></div><?php endif; ?>
    <?php foreach ($rows as $r): $rid = (int) $r['join_request_id']; $pending = $r['status'] === 'pending'; ?>
    <div class="card mb-2<?= $pending ? '' : ' opacity-75' ?>" id="request-row-<?= $rid ?>"><div class="card-body d-flex flex-wrap align-items-center gap-2">
        <div class="min-w-0 flex-grow-1">
            <div class="fw-semibold"><?= e($r['display_name']) ?> <span class="badge bg-soft-<?= $st[$r['status']] ?? 'secondary' ?> text-<?= ($st[$r['status']] ?? 'secondary') === 'light' ? 'dark' : ($st[$r['status']] ?? 'secondary') ?>" id="request-row-<?= $rid ?>-status"><?= e($r['status']) ?></span></div>
            <?php if (($r['message'] ?? '') !== ''): ?><div class="fs-12" id="request-row-<?= $rid ?>-message"><?= e($r['message']) ?></div><?php endif; ?>
            <div class="fs-11 text-muted"><?= e(format_ts($r['created_at'], $tz, 'M j, g:i A')) ?><?= $r['decided_at'] !== null ? ' · decided ' . e(format_ts($r['decided_at'], $tz, 'M j, g:i A')) . ($r['decided_by_name'] ? ' by ' . e($r['decided_by_name']) : '') : '' ?></div>
        </div>
        <?php if ($pending): ?>
            <form method="post" action="/spaces/request-decide.php" hx-post="/spaces/request-decide.php" hx-target="#flash" hx-confirm="Let <?= e($r['display_name']) ?> into <?= e($s['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="request" value="<?= $rid ?>"><input type="hidden" name="decision" value="approve"><input type="hidden" name="return_to" value="/spaces/<?= $id ?>/requests"><button type="submit" class="btn btn-primary btn-touch" id="request-row-<?= $rid ?>-approve-btn">Approve</button></form>
            <form method="post" action="/spaces/request-decide.php" hx-post="/spaces/request-decide.php" hx-target="#flash" hx-confirm="Decline <?= e($r['display_name']) ?>?"><?= csrf_field() ?><input type="hidden" name="request" value="<?= $rid ?>"><input type="hidden" name="decision" value="decline"><input type="hidden" name="return_to" value="/spaces/<?= $id ?>/requests"><button type="submit" class="btn btn-light btn-touch" id="request-row-<?= $rid ?>-decline-btn">Decline</button></form>
        <?php endif; ?>
    </div></div>
    <?php endforeach; ?>
</div>
