<?php /** The modifiers as removable chips (search-chips). Data: parsed */
$labels = ['in' => 'in', 'from' => 'from', 'has' => 'has', 'before' => 'before', 'after' => 'after', 'is' => 'is', 'space' => 'space'];
$any = false; foreach ($labels as $m => $_) { if ($parsed[$m] !== null) { $any = true; } } ?>
<?php if ($any): ?>
<div class="d-flex flex-wrap gap-2 mb-3" id="search-chips">
    <?php foreach ($labels as $m => $label): if ($parsed[$m] === null) { continue; } ?>
        <span class="badge bg-soft-primary text-primary d-inline-flex align-items-center gap-1 fs-12 py-2 px-3" id="search-chip-<?= $m ?>"><?= e($label) ?>: <?= e((string) $parsed[$m]) ?>
            <?= hx_link(search_url($parsed, [$m]), '<i class="feather-x"></i>', 'text-primary d-inline-flex align-items-center justify-content-center sp-chip-x', 'id="search-chip-' . $m . '-remove" aria-label="Remove ' . e($label) . '"') ?></span>
    <?php endforeach; ?>
</div>
<?php endif; ?>
