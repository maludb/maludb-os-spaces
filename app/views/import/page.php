<?php /** Import (screen `import`). Data: spaces, databases, space, parent, parentPage, database, preview, token, previewError, rows, tz, notice, kind, here */
$dest = $parent !== '' ? ['parent' => $parent] : ($database !== '' ? ['database' => $database] : ($space !== null ? ['space' => (string) $space] : [])); ?>
<?= view('shared/header.php', ['id' => 'import', 'title' => 'Import', 'crumbs' => [['Home', '/'], ['Import', null]], 'back' => back_link()]) ?>
<div class="main-content" id="import-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($previewError !== null): ?><div class="alert alert-danger" id="import-preview-error" role="alert"><?= e($previewError) ?></div><?php endif; ?>
    <?php if ($preview !== null): ?><?= view('import/partials/preview.php', ['preview' => $preview, 'token' => $token, 'dest' => $dest, 'kind' => $kind]) ?><?php endif; ?>
    <form method="post" action="/import/start.php" enctype="multipart/form-data" id="import-form" class="card mb-3">
        <div class="card-header"><h5 class="card-title mb-0">Bring something in</h5></div>
        <div class="card-body">
            <?= csrf_field() ?>
            <label class="form-label fs-12 text-muted" for="import-form-field-file">File: a .md, a .zip (a folder of Markdown or a Notion export) or a .csv</label>
            <input type="file" name="file" id="import-form-field-file" class="form-control btn-touch mb-3" accept=".md,.markdown,.zip,.csv" required>
            <label class="form-label fs-12 text-muted" for="import-form-field-kind">Kind</label>
            <select name="kind" id="import-form-field-kind" class="form-select btn-touch mb-3">
                <option value="">From the file</option>
                <?php foreach (IMPORT_KIND_WORDS as $k => $w): ?><option value="<?= e($k) ?>" <?= $kind === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?>
            </select>
            <?php if ($parentPage !== null): ?>
                <div class="fs-12 text-muted mb-1">Where</div>
                <div class="border rounded px-3 d-flex align-items-center btn-touch mb-3" id="import-form-where"><i class="feather-file-text me-2"></i>Under the page <strong class="ms-1"><?= e($parentPage['plain_title']) ?></strong></div>
                <input type="hidden" name="parent" value="<?= e($parent) ?>">
            <?php else: ?>
                <label class="form-label fs-12 text-muted" for="import-form-field-space">Into the space (pages made at its root)</label>
                <select name="space" id="import-form-field-space" class="form-select btn-touch mb-3">
                    <option value="">Choose a space</option>
                    <?php foreach ($spaces as $s): ?><option value="<?= (int) $s['space_id'] ?>" <?= (int) $s['space_id'] === (int) $space ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
                </select>
                <label class="form-label fs-12 text-muted" for="import-form-field-database">For a CSV: the database it goes into</label>
                <select name="database" id="import-form-field-database" class="form-select btn-touch mb-3">
                    <option value="">None</option>
                    <?php foreach ($databases as $d): ?><option value="<?= e($d['database_id']) ?>" <?= $d['database_id'] === $database ? 'selected' : '' ?>><?= e($d['title']) ?></option><?php endforeach; ?>
                </select>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="import-form-submit-btn">Import</button>
            <button type="submit" name="preview" value="yes" class="btn btn-light btn-touch w-100" id="import-form-preview-btn">Preview a zip first</button>
            <div class="fs-12 text-muted mt-2">A Markdown file or a small zip is imported at once; a large one is queued and this page shows its progress. What an import makes stays: if it is not what you wanted, move the pages to the trash.</div>
        </div>
    </form>
    <h6 class="text-muted fs-12 text-uppercase mb-2" id="import-past-title">Past imports</h6>
    <div id="import-rows">
        <?php if ($rows === []): ?><div class="card" id="import-empty"><div class="card-body"><div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="feather-upload-cloud"></i></span><div><div class="fw-semibold">Nothing imported yet</div><div class="fs-12 text-muted">Notion exports, folders of Markdown and CSVs all come in here.</div></div></div></div></div><?php endif; ?>
        <?php foreach ($rows as $i): ?><?= view('import/partials/row.php', ['i' => $i, 'tz' => $tz]) ?><?php endforeach; ?>
    </div>
</div>
