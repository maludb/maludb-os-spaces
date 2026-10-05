<?php /** The bell's list (screen `notifications`). Data: rows, unreadOnly, page, more, unread, hash, tz, here */ ?>
<?= view('shared/header.php', ['id' => 'notifications', 'title' => 'Notifications', 'crumbs' => [['Home', '/'], ['Notifications', null]], 'back' => back_link(),
    'action' => '<form method="post" action="/settings/notifications/read.php" hx-post="/settings/notifications/read.php" hx-target="#flash" id="notifications-read-all">' . csrf_field()
        . '<input type="hidden" name="return_to" value="/notifications"><button type="submit" class="btn btn-light btn-touch" id="notifications-read-all-btn"' . ($unread === 0 ? ' disabled' : '') . '>Mark all read</button></form>']) ?>
<div class="main-content" id="notifications-content">
    <div class="d-flex flex-wrap align-items-center gap-1 mb-3" id="notifications-filters">
        <?= hx_link('/notifications', 'Everything', 'btn btn-touch ' . ($unreadOnly ? 'btn-light' : 'btn-primary'), 'id="notifications-filter-all"') ?>
        <?= hx_link('/notifications?unread=1', 'Unread', 'btn btn-touch ' . ($unreadOnly ? 'btn-primary' : 'btn-light'), 'id="notifications-filter-unread"') ?>
        <?= hx_link('/settings/', '<i class="feather-settings me-1"></i>Choose what reaches you', 'btn btn-touch btn-light ms-auto', 'id="notifications-prefs-link"') ?>
    </div>
    <?= view('notifications/partials/list.php', ['rows' => $rows, 'unreadOnly' => $unreadOnly, 'page' => $page, 'more' => $more, 'unread' => $unread, 'hash' => $hash, 'tz' => $tz, 'here' => $here]) ?>
</div>
