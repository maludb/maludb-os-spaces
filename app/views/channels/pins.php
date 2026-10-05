<?php /** Pins and bookmarks (screen `channel-pins`). Data: c, pins, bookmarks, pages, may (write), here, tz, notice */ $cid = (int) $c['channel_id']; ?>
<?= view('shared/header.php', ['id' => 'channel-pins', 'title' => 'Pins of ' . $c['label'], 'crumbs' => [['Home', '/'], ['Channels', '/channels/'], [$c['label'], '/channels/' . $cid], ['Pins', null]], 'back' => back_link() ?? ['/channels/' . $cid, $c['label']]]) ?>
<div class="main-content" id="channel-pins-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Pinned · <?= count($pins) ?></h5></div><div class="card-body p-0"><div class="list-group list-group-flush" id="channel-pins-list">
                <?php if ($pins === []): ?><div class="list-group-item text-muted fs-12" id="channel-pins-empty">Nothing pinned yet — pin a message from its menu, or a page below.</div><?php endif; ?>
                <?php foreach ($pins as $p): $pid = (int) $p['pin_id']; ?>
                <div class="list-group-item d-flex justify-content-between align-items-start gap-2" id="pin-<?= $pid ?>">
                    <div class="min-w-0"><?php if ($p['message_id'] !== null): ?><?= hx_link('/channels/' . $cid . '?message=' . (int) $p['message_id'], '<i class="feather-message-square me-1"></i>' . e((string) ($p['message_author'] ?? 'someone')) . ': ' . e((string) ($p['message_text'] ?? '')), 'text-dark') ?><?php else: ?><?= hx_link('/pages/' . $p['page_id'], '<i class="feather-file-text me-1"></i>' . e((string) ($p['page_title'] ?? 'a page')), 'text-dark') ?><?php endif; ?>
                        <div class="fs-12 text-muted">pinned by <?= e((string) ($p['pinned_by_name'] ?? 'someone')) ?> · <?= e(format_ts($p['created_at'], $tz, 'M j')) ?></div></div>
                    <?php if ($may['write']): ?><form method="post" action="/channels/pins/remove.php" hx-post="/channels/pins/remove.php" hx-target="#flash" class="m-0"><?= csrf_field() ?><input type="hidden" name="channel" value="<?= $cid ?>"><?= $p['message_id'] !== null ? '<input type="hidden" name="message" value="' . (int) $p['message_id'] . '">' : '<input type="hidden" name="page" value="' . e($p['page_id']) . '">' ?><input type="hidden" name="return_to" value="/channels/<?= $cid ?>/pins"><button type="submit" class="btn btn-light btn-sm btn-touch" id="pin-<?= $pid ?>-unpin-btn">Unpin</button></form><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div></div></div>
            <div class="card"><div class="card-header"><h5 class="card-title mb-0">Bookmarks · <?= count($bookmarks) ?></h5></div><div class="card-body p-0"><div class="list-group list-group-flush" id="channel-bookmarks-list">
                <?php if ($bookmarks === []): ?><div class="list-group-item text-muted fs-12" id="channel-bookmarks-empty">No bookmarks yet.</div><?php endif; ?>
                <?php foreach ($bookmarks as $b): $bid = (int) $b['bookmark_id']; ?>
                <div class="list-group-item d-flex justify-content-between align-items-center gap-2" id="bookmark-<?= $bid ?>">
                    <div class="min-w-0 text-truncate"><?= e($b['emoji'] ?? '🔗') ?> <?php if ($b['url'] !== null): ?><a href="<?= e($b['url']) ?>" target="_blank" rel="noopener" class="text-dark fw-semibold"><?= e($b['title']) ?></a> <span class="fs-12 text-muted"><?= e((string) parse_url($b['url'], PHP_URL_HOST)) ?></span><?php else: ?><?= hx_link('/pages/' . $b['page_id'], e($b['title']), 'text-dark fw-semibold') ?> <span class="fs-12 text-muted">page</span><?php endif; ?></div>
                    <?php if ($may['write']): ?><form method="post" action="/channels/bookmarks/delete.php" hx-post="/channels/bookmarks/delete.php" hx-target="#flash" hx-confirm="Remove the bookmark <?= e($b['title']) ?>?" class="m-0"><?= csrf_field() ?><input type="hidden" name="bookmark" value="<?= $bid ?>"><input type="hidden" name="return_to" value="/channels/<?= $cid ?>/pins"><button type="submit" class="btn btn-light btn-sm btn-touch" id="bookmark-<?= $bid ?>-delete-btn">Remove</button></form><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div></div></div>
        </div>
        <?php if ($may['write']): ?>
        <div class="col-lg-5">
            <form method="post" action="/channels/bookmarks/save.php" hx-post="/channels/bookmarks/save.php" hx-target="#flash" class="card mb-3" id="bookmark-form"><div class="card-header"><h5 class="card-title mb-0">Add a bookmark</h5></div><div class="card-body">
                <?= csrf_field() ?><input type="hidden" name="channel" value="<?= $cid ?>"><input type="hidden" name="return_to" value="/channels/<?= $cid ?>/pins">
                <label class="form-label fs-12 text-muted" for="bookmark-form-field-title">Title</label><input type="text" name="title" id="bookmark-form-field-title" class="form-control btn-touch mb-2" maxlength="100" required>
                <label class="form-label fs-12 text-muted" for="bookmark-form-field-url">Link</label><input type="url" name="url" id="bookmark-form-field-url" class="form-control btn-touch mb-2" placeholder="https://…">
                <label class="form-label fs-12 text-muted" for="bookmark-form-field-page">or a page</label><select name="page" id="bookmark-form-field-page" class="form-select btn-touch mb-2"><option value="">—</option><?php foreach ($pages as $pg): ?><option value="<?= e($pg['page_id']) ?>"><?= e($pg['plain_title'] ?: 'Untitled') ?></option><?php endforeach; ?></select>
                <label class="form-label fs-12 text-muted" for="bookmark-form-field-emoji">Emoji</label><input type="text" name="emoji" id="bookmark-form-field-emoji" class="form-control btn-touch mb-2" maxlength="16" placeholder="🔗">
                <button type="submit" class="btn btn-primary btn-touch w-100" id="bookmark-form-save-btn">Add</button>
            </div></form>
            <form method="post" action="/channels/pins/add.php" hx-post="/channels/pins/add.php" hx-target="#flash" class="card" id="pin-page-form"><div class="card-header"><h5 class="card-title mb-0">Pin a page</h5></div><div class="card-body">
                <?= csrf_field() ?><input type="hidden" name="channel" value="<?= $cid ?>"><input type="hidden" name="return_to" value="/channels/<?= $cid ?>/pins">
                <select name="page" id="pin-page-field" class="form-select btn-touch mb-2" required><option value="">Choose a page…</option><?php foreach ($pages as $pg): ?><option value="<?= e($pg['page_id']) ?>"><?= e($pg['plain_title'] ?: 'Untitled') ?></option><?php endforeach; ?></select>
                <button type="submit" class="btn btn-outline-primary btn-touch w-100" id="pin-page-btn">Pin the page</button>
            </div></form>
        </div>
        <?php endif; ?>
    </div>
</div>
