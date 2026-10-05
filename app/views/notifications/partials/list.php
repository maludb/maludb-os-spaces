<?php /** The notices (polled every 20 s: 204 when nothing changed). Data: rows, unreadOnly, page, more, unread, hash, tz, here */ ?>
<div id="notification-list" hx-get="/notifications?list=1&amp;unread=<?= $unreadOnly ? 1 : 0 ?>&amp;page=<?= (int) $page ?>&amp;h=<?= e($hash) ?>" hx-trigger="every 20s [document.visibilityState=='visible'], notificationChanged from:body" hx-swap="outerHTML" hx-target="this">
    <div class="fs-12 text-muted mb-2"><span id="bell-count"><?= (int) $unread ?></span> unread</div>
    <?php if ($rows === []): ?>
        <div class="card" id="notifications-card"><div class="card-body">
            <div class="empty-state" id="notifications-empty">
                <span class="avatar-text avatar-lg rounded"><i class="feather-bell"></i></span>
                <div><div class="fw-semibold"><?= $unreadOnly ? 'All caught up' : 'Nothing yet' ?></div>
                    <div class="fs-12 text-muted">When someone mentions you, replies in your thread, comments on your page or shares one with you, it is listed here. <?= hx_link('/settings/', 'Choose what you are told', 'fw-semibold') ?>.</div></div>
            </div>
        </div></div>
    <?php endif; ?>
    <?php foreach ($rows as $n): ?><?= view('notifications/partials/row.php', ['n' => $n, 'tz' => $tz, 'here' => $here]) ?><?php endforeach; ?>
    <?php if ($more || $page > 1): ?>
        <div class="d-flex gap-2 mt-3" id="notifications-paging">
            <?php if ($page > 1): ?><?= hx_link('/notifications?' . http_build_query(['unread' => $unreadOnly ? 1 : null, 'page' => $page - 1 > 1 ? $page - 1 : null]), 'Newer', 'btn btn-light btn-touch', 'id="notifications-newer"') ?><?php endif; ?>
            <?php if ($more): ?><?= hx_link('/notifications?' . http_build_query(['unread' => $unreadOnly ? 1 : null, 'page' => $page + 1]), 'Older', 'btn btn-light btn-touch ms-auto', 'id="notifications-older"') ?><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
