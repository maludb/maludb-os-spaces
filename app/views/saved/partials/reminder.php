<?php /** One reminder, due or coming (reminder-row-{id}) with Done. Data: r, tz, here */
$rid = (int) $r['reminder_id']; $due = $r['state'] === 'due'; ?>
<div class="card mb-2<?= $due ? ' border-warning' : '' ?>" id="saved-reminder-<?= $rid ?>"><div class="card-body p-3 d-flex align-items-center gap-2">
    <div class="min-w-0 flex-grow-1">
        <div class="fw-semibold text-truncate"><?php if ($r['message_id'] !== null): ?><?= hx_link(with_back('/channels/' . (int) $r['channel_id'] . '?message=' . $r['message_id'], '/saved?tab=reminders'), '<i class="feather-message-square me-1"></i>' . e((string) ($r['message_text'] ?? 'a message')), 'text-dark') ?>
            <?php elseif ($r['page_id'] !== null): ?><?= hx_link(with_back('/pages/' . $r['page_id'], '/saved?tab=reminders'), '<i class="feather-file-text me-1"></i>' . e((string) ($r['page_title'] ?? 'a page')), 'text-dark') ?>
            <?php else: ?><i class="feather-clock me-1"></i><?= e((string) ($r['text'] ?? 'a reminder')) ?><?php endif; ?></div>
        <div class="fs-12 text-muted"><span class="badge bg-soft-<?= $due ? 'warning text-warning' : 'primary text-primary' ?>" id="saved-reminder-<?= $rid ?>-state"><?= $due ? 'due' : 'coming' ?></span> <?= e(format_ts($r['remind_at'], $tz, 'M j, g:i A')) ?><?= $r['text'] !== null && ($r['message_id'] !== null || $r['page_id'] !== null) ? ' · ' . e($r['text']) : '' ?></div>
    </div>
    <form method="post" action="/reminders/done.php" hx-post="/reminders/done.php" hx-target="#flash" class="m-0"><?= csrf_field() ?><input type="hidden" name="reminder" value="<?= $rid ?>"><input type="hidden" name="return_to" value="/saved?tab=reminders"><button type="submit" class="btn btn-light btn-touch" id="saved-reminder-<?= $rid ?>-done-btn">Done</button></form>
</div></div>
