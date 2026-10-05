<?php /** The agents that are members here (screen `agent-list`). Data: agents, here, tz */ $os = rtrim((string) env('OS_LAUNCHER_URL', ''), '/'); ?>
<?= view('shared/header.php', ['id' => 'agent-list', 'title' => 'Agents', 'crumbs' => [['Home', '/'], ['Agents', null]], 'back' => back_link()]) ?>
<div class="main-content" id="agent-list-content">
    <div class="alert alert-light border fs-12" id="agent-list-note">Hiring an agent, granting it Spaces and giving it duties are done in the OS<?php if ($os !== ''): ?>: <a href="<?= e($os . '/agents') ?>" id="agent-list-os-link">Agent HR</a><?php endif; ?>. Here you see what the agents do in Spaces. <?= hx_link('/admin/dispatches', 'Every dispatch', 'alert-link', 'id="agent-list-dispatches-link"') ?></div>
    <?php if ($agents === []): ?><div class="card" id="agent-list-empty"><div class="card-body text-muted">No agent has been granted Spaces yet.</div></div><?php endif; ?>
    <div class="row g-2" id="agent-cards">
    <?php foreach ($agents as $a): $id = $a['member_id']; ?>
        <div class="col-12 col-lg-6"><div class="card h-100" id="agent-row-<?= $id ?>"><div class="card-body p-3">
            <div class="d-flex align-items-center gap-2 mb-1"><span class="avatar-text avatar-sm sp-avatar-agent flex-shrink-0"><?= e(mb_strtoupper(mb_substr($a['display_name'], 0, 1))) ?></span>
                <div class="fw-bold min-w-0 text-truncate" id="agent-row-<?= $id ?>-name"><?= e($a['display_name']) ?></div><span class="badge bg-soft-info text-info">agent</span></div>
            <?php if (($a['job_title'] ?? '') !== ''): ?><div class="fs-12 text-muted"><?= e($a['job_title']) ?></div><?php endif; ?>
            <div class="d-flex flex-wrap gap-1 mt-2" id="agent-row-<?= $id ?>-roles"><?php foreach ($a['roles'] as $r): ?><span class="badge bg-soft-secondary text-secondary"><?= e($r) ?></span><?php endforeach; ?></div>
            <div class="fs-12 mt-2" id="agent-row-<?= $id ?>-spaces"><i class="feather-layers me-1 text-muted"></i><?php foreach ($a['spaces'] as $i => $s): ?><?= $i > 0 ? ', ' : '' ?><?= hx_link(with_back('/spaces/' . $s['space_id'], $here), e($s['name']), 'text-dark') ?><?php endforeach; ?><?= $a['spaces'] === [] ? '<span class="text-muted">no space</span>' : '' ?></div>
            <div class="fs-12 mt-1" id="agent-row-<?= $id ?>-channels"><i class="feather-hash me-1 text-muted"></i><?php foreach ($a['channels'] as $i => $c): ?><?= $i > 0 ? ', ' : '' ?><?= hx_link(with_back('/channels/' . $c['channel_id'], $here), e($c['name']), 'text-dark') ?><?php endforeach; ?><?= $a['channels'] === [] ? '<span class="text-muted">no channel</span>' : '' ?></div>
            <div class="fs-12 mt-2" id="agent-row-<?= $id ?>-last"><?php if ($a['last_reply'] !== null): ?>Last reply <?= e(format_ts($a['last_reply']['answered_at'], $tz, 'M j, g:i A')) ?><?php if (($a['last_reply']['excerpt'] ?? '') !== ''): ?> — <span class="text-muted"><?= e($a['last_reply']['excerpt']) ?></span><?php endif; ?><?php else: ?><span class="text-muted">No reply yet.</span><?php endif; ?></div>
            <div class="d-flex flex-wrap gap-1 mt-2">
                <?= hx_link('/admin/dispatches?agent=' . $id . '&status=pending', 'pending <b>' . (int) $a['pending'] . '</b>', 'badge bg-soft-secondary text-secondary', 'id="agent-row-' . $id . '-pending"') ?>
                <?= hx_link('/admin/dispatches?agent=' . $id . '&status=failed', 'failed <b>' . (int) $a['failed'] . '</b>', 'badge bg-soft-' . ((int) $a['failed'] > 0 ? 'danger text-danger' : 'secondary text-secondary'), 'id="agent-row-' . $id . '-failed"') ?>
                <?= hx_link('/admin/dispatches?agent=' . $id . '&status=answered', 'answered <b>' . (int) $a['answered'] . '</b>', 'badge bg-soft-success text-success', 'id="agent-row-' . $id . '-answered"') ?>
            </div>
        </div></div></div>
    <?php endforeach; ?>
    </div>
</div>
