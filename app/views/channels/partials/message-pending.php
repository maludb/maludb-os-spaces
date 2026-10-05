<?php /** The inside of a dispatch's placeholder (`message-pending-{id}`): "<agent> is thinking…" with a spinner, or — when a person must approve what the agent tried — saying so. Data: m, awaiting (bool) */
$id = (int) $m['message_id']; $name = (string) ($m['author_name'] ?? 'The agent'); ?>
<div class="d-flex gap-2 align-items-center" id="message-pending-<?= $id ?>" role="status" aria-live="polite">
    <span class="avatar-text avatar-sm sp-avatar-agent flex-shrink-0"><?= e(mb_strtoupper(mb_substr($name, 0, 1))) ?></span>
    <?php if (!empty($awaiting)): ?>
        <span class="fs-12 text-muted"><span class="fw-semibold text-dark"><?= e($name) ?></span> is waiting for a person's approval<i class="feather-clock ms-1"></i></span>
    <?php else: ?>
        <span class="spinner-border spinner-border-sm text-muted flex-shrink-0" aria-hidden="true"></span>
        <span class="fs-12 text-muted"><span class="fw-semibold text-dark"><?= e($name) ?></span> is thinking<span class="sp-dots"><span>.</span><span>.</span><span>.</span></span></span>
    <?php endif; ?>
</div>
