<?php /** Who else is here (the presence cache). Data: others = [{member_id, name}] */ ?>
<div class="sp-presence d-flex align-items-center gap-1 mb-2<?= $others === [] ? ' d-none' : '' ?>" id="presence-bar" aria-live="polite">
    <span class="fs-12 text-muted me-1">Here now:</span>
    <span id="presence-list"><?php foreach ($others as $o): ?><span class="avatar-text avatar-sm me-1" title="<?= e($o['name']) ?>" data-member="<?= (int) $o['member_id'] ?>"><?= e(mb_strtoupper(mb_substr((string) $o['name'], 0, 1))) ?></span><?php endforeach; ?></span>
</div>
