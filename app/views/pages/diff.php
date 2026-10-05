<?php /** The line diff (this version → the other). Data: diff = [['op','line']], otherLabel */ ?>
<div class="card h-100" id="diff-view"><div class="card-header"><h5 class="card-title mb-0">What differs from <?= e($otherLabel) ?></h5></div>
<div class="card-body p-0"><pre class="sp-diff mb-0"><?php foreach ($diff as $d): ?><div class="sp-diff-line sp-diff-<?= $d['op'] === '+' ? 'add' : ($d['op'] === '-' ? 'del' : 'same') ?>"><span class="sp-diff-op"><?= $d['op'] === ' ' ? '&nbsp;' : e($d['op']) ?></span><?= e($d['line']) ?: '&nbsp;' ?></div><?php endforeach; ?></pre></div></div>
