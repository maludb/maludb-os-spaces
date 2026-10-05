<?php /** My Activity (screen `activity`). Data: rows, since, kind, hash, tz, here */
$q = static fn (array $over): string => '/activity?' . http_build_query(array_filter(['since' => $over['since'] ?? $since, 'kind' => $over['kind'] ?? $kind], static fn ($v): bool => $v !== '' && $v !== '7')); ?>
<?= view('shared/header.php', ['id' => 'activity', 'title' => 'Activity', 'crumbs' => [['Home', '/'], ['Activity', null]], 'back' => back_link()]) ?>
<div class="main-content" id="activity-content">
    <div class="d-flex flex-wrap gap-1 mb-2" id="activity-since">
        <?php foreach (['today' => 'Today', '7' => '7 days', '30' => '30 days'] as $k => $label): ?>
            <?= hx_link($q(['since' => $k]), $label, 'btn btn-touch ' . ($since === (string) $k ? 'btn-primary' : 'btn-light'), 'id="activity-since-' . $k . '"') ?>
        <?php endforeach; ?>
    </div>
    <div class="d-flex flex-wrap gap-1 mb-3" id="activity-kinds">
        <?= hx_link($q(['kind' => '']), 'Everything', 'btn btn-touch ' . ($kind === '' ? 'btn-primary' : 'btn-light'), 'id="activity-kind-all"') ?>
        <?php foreach (ACTIVITY_KINDS as $k => $label): ?>
            <?= hx_link($q(['kind' => $k]), $label, 'btn btn-touch ' . ($kind === $k ? 'btn-primary' : 'btn-light'), 'id="activity-kind-' . $k . '"') ?>
        <?php endforeach; ?>
    </div>
    <?= view('activity/feed.php', $data = ['rows' => $rows, 'since' => $since, 'kind' => $kind, 'hash' => $hash, 'tz' => $tz, 'here' => $here]) ?>
</div>
