<?php /** My reminders (screen `reminder-list`). Data: rows, here, tz, notice */ $groups = ['due' => [], 'coming' => [], 'done' => []]; foreach ($rows as $r) { $groups[$r['state']][] = $r; } ?>
<?= view('shared/header.php', ['id' => 'reminder-list', 'title' => 'Reminders', 'crumbs' => [['Home', '/'], ['Saved', '/saved'], ['Reminders', null]], 'back' => back_link() ?? ['/saved', 'Saved']]) ?>
<div class="main-content" id="reminder-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="post" action="/reminders/save.php" hx-post="/reminders/save.php" hx-target="#flash" class="card mb-3" id="reminder-form"><div class="card-header"><h5 class="card-title mb-0">Remind me</h5></div><div class="card-body row g-2 align-items-end">
        <?= csrf_field() ?><input type="hidden" name="return_to" value="/reminders/">
        <div class="col-12 col-md-7"><label class="form-label fs-12 text-muted" for="reminder-form-field-text">Of what</label><input type="text" name="text" id="reminder-form-field-text" class="form-control btn-touch" maxlength="500" required></div>
        <div class="col-8 col-md-3"><label class="form-label fs-12 text-muted" for="reminder-form-field-at">When</label><input type="datetime-local" name="remind_at" id="reminder-form-field-at" class="form-control btn-touch" required></div>
        <div class="col-4 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="reminder-form-save-btn">Set</button></div>
    </div></form>
    <?php foreach ([['due', 'Due', 'warning'], ['coming', 'Coming', 'primary'], ['done', 'Done', 'secondary']] as [$key, $title, $cls]): ?>
    <h6 class="fw-bold mt-3 mb-1" id="reminder-list-<?= $key ?>-title"><?= $title ?> <span class="badge bg-soft-<?= $cls ?> text-<?= $cls ?>"><?= count($groups[$key]) ?></span></h6>
    <div class="list-group mb-2" id="reminder-list-<?= $key ?>">
        <?php if ($groups[$key] === []): ?><div class="list-group-item text-muted fs-12" id="reminder-list-<?= $key ?>-empty">None.</div><?php endif; ?>
        <?php foreach ($groups[$key] as $r): $rid = $r['reminder_id']; ?>
        <div class="list-group-item d-flex justify-content-between align-items-center gap-2" id="reminder-row-<?= $rid ?>">
            <div class="min-w-0"><div class="fw-semibold text-truncate"><?php if ($r['message_id'] !== null): ?><?= hx_link('/channels/' . (int) $r['channel_id'] . '?message=' . $r['message_id'], '<i class="feather-message-square me-1"></i>' . e((string) ($r['message_text'] ?? 'a message')), 'text-dark') ?><?php elseif ($r['page_id'] !== null): ?><?= hx_link('/pages/' . $r['page_id'], '<i class="feather-file-text me-1"></i>' . e((string) ($r['page_title'] ?? 'a page')), 'text-dark') ?><?php else: ?><?= e((string) $r['text']) ?><?php endif; ?></div>
                <div class="fs-12 text-muted"><?= e(format_ts($r['remind_at'], $tz, 'M j, Y g:i A')) ?><?= $r['text'] !== null && ($r['message_id'] !== null || $r['page_id'] !== null) ? ' · ' . e($r['text']) : '' ?></div></div>
            <?php if ($r['done_at'] === null): ?><form method="post" action="/reminders/done.php" hx-post="/reminders/done.php" hx-target="#flash" class="m-0"><?= csrf_field() ?><input type="hidden" name="reminder" value="<?= $rid ?>"><input type="hidden" name="return_to" value="/reminders/"><button type="submit" class="btn btn-light btn-sm btn-touch" id="reminder-row-<?= $rid ?>-done-btn">Done</button></form><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>
