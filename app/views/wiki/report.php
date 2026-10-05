<?php /** The wiki's reports (screen `wiki-report`). Data: s, report, numbers, may (nudge), me, here, tz, notice */ $sid = (int) $s['space_id']; ?>
<?= view('shared/header.php', ['id' => 'wiki-report', 'title' => $s['name'] . ' · Wiki reports', 'crumbs' => [['Home', '/'], ['Spaces', '/spaces/'], [$s['name'], '/spaces/' . $sid], ['Wiki', '/spaces/' . $sid . '/wiki'], ['Reports', null]],
    'back' => back_link() ?? ['/spaces/' . $sid . '/wiki', 'the wiki']]) ?>
<div class="main-content" id="wiki-report-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="wiki-report-numbers"><div class="card-body py-2 fs-12 text-muted d-flex flex-wrap gap-3">
        <span id="wiki-report-stale-days"><i class="feather-clock me-1"></i>Stale after <strong><?= (int) $numbers['stale_days'] ?></strong> days</span>
        <span id="wiki-report-unanswered-hours"><i class="feather-message-square me-1"></i>A question waits <strong><?= (int) $numbers['unanswered_hours'] ?></strong> hours</span>
        <span id="wiki-report-verify-months"><i class="feather-check-circle me-1"></i>Verify every <strong><?= (int) $numbers['verify_months'] ?></strong> months</span>
        <span class="ms-auto"><?= hx_link('/spaces/' . $sid . '/wiki', 'Every page', 'fw-semibold', 'id="wiki-report-status-link"') ?></span>
    </div></div>
    <div class="d-flex flex-wrap gap-1 mb-3" id="wiki-report-jump">
        <?php foreach (WIKI_LISTS as $key => [$label]): ?><a href="#wiki-list-<?= $key ?>" class="btn btn-light btn-touch" id="wiki-report-jump-<?= $key ?>"><?= e($label) ?> <span class="badge bg-soft-secondary text-secondary"><?= count($report[$key]) ?></span></a><?php endforeach; ?>
    </div>
    <?php foreach (WIKI_LISTS as $key => [$label, $icon, $tone, $hint]): $rows = $report[$key]; ?>
        <?= view('wiki/partials/list.php', ['key' => $key, 'label' => $label, 'icon' => $icon, 'tone' => $tone, 'hint' => $hint, 'rows' => $rows, 'sid' => $sid, 'may' => $may, 'me' => $me, 'here' => $here, 'tz' => $tz]) ?>
    <?php endforeach; ?>
</div>
