<?php /** One space as a card. Data: s, here, pending (bool: my request pending), may (admin) */ $id = (int) $s['space_id']; $archived = $s['archived_at'] !== null;
$kindClass = ['open' => 'success', 'closed' => 'secondary', 'private' => 'dark'][$s['kind']] ?? 'secondary'; ?>
<div class="card h-100<?= $archived ? ' border-dark' : '' ?>" id="space-card-<?= $id ?>"><div class="card-body p-3 d-flex flex-column">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="fw-bold min-w-0 text-truncate" id="space-card-<?= $id ?>-name"><?= hx_link(with_back('/spaces/' . $id, $here), e(($s['icon'] ?? '') !== '' ? $s['icon'] . ' ' : '') . e($s['name']), 'text-dark') ?></div>
        <span class="badge bg-soft-<?= $kindClass ?> text-<?= $kindClass ?>" id="space-card-<?= $id ?>-kind"><?= e($s['kind']) ?></span>
    </div>
    <div class="d-flex flex-wrap gap-1 mt-1">
        <?php if ($s['is_default']): ?><span class="badge bg-soft-info text-info">everyone</span><?php endif; ?>
        <?php if ($s['department_id'] !== null): ?><span class="badge bg-soft-light text-dark" id="space-card-<?= $id ?>-department"><?= e($s['department_name']) ?> department</span><?php endif; ?>
        <?php if ($s['i_am_owner']): ?><span class="badge bg-soft-primary text-primary" id="space-card-<?= $id ?>-owner">owner</span><?php endif; ?>
        <?php if ($s['is_wiki']): ?><span class="badge bg-soft-warning text-warning">wiki</span><?php endif; ?>
        <?php if ($archived): ?><span class="badge bg-dark" id="space-card-<?= $id ?>-archived">archived</span><?php endif; ?>
    </div>
    <?php if (($s['description'] ?? '') !== ''): ?><div class="fs-12 text-muted mt-2 text-truncate" id="space-card-<?= $id ?>-description"><?= e(strtok((string) $s['description'], "\n")) ?></div><?php endif; ?>
    <div class="fs-12 text-muted mt-2" id="space-card-<?= $id ?>-facts"><?= (int) $s['member_count'] ?> member<?= (int) $s['member_count'] === 1 ? '' : 's' ?> · <?= (int) $s['page_count'] ?> page<?= (int) $s['page_count'] === 1 ? '' : 's' ?> · <?= (int) $s['channel_count'] ?> channel<?= (int) $s['channel_count'] === 1 ? '' : 's' ?></div>
    <div class="mt-auto pt-2 d-flex gap-2">
        <?= hx_link(with_back('/spaces/' . $id, $here), 'Open', 'btn btn-light btn-touch flex-grow-1', 'id="space-card-' . $id . '-open-btn"') ?>
        <?php if (!$s['i_am_member'] && !$archived && $s['kind'] === 'open' && has_right('spaces.join')): ?>
            <form method="post" action="/spaces/join.php" hx-post="/spaces/join.php" hx-target="#flash" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="space" value="<?= $id ?>"><input type="hidden" name="return_to" value="/spaces/<?= $id ?>"><button type="submit" class="btn btn-primary btn-touch w-100" id="space-card-<?= $id ?>-join-btn">Join</button></form>
        <?php elseif (!$s['i_am_member'] && !$archived && $s['kind'] === 'closed' && has_right('spaces.join')): ?>
            <?php if (!empty($pending)): ?><span class="btn btn-light btn-touch flex-grow-1 disabled" id="space-card-<?= $id ?>-requested-btn">Requested</span>
            <?php else: ?><?= hx_link(with_back('/spaces/' . $id, $here) . '#request', 'Request to join', 'btn btn-outline-primary btn-touch flex-grow-1', 'id="space-card-' . $id . '-request-btn"') ?><?php endif; ?>
        <?php endif; ?>
    </div>
</div></div>
