<?php /** One proposal as a card (`proposal-card-{id}`). Data: p, dest, here, tz */ $id = (int) $p['proposal_id']; [$icon, $kindWord] = PROPOSAL_KINDS[$p['kind']] ?? ['feather-inbox', $p['kind']];
$tone = ['proposed' => 'info', 'accepted' => 'success', 'dismissed' => 'secondary'][$p['status']] ?? 'secondary'; $subject = proposal_subject_url($p); ?>
<div class="card mb-2" id="proposal-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
        <span class="badge bg-soft-primary text-primary" id="proposal-card-<?= $id ?>-kind"><i class="<?= e($icon) ?> me-1"></i><?= e($kindWord) ?></span>
        <span class="badge bg-soft-<?= $tone ?> text-<?= $tone ?>" id="proposal-card-<?= $id ?>-status"><?= e($p['status']) ?></span>
    </div>
    <div class="fw-bold" id="proposal-card-<?= $id ?>-title"><?= e($p['title']) ?></div>
    <div class="fs-12 mt-1" id="proposal-card-<?= $id ?>-reason"><?= e($p['reason']) ?></div>
    <div class="fs-12 text-muted mt-2" id="proposal-card-<?= $id ?>-subject">
        <?php if ($p['subject_page_id'] !== null): ?><i class="feather-file-text me-1"></i><?= hx_link(with_back((string) $subject, $here), e((string) ($p['subject_page_title'] ?: 'the page')), 'text-dark') ?>
        <?php elseif ($subject !== null): ?><i class="feather-message-square me-1"></i><?= hx_link(with_back($subject, $here), e((string) ($p['subject_first_line'] ?: 'the thread')), 'text-dark') ?><?= $p['channel_name'] ? ' <span class="text-muted">in #' . e($p['channel_name']) . '</span>' : '' ?>
        <?php endif; ?>
    </div>
    <?php if ($p['proposed_page_id'] !== null): ?>
        <div class="fs-12 mt-1" id="proposal-card-<?= $id ?>-draft"><i class="feather-edit-3 me-1"></i>Draft: <?= hx_link(with_back('/pages/' . $p['proposed_page_id'], $here), e((string) ($p['draft_title'] ?: 'the draft')), 'text-dark fw-semibold') ?></div>
    <?php endif; ?>
    <div class="fs-11 text-muted mt-2"><?= $p['proposed_by_name'] ? e($p['proposed_by_name']) . ' · ' : '' ?>proposed <?= e(format_ts($p['created_at'], $tz, 'M j, g:i A')) ?>
        <?php if ($p['decided_at'] !== null): ?> · <?= e($p['status']) ?> <?= e(format_ts($p['decided_at'], $tz, 'M j, g:i A')) ?><?= $p['decided_by_name'] ? ' by ' . e($p['decided_by_name']) : '' ?><?php endif; ?></div>
    <?php if (($p['decision_note'] ?? '') !== ''): ?><div class="fs-12 text-muted" id="proposal-card-<?= $id ?>-note">Why: <?= e($p['decision_note']) ?></div><?php endif; ?>
    <?php if ($p['status'] === 'proposed'): ?>
    <div class="mt-3 d-flex flex-column flex-md-row gap-2">
        <?= view('proposals/partials/accept-form.php', ['p' => $p, 'dest' => $dest]) ?>
        <form method="post" action="/proposals/dismiss.php" hx-post="/proposals/dismiss.php" hx-target="#flash" class="d-flex gap-2 flex-grow-1" id="proposal-dismiss-<?= $id ?>"><?= csrf_field() ?><input type="hidden" name="proposal" value="<?= $id ?>"><input type="hidden" name="return_to" value="/proposals/">
            <input type="text" name="reason" class="form-control btn-touch" maxlength="500" placeholder="Why not (optional)" aria-label="Why not" id="proposal-dismiss-<?= $id ?>-reason">
            <button type="submit" class="btn btn-light btn-touch flex-shrink-0" id="proposal-dismiss-<?= $id ?>-btn">Dismiss</button></form>
    </div>
    <?php elseif ($p['status'] === 'accepted'): ?>
    <div class="mt-2"><?= hx_link($p['proposed_page_id'] !== null ? '/pages/' . $p['proposed_page_id'] : (string) ($subject ?? '/proposals/'), 'Open', 'btn btn-light btn-touch', 'id="proposal-card-' . $id . '-open-btn"') ?></div>
    <?php endif; ?>
</div></div>
