<?php /** One notice (notification-row-{id}): the kind's icon, the title linking to its record, the body's first line, who, when, an unread chip. Data: n, tz, here */
$id = (int) $n['notification_id']; $unread = $n['read_at'] === null; $url = notification_record_url($n); $line = notice_first_line($n['body']); $kind = (string) $n['kind']; ?>
<div class="card mb-2<?= $unread ? ' border-primary' : '' ?>" id="notification-row-<?= $id ?>">
    <div class="card-body d-flex align-items-start gap-3 py-3">
        <span class="avatar-text avatar-md rounded bg-soft-<?= e(notice_tone($kind)) ?> text-<?= e(notice_tone($kind)) ?>" id="notification-row-<?= $id ?>-icon" title="<?= e(notice_word($kind)) ?>"><i class="<?= e(notice_icon($kind)) ?>"></i></span>
        <div class="min-w-0 flex-grow-1">
            <div class="fw-semibold"><?= $url !== null ? hx_link(with_back($url, '/notifications'), e($n['title']), 'text-dark', 'id="notification-row-' . $id . '-link"') : e($n['title']) ?>
                <?php if ($unread): ?><span class="badge bg-soft-primary text-primary ms-1" id="notification-row-<?= $id ?>-chip">unread</span><?php endif; ?></div>
            <?php if ($line !== ''): ?><div class="fs-12 text-muted"><?= e($line) ?></div><?php endif; ?>
            <div class="fs-11 text-muted"><?php if (($n['actor_name'] ?? null) !== null): ?><?= e($n['actor_name']) ?><?= $n['actor_is_agent'] ? ' <span class="badge bg-soft-secondary text-secondary">agent</span>' : '' ?> · <?php endif; ?><?= e(format_ts($n['created_at'], $tz, 'M j, g:i A')) ?></div>
        </div>
        <?php if ($unread): ?>
            <form method="post" action="/settings/notifications/read.php" hx-post="/settings/notifications/read.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="notification" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <button type="submit" class="btn btn-light btn-touch" id="notification-row-<?= $id ?>-read-btn" aria-label="Mark read"><i class="feather-check"></i></button></form>
        <?php endif; ?>
    </div>
</div>
