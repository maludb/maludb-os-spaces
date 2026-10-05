<?php /** "Seamus is thinking…" — a dispatch's placeholder in the thread (kind agent_pending). Data: m, oob, inThread. Waiting for an approval says so (agent_dispatches.status). */
$id = (int) $m['message_id'];
$awaiting = (bool) one_value(db(), "SELECT 1 FROM agent_dispatches WHERE pending_message_id = :m AND status = 'awaiting_approval'", ['m' => $id]); ?>
<div class="sp-message sp-pending" id="<?= !empty($inThread) ? 'thread-row-' : 'message-row-' ?><?= $id ?>" data-id="<?= $id ?>" data-hash="pending" data-author="<?= (int) ($m['author_member_id'] ?? 0) ?>"<?= !empty($oob) ? ' hx-swap-oob="outerHTML"' : '' ?>>
    <?= view('channels/partials/message-pending.php', ['m' => $m, 'awaiting' => $awaiting]) ?>
</div>
