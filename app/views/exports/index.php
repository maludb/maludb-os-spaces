<?php /** Exports (screen `export-list`). Data: rows, tz, all, picks (pages, databases, spaces, channels), may (own, space, all), sel (preselected: page, database, space, channel), notice */
$sel = $sel ?? []; ?>
<?= view('shared/header.php', ['id' => 'export-list', 'title' => 'Exports', 'crumbs' => [['Home', '/'], ['Exports', null]], 'back' => back_link()]) ?>
<div class="main-content" id="export-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-3">An export holds only what you may see. It is kept for 7 days, then the file is deleted.</div>
    <div class="row g-3 mb-3" id="export-forms">
        <?php if ($may['own']): ?>
        <div class="col-12 col-xl-6"><form method="post" action="/exports/page.php" hx-post="/exports/page.php" hx-target="#flash" class="card h-100" id="export-page-form"><div class="card-header"><h5 class="card-title mb-0">A page</h5></div><div class="card-body">
            <?= csrf_field() ?>
            <label class="form-label fs-12 text-muted" for="export-page-field-page">Page</label>
            <select name="page" id="export-page-field-page" class="form-select btn-touch mb-2" required><option value="">Choose a page</option><?php foreach ($picks['pages'] as $p): ?><option value="<?= e($p['id']) ?>" <?= ($sel['page'] ?? '') === $p['id'] ? 'selected' : '' ?>><?= e($p['title'] ?: 'Untitled') ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="export-page-field-format">Format</label>
            <select name="format" id="export-page-field-format" class="form-select btn-touch mb-2"><option value="md">Markdown</option><option value="html">HTML (print-ready)</option></select>
            <label class="d-flex align-items-center gap-2 btn-touch mb-2" for="export-page-field-sub"><input type="checkbox" class="form-check-input mt-0" name="include_subpages" value="yes" id="export-page-field-sub"><span class="fs-12">Include its subpages (a zip)</span></label>
            <button type="submit" class="btn btn-primary btn-touch w-100" id="export-page-btn">Export the page</button>
        </div></form></div>
        <div class="col-12 col-xl-6"><form method="post" action="/exports/database.php" hx-post="/exports/database.php" hx-target="#flash" class="card h-100" id="export-database-form"><div class="card-header"><h5 class="card-title mb-0">A database</h5></div><div class="card-body">
            <?= csrf_field() ?>
            <label class="form-label fs-12 text-muted" for="export-database-field-database">Database</label>
            <select name="database" id="export-database-field-database" class="form-select btn-touch mb-2" required><option value="">Choose a database</option><?php foreach ($picks['databases'] as $d): ?><option value="<?= e($d['id']) ?>" <?= ($sel['database'] ?? '') === $d['id'] ? 'selected' : '' ?>><?= e($d['title'] ?: 'Untitled') ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="export-database-field-format">Format</label>
            <select name="format" id="export-database-field-format" class="form-select btn-touch mb-2"><option value="csv">CSV (every row, as a sheet shows it)</option><option value="json">JSON (the schema and the rows)</option></select>
            <button type="submit" class="btn btn-primary btn-touch w-100" id="export-database-btn">Export the database</button>
        </div></form></div>
        <?php endif; ?>
        <?php if ($may['space'] && ($picks['spaces'] !== [] || $may['all'])): ?>
        <div class="col-12 col-xl-6"><form method="post" action="/exports/space.php" hx-post="/exports/space.php" hx-target="#flash" hx-confirm="Export this whole space? It is made in the background." class="card h-100" id="export-space-form"><div class="card-header"><h5 class="card-title mb-0">A space</h5></div><div class="card-body">
            <?= csrf_field() ?>
            <label class="form-label fs-12 text-muted" for="export-space-field-space">Space</label>
            <select name="space" id="export-space-field-space" class="form-select btn-touch mb-2" required><option value="">Choose a space</option><?php foreach ($picks['spaces'] as $s): ?><option value="<?= (int) $s['space_id'] ?>" <?= (int) ($sel['space'] ?? 0) === (int) $s['space_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="export-space-field-format">Format</label>
            <select name="format" id="export-space-field-format" class="form-select btn-touch mb-2"><option value="md">Zip of Markdown</option><option value="html">Zip of HTML</option><option value="json">JSON</option></select>
            <button type="submit" class="btn btn-primary btn-touch w-100" id="export-space-btn">Export the space</button>
        </div></form></div>
        <div class="col-12 col-xl-6"><form method="post" action="/exports/channel.php" hx-post="/exports/channel.php" hx-target="#flash" hx-confirm="Export this channel's messages? It is made in the background." class="card h-100" id="export-channel-form"><div class="card-header"><h5 class="card-title mb-0">A channel</h5></div><div class="card-body">
            <?= csrf_field() ?>
            <label class="form-label fs-12 text-muted" for="export-channel-field-channel">Channel</label>
            <select name="channel" id="export-channel-field-channel" class="form-select btn-touch mb-2" required><option value="">Choose a channel</option><?php foreach ($picks['channels'] as $c): ?><option value="<?= (int) $c['channel_id'] ?>" <?= (int) ($sel['channel'] ?? 0) === (int) $c['channel_id'] ? 'selected' : '' ?>><?= e($c['space_name']) ?> · #<?= e($c['name']) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="export-channel-field-format">Format</label>
            <select name="format" id="export-channel-field-format" class="form-select btn-touch mb-2"><option value="json">JSON (threads nested)</option><option value="md">Markdown (day by day)</option><option value="csv">CSV</option></select>
            <div class="row g-2 mb-2"><div class="col-6"><label class="form-label fs-12 text-muted" for="export-channel-field-from">From</label><input type="date" name="from" id="export-channel-field-from" class="form-control btn-touch"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="export-channel-field-to">To</label><input type="date" name="to" id="export-channel-field-to" class="form-control btn-touch"></div></div>
            <button type="submit" class="btn btn-primary btn-touch w-100" id="export-channel-btn">Export the channel</button>
        </div></form></div>
        <?php endif; ?>
        <?php if ($may['all']): ?>
        <div class="col-12"><form method="post" action="/exports/all.php" hx-post="/exports/all.php" hx-target="#flash" hx-confirm="Export everything you may read? It is made in the background." class="card" id="export-all-form"><div class="card-header"><h5 class="card-title mb-0">Everything</h5></div><div class="card-body">
            <?= csrf_field() ?>
            <div class="fs-12 text-muted mb-2">Every space and every channel you may read, as one zip. Direct messages are never in it.</div>
            <button type="submit" class="btn btn-primary btn-touch w-100" id="export-all-btn">Export everything</button>
        </div></form></div>
        <?php endif; ?>
    </div>
    <h6 class="text-muted fs-12 text-uppercase mb-2" id="export-past-title"><?= $all ? 'Everyone\'s exports' : 'My exports' ?></h6>
    <div id="export-rows">
        <?php if ($rows === []): ?><div class="card" id="export-empty"><div class="card-body"><div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="feather-download-cloud"></i></span><div><div class="fw-semibold">Nothing exported yet</div><div class="fs-12 text-muted">Make an export above; it stays here for 7 days.</div></div></div></div></div><?php endif; ?>
        <?php foreach ($rows as $e): ?><?= view('exports/partials/row.php', ['e' => $e, 'tz' => $tz, 'all' => $all]) ?><?php endforeach; ?>
    </div>
</div>
