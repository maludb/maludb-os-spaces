<?php /** The preview of a zip before it is imported (id import-preview). Data: preview (kind, counts, tree, file_name), token, dest (the hidden destination fields), kind */
$walk = static function (array $nodes) use (&$walk): string {
    if ($nodes === []) { return ''; }
    $h = '<ul class="list-unstyled ms-3 mb-0 fs-12">';
    foreach ($nodes as $n) {
        $h .= '<li class="py-1"><i class="' . ($n['type'] === 'database' ? 'feather-database' : 'feather-file-text') . ' me-1 text-muted"></i>' . e($n['title'])
            . ($n['type'] === 'database' ? ' <span class="text-muted">(' . (int) $n['rows'] . ' rows, ' . (int) $n['columns'] . ' columns)</span>' : '') . $walk($n['children']) . '</li>';
    }
    return $h . '</ul>';
}; ?>
<div class="card mb-3" id="import-preview">
    <div class="card-header"><h5 class="card-title mb-0">Preview: <?= e($preview['file_name']) ?></h5></div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-3 mb-2" id="import-preview-counts">
            <span><strong id="import-preview-pages"><?= (int) $preview['pages_total'] ?></strong> pages</span>
            <span><strong id="import-preview-databases"><?= (int) $preview['databases'] ?></strong> databases</span>
            <span><strong id="import-preview-rows"><?= (int) $preview['rows'] ?></strong> rows</span>
            <span><strong id="import-preview-unsupported"><?= (int) $preview['unsupported'] ?></strong> unsupported blocks</span>
        </div>
        <div class="border rounded p-2 mb-3" id="import-preview-tree"><?= $walk($preview['tree']) ?></div>
        <form method="post" action="/import/start.php" id="import-confirm-form">
            <?= csrf_field() ?>
            <input type="hidden" name="preview_token" value="<?= e($token) ?>"><input type="hidden" name="kind" value="<?= e($preview['kind']) ?>">
            <?php foreach ($dest as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
            <button type="submit" class="btn btn-primary btn-touch w-100" id="import-confirm-btn">Import this</button>
        </form>
    </div>
</div>
