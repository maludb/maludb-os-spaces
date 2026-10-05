<?php /** The channels as cards by space (screen `channel-browse`). Data: groups, filters, unread, spaces, may (create), here, notice */ ?>
<?= view('shared/header.php', ['id' => 'channel-browse', 'title' => 'Channels', 'crumbs' => [['Home', '/'], ['Channels', null]],
    'action' => $may['create'] ? hx_link('/channels/new' . ($filters['space'] ? '?space=' . (int) $filters['space'] : ''), '<i class="feather-plus me-1"></i>New channel', 'btn btn-primary btn-touch', 'id="channel-browse-add-btn"') : '']) ?>
<div class="main-content" id="channel-browse-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/channels/" hx-get="/channels/" hx-target="#page-content" hx-swap="innerHTML" hx-trigger="change, submit, keyup changed delay:400ms from:#channel-filter-q" hx-push-url="true" id="channel-filters" class="card mb-3"><div class="card-body row g-2 align-items-end">
        <div class="col-12 col-md-5"><label class="form-label fs-12 text-muted" for="channel-filter-q">Name or topic</label><input type="search" name="q" id="channel-filter-q" class="form-control btn-touch" value="<?= e($filters['q']) ?>"></div>
        <div class="col-8 col-md-5"><label class="form-label fs-12 text-muted" for="channel-filter-space">Space</label><select name="space" id="channel-filter-space" class="form-select btn-touch"><option value="">Any</option><?php foreach ($spaces as $s): ?><option value="<?= (int) $s['space_id'] ?>" <?= (int) $s['space_id'] === (int) $filters['space'] ? 'selected' : '' ?>><?= e(($s['icon'] ?? '') !== '' ? $s['icon'] . ' ' : '') ?><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-4 col-md-2"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="channel-filter-archived"><input type="checkbox" class="form-check-input mt-0" name="archived" value="1" id="channel-filter-archived" <?= $filters['include_archived'] ? 'checked' : '' ?>><span class="fs-12">Archived</span></label></div>
        <noscript><div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100">Apply</button></div></noscript>
    </div></form>
    <?php if ($groups === []): ?><div class="card"><div class="card-body text-muted" id="channel-browse-empty">No channels to show<?= $filters['q'] !== '' ? ' for that search' : ' — join a space first' ?>.</div></div><?php endif; ?>
    <?php foreach ($groups as $g): ?>
    <h6 class="fw-bold mt-3 mb-1" id="channel-browse-space-<?= (int) $g['space_id'] ?>-title"><?= hx_link('/spaces/' . (int) $g['space_id'], e(($g['icon'] ?? '') !== '' ? $g['icon'] . ' ' : '') . e($g['name']), 'text-dark') ?> <span class="fs-12 text-muted fw-normal">· <?= count($g['channels']) ?> channel<?= count($g['channels']) === 1 ? '' : 's' ?></span></h6>
    <div class="row g-3 mb-2" id="channel-browse-space-<?= (int) $g['space_id'] ?>">
        <?php foreach ($g['channels'] as $c): ?><div class="col-12 col-md-6 col-xl-4"><?= view('channels/partials/channel-card.php', ['c' => $c, 'u' => $unread[$c['channel_id']] ?? [], 'here' => $here]) ?></div><?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>
