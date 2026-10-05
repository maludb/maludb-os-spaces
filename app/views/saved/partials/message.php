<?php /** One saved message (saved-row-{id}): where it is, who said it, when, its words, Remove from Saved. Data: m, tz, here */
$id = (int) $m['message_id']; $isDm = $m['channel_name'] === null;
$where = ($isDm ? '/dm/' : '/channels/') . (int) $m['channel_id'] . ($m['thread_root_id'] !== null && !$isDm ? '/threads/' . (int) $m['thread_root_id'] : '?message=' . $id); ?>
<div class="card mb-2" id="saved-row-<?= $id ?>"><div class="card-body p-3">
    <div class="fs-12 text-muted mb-1"><?= hx_link(with_back($where, '/saved'), $isDm ? 'a conversation' : '#' . e((string) $m['channel_name']), 'fw-semibold', 'id="saved-row-' . $id . '-where"') ?> · <?= e((string) ($m['author_name'] ?? 'someone')) ?> · <?= e(format_ts($m['sent_at'] ?? $m['created_at'], $tz, 'M j, g:i A')) ?> · saved <?= e(format_ts($m['saved_at'], $tz, 'M j')) ?></div>
    <div class="sp-message-body"><?= ($m['deleted_at'] ?? null) !== null ? '<span class="text-muted fst-italic">This message was deleted.</span>' : render_rich_text($m['body']) ?></div>
    <form method="post" action="/channels/messages/save.php" hx-post="/channels/messages/save.php" hx-target="#flash" class="mt-2"><?= csrf_field() ?><input type="hidden" name="message" value="<?= $id ?>"><input type="hidden" name="saved" value="no"><input type="hidden" name="return_to" value="/saved?notice=unsaved"><button type="submit" class="btn btn-light btn-sm btn-touch" id="saved-row-<?= $id ?>-unsave-btn">Remove from Saved</button></form>
</div></div>
