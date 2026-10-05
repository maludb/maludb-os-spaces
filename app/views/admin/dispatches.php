<?php /** Every dispatch (screen `dispatch-list`). Data: rows, total, page, pages, status, agent, agents, hash, here, tz, notice */
$q = static fn (array $over): string => '/admin/dispatches?' . http_build_query(array_filter(['status' => $over['status'] ?? $status, 'agent' => $over['agent'] ?? $agent, 'page' => $over['page'] ?? null], static fn ($v): bool => $v !== '' && $v !== null && $v !== 0 && $v !== 1)); ?>
<?= view('shared/header.php', ['id' => 'dispatch-list', 'title' => 'Dispatches', 'crumbs' => [['Home', '/'], ['Agents', '/admin/agents'], ['Dispatches', null]], 'back' => back_link() ?? ['/admin/agents', 'Agents']]) ?>
<div class="main-content" id="dispatch-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-2" id="dispatch-status-filter">
        <?= hx_link($q(['status' => '']), 'All', 'btn btn-touch ' . ($status === '' ? 'btn-primary' : 'btn-light'), 'id="dispatch-status-all"') ?>
        <?php foreach (DISPATCH_STATUSES as $k => $label): ?><?= hx_link($q(['status' => $k]), e($label), 'btn btn-touch ' . ($status === $k ? 'btn-primary' : 'btn-light'), 'id="dispatch-status-' . e($k) . '"') ?><?php endforeach; ?>
    </div>
    <form method="get" action="/admin/dispatches" class="d-flex gap-2 mb-3 align-items-center" id="dispatch-agent-filter"><?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <select name="agent" class="form-select btn-touch" aria-label="Agent" id="dispatch-agent-select"><option value="">Every agent</option><?php foreach ($agents as $a): ?><option value="<?= (int) $a['member_id'] ?>"<?= $agent === (int) $a['member_id'] ? ' selected' : '' ?>><?= e($a['display_name']) ?></option><?php endforeach; ?></select>
        <button type="submit" class="btn btn-light btn-touch flex-shrink-0" id="dispatch-agent-btn">Filter</button></form>
    <?= view('admin/partials/dispatch-list.php', ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'status' => $status, 'agent' => $agent, 'hash' => $hash, 'here' => $here, 'tz' => $tz]) ?>
</div>
