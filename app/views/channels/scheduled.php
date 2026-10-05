<?php /** My scheduled messages (screen `scheduled-list`). Data: rows, here, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'scheduled-list', 'title' => 'Scheduled messages', 'crumbs' => [['Home', '/'], ['Channels', '/channels/'], ['Scheduled', null]], 'back' => back_link() ?? ['/channels/', 'Channels']]) ?>
<div class="main-content" id="scheduled-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="scheduled-list-empty">Nothing scheduled. In the composer, the clock sends later.</div></div><?php endif; ?>
    <?php foreach ($rows as $m): $id = (int) $m['message_id']; ?>
    <div class="card mb-2" id="scheduled-<?= $id ?>"><div class="card-body p-3">
        <div class="fs-12 text-muted mb-1">to <strong><?= e($m['channel_name'] !== null ? '#' . $m['channel_name'] : 'a conversation') ?></strong> at <strong id="scheduled-<?= $id ?>-at"><?= e(format_ts($m['scheduled_for'], $tz, 'M j, Y g:i A')) ?></strong></div>
        <div class="sp-message-body mb-2"><?= render_rich_text($m['body']) ?></div>
        <form method="post" action="/channels/messages/schedule.php" hx-post="/channels/messages/schedule.php" hx-target="#flash" class="d-flex flex-wrap gap-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="message" value="<?= $id ?>"><input type="hidden" name="return_to" value="/channels/scheduled">
            <div><label class="form-label fs-12 text-muted" for="scheduled-<?= $id ?>-field">New time (empty sends now)</label><input type="datetime-local" name="schedule_for" id="scheduled-<?= $id ?>-field" class="form-control btn-touch"></div>
            <button type="submit" class="btn btn-primary btn-touch" id="scheduled-<?= $id ?>-save-btn">Save</button>
        </form>
        <form method="post" action="/channels/messages/unschedule.php" hx-post="/channels/messages/unschedule.php" hx-target="#flash" hx-confirm="Cancel this message? It is discarded." class="mt-2"><?= csrf_field() ?><input type="hidden" name="message" value="<?= $id ?>"><input type="hidden" name="return_to" value="/channels/scheduled"><button type="submit" class="btn btn-light btn-touch" id="scheduled-<?= $id ?>-cancel-btn">Cancel it</button></form>
    </div></div>
    <?php endforeach; ?>
</div>
