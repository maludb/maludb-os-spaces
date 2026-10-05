<?php /** The reactions under a message: a chip per emoji, mine highlighted; a tap toggles. Data: m, may, rid */ $id = (int) $m['message_id']; $rid = $rid ?? ('message-row-' . $id); if ($m['reactions'] === []) { return; } ?>
<div class="d-flex flex-wrap gap-1 mt-1 sp-reactions" id="<?= $rid ?>-reactions">
    <?php foreach ($m['reactions'] as $r): $mine = !empty($r['mine']); ?>
    <form method="post" action="/channels/messages/<?= $mine ? 'unreact' : 'react' ?>.php" class="sp-msg-form m-0"><?= csrf_field() ?><input type="hidden" name="message" value="<?= $id ?>"><input type="hidden" name="emoji" value="<?= e($r['emoji']) ?>">
        <button type="submit" class="btn btn-sm sp-reaction<?= $mine ? ' active' : '' ?>" <?= $may['member'] ? '' : 'disabled' ?> title="<?= (int) $r['count'] ?> reaction<?= (int) $r['count'] === 1 ? '' : 's' ?>"><?= e($r['emoji']) ?> <span class="sp-reaction-count"><?= (int) $r['count'] ?></span></button></form>
    <?php endforeach; ?>
</div>
