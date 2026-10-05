<?php /** My conversations as cards (screen `dm-list`). Data: rows, may (new), here, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'dm-list', 'title' => 'Direct messages', 'crumbs' => [['Home', '/'], ['Direct messages', null]], 'action' => $may['new'] ? hx_link('/dm/new', '<i class="feather-plus me-1"></i>New message', 'btn btn-primary btn-touch', 'id="dm-list-new-btn"') : '']) ?>
<div class="main-content" id="dm-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3" id="dm-list">
        <?php if ($rows === []): ?><div class="col-12"><div class="card"><div class="card-body text-muted" id="dm-list-empty">No conversations yet.</div></div></div><?php endif; ?>
        <?php foreach ($rows as $d): ?><div class="col-12 col-md-6 col-xl-4"><?= view('dm/partials/dm-card.php', ['d' => $d, 'here' => $here, 'tz' => $tz]) ?></div><?php endforeach; ?>
    </div>
</div>
