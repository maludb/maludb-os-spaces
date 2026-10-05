<?php
/**
 * The relation picker (id relation-picker): the rows of the target database with the current links ticked; Save posts the WHOLE list (row_relation_set).
 * Data: r (the row), key, def, target (the related database), cands, have (current links), ids, q, here
 */
$rid = $r['page_id'];
$back = '/databases/' . $r['parent_database_id'] . '/rows/' . $rid;
$name = (string) ($def['name'] ?? $key);
$union = [];
foreach ($have as $h) { $union[$h['row_id']] = $h['title']; }
foreach ($cands as $c) { $union[$c['row_id']] = $c['title']; }
?>
<div id="relation-picker">
<?= view('shared/header.php', ['id' => 'relation-picker-page', 'title' => 'Link ' . $name, 'crumbs' => [['Home', '/'], ['Databases', '/databases/'], [$r['database']['plain_title'], '/databases/' . $r['parent_database_id']], [$r['plain_title'] ?: 'Untitled', $back], [$name, null]], 'back' => back_link() ?? [$back, $r['plain_title'] ?: 'the row']]) ?>
<div class="main-content">
    <form method="get" action="/databases/rows/relation.php" class="card mb-3" id="relation-search"><div class="card-body p-3 d-flex gap-2">
        <input type="hidden" name="row" value="<?= e($rid) ?>"><input type="hidden" name="property" value="<?= e($key) ?>">
        <input type="search" name="q" class="form-control btn-touch" value="<?= e($q) ?>" placeholder="Search <?= e($target['plain_title'] ?? 'the rows') ?>" aria-label="Search the rows">
        <button type="submit" class="btn btn-light btn-touch">Search</button>
    </div></form>
    <form method="post" action="/databases/rows/relation.php" hx-post="/databases/rows/relation.php" hx-target="#flash" id="relation-form">
        <?= csrf_field() ?><input type="hidden" name="row" value="<?= e($rid) ?>"><input type="hidden" name="property" value="<?= e($key) ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
        <input type="hidden" name="targets[]" value="">
        <div class="card mb-3"><div class="list-group list-group-flush">
            <?php if ($union === []): ?><div class="list-group-item text-muted" id="relation-empty"><?= $target === null ? 'The related database is not one you can see.' : 'No rows to link' . ($q !== '' ? ' match.' : ' yet.') ?></div><?php endif; ?>
            <?php foreach ($union as $id => $title): ?>
                <label class="list-group-item d-flex align-items-center gap-2 btn-touch" id="relation-option-<?= e($id) ?>"><input type="checkbox" class="form-check-input mt-0" name="targets[]" value="<?= e($id) ?>" <?= in_array($id, $ids, true) ? 'checked' : '' ?>><span><?= e($title !== '' ? $title : 'Untitled') ?></span></label>
            <?php endforeach; ?>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="relation-save-btn">Save the links</button>
        <?= hx_link($back, 'Cancel', 'btn btn-light btn-touch w-100', 'id="relation-cancel-link"') ?>
    </form>
</div></div>
