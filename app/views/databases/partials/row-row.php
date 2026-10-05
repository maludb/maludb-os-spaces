<?php /** One table row (id row-row-{row}); the cell-edit POST answers with this. Data: row, cols (visible properties), d, may, here, view, members? */ ?>
<tr id="row-row-<?= e($row['row_id']) ?>" data-row="<?= e($row['row_id']) ?>">
<?php foreach ($cols as $key => $def): ?>
    <?= view('databases/partials/cell.php', ['row' => $row, 'key' => $key, 'def' => $def, 'd' => $d, 'may' => $may, 'here' => $here, 'view' => $view, 'members' => $members ?? []]) ?>
<?php endforeach; ?>
</tr>
