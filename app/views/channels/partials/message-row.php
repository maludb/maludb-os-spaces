<?php /** One message (channel-view, thread-view, saved). Data: m, c, me, tz, inThread (bool), may (post, manage, member, human), oob (bool) */
$id = (int) $m['message_id']; $own = (int) ($m['author_member_id'] ?? 0) === $me; $deleted = ($m['deleted_at'] ?? null) !== null; $pending = ($m['kind'] ?? '') === 'agent_pending'; $cid = (int) $c['channel_id'];
$hash = substr(md5(($m['edited_at'] ?? '') . '|' . ($m['deleted_at'] ?? '') . '|' . $m['reply_count'] . '|' . json_encode($m['reactions']) . '|' . $m['attachment_count'] . '|' . ($m['kind'] ?? '')), 0, 12);
$threadUrl = '/channels/' . $cid . '/threads/' . (int) ($m['thread_root_id'] ?? $id);
$isRoot = $m['thread_root_id'] === null; $rid = ($inThread ? 'thread-row-' : 'message-row-') . $id;   // a thread's copy of a row has its own id: the two lists may show one message
$form = static fn (string $action, array $fields, string $label, string $idSuffix, ?string $confirm = null): string =>
    '<form method="post" action="' . e($action) . '" class="sp-msg-form m-0"' . ($confirm !== null ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field() . implode('', array_map(static fn ($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">', array_keys($fields), $fields))
    . '<button type="submit" class="list-group-item list-group-item-action text-start w-100 border-0" id="message-row-' . $id . '-' . $idSuffix . '">' . $label . '</button></form>';
if ($pending): ?>
<?= view('channels/partials/pending-row.php', ['m' => $m, 'oob' => $oob, 'inThread' => $inThread]) ?>
<?php return; endif; ?>
<div class="sp-message<?= $own ? ' sp-mine' : '' ?><?= $deleted ? ' sp-deleted' : '' ?>" id="<?= $rid ?>" data-id="<?= $id ?>" data-hash="<?= $hash ?>" data-author="<?= (int) ($m['author_member_id'] ?? 0) ?>" data-sent="<?= e((string) ($m['sent_at'] ?? '')) ?>"<?= $oob ? ' hx-swap-oob="outerHTML"' : '' ?>>
    <div class="d-flex gap-2 align-items-start">
        <span class="avatar-text avatar-sm flex-shrink-0 mt-1<?= !empty($m['author_is_agent']) ? ' sp-avatar-agent' : '' ?>" title="<?= e((string) ($m['author_name'] ?? 'someone')) ?>"><?= e(mb_strtoupper(mb_substr((string) ($m['author_name'] ?? '?'), 0, 1))) ?></span>
        <div class="min-w-0 flex-grow-1">
            <div class="d-flex flex-wrap align-items-baseline gap-2 fs-12">
                <span class="fw-semibold text-dark" id="<?= $rid ?>-author"><?= e((string) ($m['author_name'] ?? 'someone')) ?></span>
                <?php if (!empty($m['author_is_agent'])): ?><span class="badge bg-soft-info text-info">agent</span><?php endif; ?>
                <span class="text-muted" title="<?= e(format_ts($m['sent_at'] ?? $m['created_at'], $tz)) ?>" id="<?= $rid ?>-time"><?= e(format_ts($m['sent_at'] ?? $m['created_at'], $tz, 'g:i A')) ?></span>
                <?php if (($m['sent_at'] ?? null) === null && ($m['scheduled_for'] ?? null) !== null): ?><span class="badge bg-soft-warning text-warning" id="<?= $rid ?>-scheduled">scheduled for <?= e(format_ts($m['scheduled_for'], $tz, 'M j, g:i A')) ?></span><?php endif; ?>
                <?php if (($m['edited_at'] ?? null) !== null && !$deleted): ?><span class="text-muted fst-italic" id="<?= $rid ?>-edited">edited</span><?php endif; ?>
                <?php if (!empty($m['is_pinned'])): ?><span class="text-warning" title="Pinned"><i class="feather-map-pin"></i></span><?php endif; ?>
                <?php if (!empty($m['is_saved'])): ?><span class="text-primary" title="Saved"><i class="feather-bookmark"></i></span><?php endif; ?>
                <?php if (!empty($m['also_to_channel']) && !$inThread): ?><span class="text-muted">· also from a thread</span><?php endif; ?>
            </div>
            <div class="sp-message-body" id="<?= $rid ?>-body"><?php if ($deleted): ?><span class="text-muted fst-italic">This message was deleted.</span><?php else: ?><?= render_rich_text($m['body']) ?><?php endif; ?></div>
            <?php if (!$deleted && $m['attachments'] !== []): ?>
            <div class="d-flex flex-wrap gap-2 mt-1" id="<?= $rid ?>-files">
                <?php foreach ($m['attachments'] as $a): $aid = (int) $a['attachment_id']; ?>
                    <?php if (str_starts_with((string) $a['mime_type'], 'image/')): ?><a href="/files/<?= $aid ?>" target="_blank" rel="noopener" class="sp-msg-image"><img src="/files/<?= $aid ?>/thumb" alt="<?= e($a['filename']) ?>" loading="lazy" onerror="this.src='/files/<?= $aid ?>'"></a>
                    <?php else: ?><a href="/files/<?= $aid ?>" class="btn btn-light btn-sm" rel="noopener"><i class="feather-paperclip me-1"></i><?= e($a['filename']) ?> <span class="text-muted">(<?= (int) round((int) $a['byte_size'] / 1024) ?> KB)</span></a><?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if (!$deleted || $m['reactions'] !== []): ?><?= view('channels/partials/reaction-bar.php', ['m' => $m, 'may' => $may, 'rid' => $rid]) ?><?php endif; ?>
            <?php if ($isRoot && !$inThread && ((int) $m['reply_count'] > 0)): ?><?= view('channels/partials/thread-summary.php', ['m' => $m, 'url' => $threadUrl, 'tz' => $tz]) ?><?php endif; ?>
        </div>
        <?php if ($may['member']): ?>
        <details class="sp-menu sp-msg-menu flex-shrink-0" id="<?= $rid ?>-menu">
            <summary class="btn btn-light btn-sm sp-msg-menu-btn" aria-label="Message menu"><i class="feather-more-horizontal"></i></summary>
            <div class="sp-menu-popover card shadow"><div class="list-group list-group-flush">
                <?php if ($may['post'] && !$deleted): ?><div class="list-group-item sp-quick-react d-flex gap-1" data-message="<?= $id ?>"><?php foreach (['👍', '✅', '🎉', '👀', '❤️'] as $em): ?><form method="post" action="/channels/messages/react.php" class="sp-msg-form m-0"><?= csrf_field() ?><input type="hidden" name="message" value="<?= $id ?>"><input type="hidden" name="emoji" value="<?= e($em) ?>"><button type="submit" class="btn btn-light btn-sm sp-react-btn" title="React <?= e($em) ?>"><?= e($em) ?></button></form><?php endforeach; ?></div><?php endif; ?>
                <?php if ($isRoot && !$inThread && $may['post']): ?><a href="<?= e($threadUrl) ?>" class="list-group-item list-group-item-action sp-open-thread" id="<?= $rid ?>-thread" data-thread="<?= e($threadUrl) ?>"><i class="feather-message-circle me-2"></i>Reply in thread</a><?php endif; ?>
                <?php if (!$deleted): ?><?= $form('/channels/messages/save.php', ['message' => $id, 'saved' => !empty($m['is_saved']) ? 'no' : 'yes'], '<i class="feather-bookmark me-2"></i>' . (!empty($m['is_saved']) ? 'Remove from Saved' : 'Save for later'), 'save') ?><?php endif; ?>
                <?php if (!$deleted && $isRoot): ?><?= !empty($m['is_pinned']) ? $form('/channels/pins/remove.php', ['channel' => $cid, 'message' => $id, 'return_to' => '/channels/' . $cid], '<i class="feather-map-pin me-2"></i>Unpin', 'unpin') : $form('/channels/pins/add.php', ['channel' => $cid, 'message' => $id, 'return_to' => '/channels/' . $cid], '<i class="feather-map-pin me-2"></i>Pin', 'pin') ?><?php endif; ?>
                <?php if (!$deleted && $may['human']): ?><button type="button" class="list-group-item list-group-item-action sp-remind-btn" data-message="<?= $id ?>" id="<?= $rid ?>-remind"><i class="feather-clock me-2"></i>Remind me…</button><?php endif; ?>
                <?php if ($own && !$deleted && $may['post']): ?><button type="button" class="list-group-item list-group-item-action sp-edit-btn" data-message="<?= $id ?>" id="<?= $rid ?>-edit"><i class="feather-edit-2 me-2"></i>Edit</button><?php endif; ?>
                <?php if ($own && !$deleted): ?><?= $form('/channels/messages/delete-own.php', ['message' => $id], '<i class="feather-trash-2 me-2"></i>Delete', 'delete', 'Delete this message?') ?>
                <?php elseif ($may['manage'] && !$deleted): ?><?= $form('/channels/messages/delete.php', ['message' => $id], '<i class="feather-trash-2 me-2"></i>Delete (as the owner)', 'delete', 'Delete this message by ' . (string) ($m['author_name'] ?? 'someone') . '?') ?><?php endif; ?>
            </div></div>
        </details>
        <?php endif; ?>
    </div>
    <?php if ($own && !$deleted && $may['post']): ?>
    <form method="post" action="/channels/messages/edit.php" class="sp-msg-form sp-edit-form d-none mt-1" id="<?= $rid ?>-edit-form"><?= csrf_field() ?><input type="hidden" name="message" value="<?= $id ?>">
        <textarea name="markdown" class="form-control form-control-sm" rows="2" maxlength="20000"><?= e((string) ($m['markdown'] ?? '')) ?></textarea>
        <div class="d-flex gap-2 mt-1"><button type="submit" class="btn btn-primary btn-sm btn-touch">Save</button><button type="button" class="btn btn-light btn-sm btn-touch sp-edit-cancel">Cancel</button></div></form>
    <?php endif; ?>
</div>
