<?php /** Saved messages and my reminders summary (screen `saved`). Data: rows, reminders, here, tz, me, notice */ ?>
<?= view('shared/header.php', ['id' => 'saved', 'title' => 'Saved', 'crumbs' => [['Home', '/'], ['Saved', null]], 'action' => hx_link('/reminders/', '<i class="feather-clock me-1"></i>Reminders' . ($reminders !== [] ? ' <span class="badge bg-soft-primary text-primary">' . count($reminders) . '</span>' : ''), 'btn btn-light btn-touch', 'id="saved-reminders-btn"')]) ?>
<div class="main-content" id="saved-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($reminders !== []): ?><div class="card mb-3" id="saved-reminders"><div class="card-body p-2 fs-12"><?php foreach (array_slice($reminders, 0, 5) as $r): ?><div class="d-flex justify-content-between gap-2 py-1 border-bottom"><span class="text-truncate"><i class="feather-clock me-1 text-<?= $r['state'] === 'due' ? 'warning' : 'muted' ?>"></i><?= e((string) ($r['text'] ?? $r['message_text'] ?? $r['page_title'] ?? 'a reminder')) ?></span><span class="text-muted text-nowrap"><?= e(format_ts($r['remind_at'], $tz, 'M j, g:i A')) ?></span></div><?php endforeach; ?></div></div><?php endif; ?>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="saved-empty">Nothing saved yet — "Save for later" in a message's menu keeps it here.</div></div><?php endif; ?>
    <?php foreach ($rows as $m): $id = (int) $m['message_id']; $isDm = $m['channel_name'] === null; ?>
    <div class="card mb-2" id="saved-row-<?= $id ?>"><div class="card-body p-3">
        <div class="fs-12 text-muted mb-1"><?= hx_link(($isDm ? '/dm/' : '/channels/') . (int) $m['channel_id'] . ($m['thread_root_id'] !== null ? '/threads/' . (int) $m['thread_root_id'] : '?message=' . $id), $isDm ? 'a conversation' : '#' . e((string) $m['channel_name']), 'fw-semibold') ?> · <?= e((string) ($m['author_name'] ?? 'someone')) ?> · <?= e(format_ts($m['sent_at'] ?? $m['created_at'], $tz, 'M j, g:i A')) ?> · saved <?= e(format_ts($m['saved_at'], $tz, 'M j')) ?></div>
        <div class="sp-message-body"><?= ($m['deleted_at'] ?? null) !== null ? '<span class="text-muted fst-italic">This message was deleted.</span>' : render_rich_text($m['body']) ?></div>
        <form method="post" action="/channels/messages/save.php" hx-post="/channels/messages/save.php" hx-target="#flash" class="mt-2"><?= csrf_field() ?><input type="hidden" name="message" value="<?= $id ?>"><input type="hidden" name="saved" value="no"><input type="hidden" name="return_to" value="/saved?notice=unsaved"><button type="submit" class="btn btn-light btn-sm btn-touch" id="saved-row-<?= $id ?>-unsave-btn">Remove from Saved</button></form>
    </div></div>
    <?php endforeach; ?>
</div>
