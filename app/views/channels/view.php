<?php /** The conversation (screens `channel-view`, `dm-view`). Data: c, rows, unread, members, running, may, me, before, focus, here, tz, notice */
$cid = (int) $c['channel_id']; $isDm = in_array($c['kind'], ['dm', 'group_dm'], true); $title = $isDm ? ($c['other_names'] ?: 'Conversation') : $c['label'];
$firstId = $rows === [] ? null : (int) $rows[0]['message_id']; $lastId = $rows === [] ? 0 : (int) $rows[count($rows) - 1]['message_id'];
$buttons = '';
if ($may['join']) { $buttons .= '<form method="post" action="/channels/join.php" hx-post="/channels/join.php" hx-target="#flash" class="d-inline">' . csrf_field() . '<input type="hidden" name="channel" value="' . $cid . '"><input type="hidden" name="return_to" value="/channels/' . $cid . '"><button type="submit" class="btn btn-primary btn-touch" id="channel-view-join-btn">Follow</button></form> '; }
if (!$isDm) { $buttons .= hx_link(with_back('/channels/' . $cid . '/members', $here), '<i class="feather-users me-1"></i>' . (int) $c['member_count'], 'btn btn-light btn-touch', 'id="channel-view-members-btn" title="Members"') . ' ' . hx_link(with_back('/channels/' . $cid . '/pins', $here), '<i class="feather-map-pin me-1"></i>' . (int) $c['pin_count'], 'btn btn-light btn-touch', 'id="channel-view-pins-btn" title="Pins and bookmarks"') . ' '; }
$menuForm = static fn (string $action, array $fields, string $label, string $idSuffix, ?string $confirm = null): string =>
    '<form method="post" action="' . e($action) . '" hx-post="' . e($action) . '" hx-target="#flash"' . ($confirm !== null ? ' hx-confirm="' . e($confirm) . '"' : '') . ' class="m-0">' . csrf_field() . implode('', array_map(static fn ($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">', array_keys($fields), $fields))
    . '<button type="submit" class="list-group-item list-group-item-action text-start w-100 border-0" id="channel-menu-' . $idSuffix . '">' . $label . '</button></form>';
$menu = '<details class="sp-menu" id="channel-menu"><summary class="btn btn-light btn-touch" aria-label="Channel menu"><i class="feather-more-horizontal"></i></summary><div class="sp-menu-popover card shadow"><div class="list-group list-group-flush">';
if ($c['i_follow']) {
    $menu .= $menuForm('/channels/notify.php', ['channel' => $cid, 'starred' => $c['starred'] ? 'no' : 'yes', 'return_to' => channel_path($c)], '<i class="feather-star me-2"></i>' . ($c['starred'] ? 'Unstar' : 'Star'), 'star');
    foreach (['all' => 'Tell me about everything', 'mentions' => 'Only mentions and replies', 'none' => 'Mute (nothing)'] as $k => $w) { $menu .= $menuForm('/channels/notify.php', ['channel' => $cid, 'notify' => $k, 'return_to' => channel_path($c)], '<i class="feather-' . ($k === 'none' ? 'bell-off' : 'bell') . ' me-2"></i>' . $w . (($c['notify'] ?? 'all') === $k ? ' <span class="badge bg-soft-primary text-primary ms-1">now</span>' : ''), 'notify-' . $k); }
}
if ($may['edit']) { $menu .= hx_link(with_back('/channels/' . $cid . '/edit', $here), '<i class="feather-edit-2 me-2"></i>Topic, purpose, name', 'list-group-item list-group-item-action', 'id="channel-menu-edit"'); }
if ($may['announce']) { $menu .= '<button type="button" class="list-group-item list-group-item-action" id="channel-menu-announce"><i class="feather-volume-2 me-2"></i>Announce to @channel…</button>'; }
if ($may['leave']) { $menu .= $menuForm('/channels/leave.php', ['channel' => $cid, 'return_to' => $isDm ? '/dm/' : '/channels/'], '<i class="feather-log-out me-2"></i>Leave', 'leave', 'Leave ' . $title . '?'); }
if ($may['archive'] && $c['archived_at'] === null) { $menu .= $menuForm('/channels/archive.php', ['channel' => $cid, 'return_to' => '/channels/' . $cid], '<i class="feather-archive me-2"></i>Archive', 'archive', 'Archive ' . $title . '? It stays readable; nothing more is posted.'); }
if ($may['archive'] && $c['archived_at'] !== null) { $menu .= $menuForm('/channels/unarchive.php', ['channel' => $cid, 'return_to' => '/channels/' . $cid], '<i class="feather-rotate-ccw me-2"></i>Unarchive', 'unarchive'); }
if ($may['delete']) { $menu .= $menuForm('/channels/delete.php', ['channel' => $cid, 'return_to' => '/channels/'], '<i class="feather-x-circle me-2"></i>Delete for good', 'delete', 'Delete ' . $title . ' and every message in it, for good?'); }
$menu .= '</div></div></details>';
$crumbs = $isDm ? [['Home', '/'], ['Direct messages', '/dm/'], [$title, null]] : [['Home', '/'], ['Channels', '/channels/'], [$title, null]]; ?>
<?= view('shared/header.php', ['id' => 'channel-view', 'title' => ($isDm ? '' : ($c['kind'] === 'private' ? '🔒 ' : '')) . $title, 'crumbs' => $crumbs, 'back' => back_link(), 'action' => '<div class="d-flex flex-wrap gap-1 justify-content-end align-items-center" id="channel-view-buttons">' . $buttons . $menu . '</div>']) ?>
<div class="main-content sp-channel" id="channel-view-content" data-channel="<?= $cid ?>" data-last="<?= $lastId ?>" data-first="<?= (int) $firstId ?>" data-unread-first="<?= (int) ($unread['first_unread_id'] ?? 0) ?>" data-focus="<?= (int) $focus ?>" data-member="<?= $c['i_am_member'] ? '1' : '0' ?>" data-since="/channels/<?= $cid ?>/since" data-hashes="<?= e(json_encode(row_hashes($rows))) ?>">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-2 d-flex flex-wrap gap-2 align-items-center" id="channel-view-head">
        <?php if ($isDm): ?><span><i class="feather-<?= $c['kind'] === 'dm' ? 'user' : 'users' ?> me-1"></i><?php foreach ($members as $mm): if ($mm['member_id'] === $me) { continue; } ?><span class="me-1"><?= e($mm['display_name']) ?><?= $mm['is_agent'] ? ' <span class="badge bg-soft-info text-info">agent</span>' : '' ?></span><?php endforeach; ?></span>
        <?php else: ?><?php if (($c['topic'] ?? '') !== ''): ?><span id="channel-view-topic"><?= e($c['topic']) ?></span><?php endif; ?><?php if (($c['purpose'] ?? '') !== ''): ?><span class="text-muted">· <?= e($c['purpose']) ?></span><?php endif; ?><?php endif; ?>
        <?php if (!$isDm && ($c['retention_days'] ?? null) !== null): ?><span class="badge bg-soft-warning text-warning" id="channel-view-retention"><i class="feather-clock me-1"></i>Messages older than <?= (int) $c['retention_days'] ?> day<?= (int) $c['retention_days'] === 1 ? '' : 's' ?> are deleted</span><?php endif; ?>
        <?php if ($c['archived_at'] !== null): ?><span class="badge bg-dark" id="channel-view-archived">archived</span><?php endif; ?>
        <?php if ($c['starred']): ?><span class="text-warning">★</span><?php endif; ?>
        <?php if (($c['notify'] ?? 'all') === 'none'): ?><span class="badge bg-soft-secondary text-secondary" id="channel-view-muted">muted</span><?php endif; ?>
    </div>
    <div class="card sp-channel-card" id="channel-view-card">
        <div class="sp-messages-wrap" id="messages-wrap">
            <div class="text-center py-2" id="load-earlier"><?php if ($firstId !== null && count($rows) >= 50): ?><a href="/channels/<?= $cid ?>?before=<?= $firstId ?>" class="btn btn-light btn-sm btn-touch" id="load-earlier-btn" data-before="<?= $firstId ?>">Load earlier</a><?php endif; ?></div>
            <div class="sp-messages" id="messages" hx-get="/channels/<?= $cid ?>/since" hx-trigger="every 3s [document.visibilityState=='visible'], every 20s" hx-vals="js:{after: SPChannel.after(), hashes: SPChannel.hashes()}" hx-target="this" hx-swap="beforeend" hx-sync="this:drop">
                <?php $lastDay = ''; $lineShown = false; foreach ($rows as $m): $day = format_ts($m['sent_at'] ?? $m['created_at'], $tz, 'Y-m-d'); ?>
                    <?php if ($day !== $lastDay): $lastDay = $day; ?><?= view('channels/partials/day-divider.php', ['date' => $day, 'label' => format_ts($m['sent_at'] ?? $m['created_at'], $tz, 'l, F j')]) ?><?php endif; ?>
                    <?php if (!$lineShown && ($unread['first_unread_id'] ?? null) !== null && (int) $m['message_id'] >= (int) $unread['first_unread_id']): $lineShown = true; ?><?= view('channels/partials/unread-line.php', ['n' => $unread['unread']]) ?><?php endif; ?>
                    <?= view('channels/partials/message-row.php', ['m' => $m, 'c' => $c, 'me' => $me, 'tz' => $tz, 'inThread' => false, 'may' => $may, 'oob' => false]) ?>
                <?php endforeach; ?>
                <?php if ($rows === []): ?><div class="text-muted fs-12 p-3" id="messages-empty"><?= $isDm ? 'Say hello.' : 'Nothing has been said in ' . e($title) . ' yet.' ?></div><?php endif; ?>
            </div>
        </div>
        <div class="sp-thinking fs-12 text-muted px-3 py-1<?= $running === [] ? ' d-none' : '' ?>" id="channel-thinking"><?= $running === [] ? '' : e($running[0]['agent_name']) . ' is thinking…' ?></div>
        <div class="sp-composer-wrap" id="composer-wrap"><?= view('channels/partials/composer.php', ['c' => $c, 'action' => 'post', 'may' => $may, 'compact' => false]) ?></div>
    </div>
    <?php if ($may['announce']): ?>
    <form method="post" action="/channels/messages/announce.php" hx-post="/channels/messages/announce.php" hx-target="#flash" hx-confirm="Announce to everyone in <?= e($title) ?>?" class="card mt-3 d-none" id="announce-form"><div class="card-header"><h5 class="card-title mb-0">Announce</h5></div><div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="channel" value="<?= $cid ?>"><input type="hidden" name="return_to" value="/channels/<?= $cid ?>">
        <div class="d-flex flex-wrap gap-2 mb-2"><?php foreach (['channel' => '@channel — everyone in it', 'here' => '@here — those here now', 'everyone' => '@everyone — everyone in the space'] as $k => $w): ?><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="announce-reach-<?= $k ?>"><input type="radio" class="form-check-input mt-0" name="reach" value="<?= $k ?>" id="announce-reach-<?= $k ?>" <?= $k === 'channel' ? 'checked' : '' ?>><span class="fs-12"><?= e($w) ?></span></label><?php endforeach; ?></div>
        <textarea name="markdown" class="form-control mb-2" rows="2" id="announce-field-markdown" required placeholder="What everyone should hear"></textarea>
        <button type="submit" class="btn btn-primary btn-touch" id="announce-send-btn">Announce</button>
    </div></form>
    <?php endif; ?>
    <template id="remind-template"><form method="post" action="/reminders/save.php" class="sp-msg-form sp-remind-form card card-body p-2 mt-1"><?= csrf_field() ?><input type="hidden" name="message" value=""><input type="hidden" name="return_to" value="<?= e(channel_path($c)) ?>"><label class="fs-12 text-muted">Remind me at</label><div class="d-flex gap-2"><input type="datetime-local" name="remind_at" class="form-control form-control-sm" required><button type="submit" class="btn btn-primary btn-sm btn-touch">Set</button><button type="button" class="btn btn-light btn-sm btn-touch sp-remind-cancel">Cancel</button></div></form></template>
</div>
<link rel="stylesheet" href="/assets/css/channel.css">
<script src="/assets/js/richtext.js"></script>
<script src="/assets/js/channel.js"></script>
