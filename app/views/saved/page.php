<?php /** Saved messages and my reminders (screen `saved`). Data: rows, reminders, tab, here, tz, me, notice */ ?>
<?= view('shared/header.php', ['id' => 'saved', 'title' => 'Saved', 'crumbs' => [['Home', '/'], ['Saved', null]], 'back' => back_link(),
    'action' => hx_link('/reminders/', '<i class="feather-clock me-1"></i>All reminders', 'btn btn-light btn-touch', 'id="saved-reminders-btn"')]) ?>
<div class="main-content" id="saved-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex gap-1 mb-3 d-md-none" id="saved-tabs">
        <?= hx_link('/saved', 'Saved <span class="badge bg-soft-primary text-primary">' . count($rows) . '</span>', 'btn btn-touch flex-fill ' . ($tab === 'saved' ? 'btn-primary' : 'btn-light'), 'id="saved-tab-saved"') ?>
        <?= hx_link('/saved?tab=reminders', 'Reminders <span class="badge bg-soft-primary text-primary">' . count($reminders) . '</span>', 'btn btn-touch flex-fill ' . ($tab === 'reminders' ? 'btn-primary' : 'btn-light'), 'id="saved-tab-reminders"') ?>
    </div>
    <div class="row g-3">
        <div class="col-md-7<?= $tab === 'saved' ? '' : ' d-none d-md-block' ?>" id="saved-pane-saved">
            <h6 class="fw-bold mb-2 d-none d-md-block">Saved messages <span class="badge bg-soft-primary text-primary"><?= count($rows) ?></span></h6>
            <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="saved-empty">Nothing saved yet — "Save for later" in a message's menu keeps it here.</div></div><?php endif; ?>
            <?php foreach ($rows as $m): ?><?= view('saved/partials/message.php', ['m' => $m, 'tz' => $tz, 'here' => $here]) ?><?php endforeach; ?>
        </div>
        <div class="col-md-5<?= $tab === 'reminders' ? '' : ' d-none d-md-block' ?>" id="saved-pane-reminders">
            <h6 class="fw-bold mb-2 d-none d-md-block">Reminders <span class="badge bg-soft-primary text-primary"><?= count($reminders) ?></span></h6>
            <?php if ($reminders === []): ?><div class="card"><div class="card-body text-muted" id="saved-reminders-empty">No reminders due or coming. <?= hx_link('/reminders/', 'Set one', 'fw-semibold') ?>.</div></div><?php endif; ?>
            <?php foreach ($reminders as $r): ?><?= view('saved/partials/reminder.php', ['r' => $r, 'tz' => $tz, 'here' => $here]) ?><?php endforeach; ?>
        </div>
    </div>
</div>
