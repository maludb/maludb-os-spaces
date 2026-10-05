<?php /** "Seamus is thinking…" — a dispatch's placeholder in the thread (kind agent_pending). Data: m, oob */ $id = (int) $m['message_id']; ?>
<div class="sp-message sp-pending" id="<?= !empty($inThread) ? 'thread-row-' : 'message-row-' ?><?= $id ?>" data-id="<?= $id ?>" data-hash="pending" data-author="<?= (int) ($m['author_member_id'] ?? 0) ?>"<?= !empty($oob) ? ' hx-swap-oob="outerHTML"' : '' ?>>
    <div class="d-flex gap-2 align-items-center">
        <span class="avatar-text avatar-sm sp-avatar-agent flex-shrink-0"><?= e(mb_strtoupper(mb_substr((string) ($m['author_name'] ?? '?'), 0, 1))) ?></span>
        <span class="fs-12 text-muted"><span class="fw-semibold text-dark"><?= e((string) ($m['author_name'] ?? 'The agent')) ?></span> is thinking<span class="sp-dots"><span>.</span><span>.</span><span>.</span></span></span>
    </div>
</div>
