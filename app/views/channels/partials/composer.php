<?php /** The composer — one for the channel, the thread, the DM. Data: c, action (post|reply), rootId?, may (post), compact (bool) */ $cid = (int) $c['channel_id']; $reply = $action === 'reply'; $fid = $reply ? 'thread-composer' : 'composer'; ?>
<?php if (!$may['post']): ?>
<div class="sp-composer-off fs-12 text-muted" id="<?= $fid ?>-off"><?= $c['archived_at'] !== null ? 'This channel is archived: nothing more is posted in it.' : (is_guest() ? 'You may read here; a member of the space posts.' : 'Follow the channel to post in it.') ?></div>
<?php else: ?>
<form method="post" action="/channels/messages/<?= $reply ? 'reply' : 'post' ?>.php" class="sp-composer" id="<?= $fid ?>" data-channel="<?= $cid ?>" data-action="<?= e($action) ?>" enctype="application/x-www-form-urlencoded">
    <?= csrf_field() ?>
    <?php if ($reply): ?><input type="hidden" name="message" value="<?= (int) $rootId ?>"><?php else: ?><input type="hidden" name="channel" value="<?= $cid ?>"><?php endif; ?>
    <input type="hidden" name="runs" value="" class="sp-composer-runs"><input type="hidden" name="attachments" value="" class="sp-composer-attachments"><input type="hidden" name="schedule_for" value="" class="sp-composer-schedule">
    <div class="sp-composer-files d-none" id="<?= $fid ?>-files"></div>
    <div class="sp-composer-box">
        <div class="sp-composer-input sp-js" hidden id="<?= $fid ?>-box" contenteditable="true" role="textbox" aria-multiline="true" aria-label="<?= $reply ? 'Reply' : 'Message ' . e($c['label']) ?>" data-placeholder="<?= $reply ? 'Reply…' : 'Message ' . e($c['label']) . ' — @ to mention, : for emoji' ?>"></div>
        <noscript><textarea name="markdown" class="form-control" rows="2" placeholder="<?= $reply ? 'Reply…' : 'Message ' . e($c['label']) ?>" id="<?= $fid ?>-textarea"></textarea></noscript>
        <div class="sp-composer-bar d-flex flex-wrap align-items-center gap-1 mt-1">
            <button type="button" class="btn btn-light btn-sm btn-touch sp-composer-attach sp-js" hidden id="<?= $fid ?>-attach" title="Attach a file"><i class="feather-paperclip"></i></button>
            <button type="button" class="btn btn-light btn-sm btn-touch sp-composer-emoji sp-js" hidden id="<?= $fid ?>-emoji" title="Emoji">🙂</button>
            <?php if (!$reply): ?><button type="button" class="btn btn-light btn-sm btn-touch sp-composer-later sp-js" hidden id="<?= $fid ?>-later" title="Send later"><i class="feather-clock"></i></button><?php endif; ?>
            <?php if ($reply): ?><label class="fs-12 d-flex align-items-center gap-1 ms-1 btn-touch" for="<?= $fid ?>-also"><input type="checkbox" name="also_to_channel" value="yes" id="<?= $fid ?>-also" class="form-check-input mt-0"> Also send to <?= e($c['label']) ?></label><?php endif; ?>
            <span class="fs-11 text-muted ms-auto d-none d-md-inline">Enter sends · Shift+Enter a new line</span>
            <button type="submit" class="btn btn-primary btn-sm btn-touch" id="<?= $fid ?>-send"><i class="feather-send me-1"></i>Send</button>
        </div>
        <div class="sp-composer-later-row d-none mt-1" id="<?= $fid ?>-later-row"><label class="fs-12 text-muted" for="<?= $fid ?>-later-at">Send at</label> <input type="datetime-local" class="form-control form-control-sm d-inline-block w-auto" id="<?= $fid ?>-later-at"> <button type="button" class="btn btn-light btn-sm sp-composer-later-clear">Send now instead</button></div>
    </div>
    <input type="file" class="d-none sp-composer-file-input" id="<?= $fid ?>-file" accept="image/*,.pdf,.txt,.csv,.md,.json,.zip,.doc,.docx,.xls,.xlsx,.ppt,.pptx,audio/*,video/*">
</form>
<?php endif; ?>
