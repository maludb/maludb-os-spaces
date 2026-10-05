<?php
/**
 * The sidebar's tree (sso-shell.md "The shell"): Favorites, each space (its sections and root pages, its channels with unread
 * badges), Shared with me, Private, Direct messages — one sp_sidebar() call, one sp_unread() call. A guest's holds Shared with
 * me and Direct messages only. Data: sb (sidebar()), unread (unread_counts()), here (current_path()), guest (bool), mayWrite (bool).
 */
$here = $here ?? current_path();
$link = static function (string $url, string $html, string $id = '', string $class = '') use ($here): string {
    return '<a href="' . e($url) . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . ' class="' . e(trim($class . ($here === $url ? ' active' : ''))) . '"'
        . ' hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url) . '">' . $html . '</a>';
};
$badge = static function (array $u): string {
    if (($u['unread'] ?? 0) < 1) { return ''; }
    return '<span class="sp-tree-badge' . (($u['mentions'] ?? 0) > 0 ? ' mention' : '') . '">' . ($u['unread'] > 99 ? '99+' : (int) $u['unread']) . '</span>';
};
$channelItem = static function (array $ch, string $prefix) use ($link, $badge, $unread): string {
    $cid = (int) $ch['channel_id'];
    $u = $unread[$cid] ?? [];
    $url = '/channels/' . $cid;
    $icon = ($ch['kind'] ?? '') === 'private' ? '<i class="feather-lock fs-11"></i>' : '#';
    return '<li class="sp-tree-item' . (($u['unread'] ?? 0) > 0 ? ' sp-tree-unread' : '') . (!empty($ch['muted']) ? ' sp-tree-muted' : '') . '" id="' . $prefix . $cid . '"><div class="sp-tree-row"><span class="sp-tree-toggle leaf"></span>'
        . $link($url, '<span>' . $icon . '</span><span class="sp-tree-label">' . e($ch['name']) . '</span>' . $badge($u)) . '</div></li>';
};
?>
<div class="sp-tree" id="shell-sidebar">
<?php if (!$guest): ?>
    <?php if ($sb['favorites'] !== []): ?>
    <div class="sp-tree-caption" id="sidebar-favorites-caption"><span>Favorites</span></div>
    <ul id="sidebar-favorites"><?= view('shared/sidebar-pages.php', ['pages' => $sb['favorites'], 'here' => $here]) ?></ul>
    <?php endif; ?>
    <?php foreach ($sb['spaces'] as $sp): $sid = (int) $sp['space_id']; $surl = '/spaces/' . $sid; ?>
    <div class="sp-tree-caption" id="sidebar-space-<?= $sid ?>-caption">
        <?= $link($surl, '<span>' . e(($sp['icon'] ?? '') !== '' ? $sp['icon'] . ' ' : '') . e($sp['name']) . '</span>', 'sidebar-space-' . $sid . '-link') ?>
        <?php if ($mayWrite): ?><a href="/pages/new?space=<?= $sid ?>" class="fs-11" id="sidebar-space-<?= $sid ?>-new" title="New page in <?= e($sp['name']) ?>" aria-label="New page in <?= e($sp['name']) ?>"><i class="feather-plus"></i></a><?php endif; ?>
    </div>
    <ul id="sidebar-space-<?= $sid ?>">
        <?php foreach ($sp['sections'] ?? [] as $sec): ?>
        <li class="sp-tree-item" id="sidebar-section-<?= (int) $sec['section_id'] ?>">
            <div class="sp-tree-row"><span class="sp-tree-toggle leaf"></span><span class="fs-11 fw-semibold text-muted px-2"><?= e($sec['name']) ?></span></div>
            <ul><?= view('shared/sidebar-pages.php', ['pages' => $sec['pages'] ?? [], 'here' => $here]) ?></ul>
        </li>
        <?php endforeach; ?>
        <?= view('shared/sidebar-pages.php', ['pages' => $sp['pages'] ?? [], 'here' => $here]) ?>
        <?php foreach ($sp['channels'] ?? [] as $ch) { echo $channelItem($ch, 'sidebar-channel-'); } ?>
        <?php if (($sp['pages'] ?? []) === [] && ($sp['sections'] ?? []) === [] && ($sp['channels'] ?? []) === []): ?><li class="sp-tree-empty">Nothing here yet.</li><?php endif; ?>
    </ul>
    <?php endforeach; ?>
    <?php if ($sb['spaces'] === []): ?><div class="sp-tree-caption"><span>Spaces</span></div><div class="sp-tree-empty" id="sidebar-spaces-empty">You are in no space yet.</div><?php endif; ?>
<?php endif; ?>
    <div class="sp-tree-caption" id="sidebar-shared-caption"><span>Shared with me</span></div>
    <ul id="sidebar-shared">
        <?php if ($sb['shared'] === []): ?><li class="sp-tree-empty" id="sidebar-shared-empty">Nothing shared with you yet.</li><?php endif; ?>
        <?= view('shared/sidebar-pages.php', ['pages' => $sb['shared'], 'here' => $here]) ?>
    </ul>
<?php if (!$guest): ?>
    <div class="sp-tree-caption" id="sidebar-private-caption"><span>Private</span>
        <?php if (has_right('pages.private')): ?><a href="/pages/new?private=1" class="fs-11" id="sidebar-private-new" title="New private page" aria-label="New private page"><i class="feather-plus"></i></a><?php endif; ?>
    </div>
    <ul id="sidebar-private">
        <?php if ($sb['private'] === []): ?><li class="sp-tree-empty" id="sidebar-private-empty">No private pages.</li><?php endif; ?>
        <?= view('shared/sidebar-pages.php', ['pages' => $sb['private'], 'here' => $here]) ?>
    </ul>
<?php endif; ?>
    <div class="sp-tree-caption" id="sidebar-dms-caption"><span>Direct messages</span>
        <?php if (has_right('dm.write') || $guest): ?><a href="/dm/new" class="fs-11" id="sidebar-dm-new" title="New message" aria-label="New message"><i class="feather-plus"></i></a><?php endif; ?>
    </div>
    <ul id="sidebar-dms">
        <?php if ($sb['dms'] === []): ?><li class="sp-tree-empty" id="sidebar-dms-empty">No conversations yet.</li><?php endif; ?>
        <?php foreach ($sb['dms'] as $dm): $cid = (int) $dm['channel_id']; $n = (int) ($dm['unread_count'] ?? 0); $url = '/dm/' . $cid; ?>
        <li class="sp-tree-item<?= $n > 0 ? ' sp-tree-unread' : '' ?>" id="sidebar-dm-<?= $cid ?>"><div class="sp-tree-row"><span class="sp-tree-toggle leaf"></span>
            <?= $link($url, '<span><i class="feather-message-circle fs-11"></i></span><span class="sp-tree-label">' . e($dm['names'] ?? 'Conversation') . '</span>' . ($n > 0 ? '<span class="sp-tree-badge">' . ($n > 99 ? '99+' : $n) . '</span>' : '')) ?></div></li>
        <?php endforeach; ?>
    </ul>
<?php if (!$guest && $sb['joinable'] !== []): ?>
    <div class="sp-tree-caption" id="sidebar-joinable-caption"><span>More spaces</span></div>
    <ul id="sidebar-joinable">
        <?php foreach ($sb['joinable'] as $sp): $sid = (int) $sp['space_id']; ?>
        <li class="sp-tree-item" id="sidebar-joinable-<?= $sid ?>"><div class="sp-tree-row"><span class="sp-tree-toggle leaf"></span>
            <?= $link('/spaces/' . $sid, '<span>' . e(($sp['icon'] ?? '') !== '' ? $sp['icon'] : '◦') . '</span><span class="sp-tree-label">' . e($sp['name']) . '</span><span class="fs-11 text-muted ms-auto">' . e($sp['kind'] === 'open' ? 'join' : 'request') . '</span>') ?></div></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
</div>
