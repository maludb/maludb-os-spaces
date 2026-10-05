<?php
declare(strict_types=1);

/**
 * The prelude of a channel handler (slice 4, THE SECOND EXEMPLAR): the feature's files, the channel a request names (through mcp_channels —
 * 404 when unseen), the gates (a member of the channel; its owner = a space owner, or channel.manage; the admin), and the one logger that
 * stamps channel_id and space_id on every row (message_id when there is one). Never a message's body in the trail.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/richtext/render.php';
require_once dirname(__DIR__, 2) . '/richtext/markdown.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

/** The channel the request names (`channel`, `channel_id` or `id`), through the view: 404 when unseen; $live refuses an archived one in words. */
function channel_from_request(PDO $pdo, bool $live = true): array
{
    $id = request_integer('channel') ?? request_integer('channel_id') ?? request_integer('id') ?? refuse(422, 'Say which channel.');
    $c = find_channel($pdo, $id) ?? refuse(404, 'Channel not found.');
    if ($live && $c['archived_at'] !== null) {
        refuse(422, 'Channel ' . $c['label'] . ' is archived: nothing changes in it.');
    }
    return $c;
}

/** The member gate: in the channel (a follower of a public one, a member of a private one, one of a DM's people). */
function require_channel_member(array $c): void
{
    require_login();
    if (!$c['i_am_member']) {
        refuse(403, 'You are not in ' . $c['label'] . '.');
    }
}

/** The owner gate: a space owner (sp_is_space_owner — the admin too), or a holder of channel.manage. A DM has no owner. */
function require_channel_owner(array $c): void
{
    require_login();
    if (!channel_owner($c)) {
        refuse(403, 'You may not manage ' . $c['label'] . '.');
    }
}

function channel_owner(array $c): bool
{
    return !empty($c['i_own_space']) || has_right('channel.manage') || is_sp_admin();
}

/** A row of the trail about a channel: channel_id and space_id on every one; message_id when the row is about a message. */
function channel_log(PDO $pdo, string $action, array $c, array $opts = [], ?string $entityType = 'channel', int|string|null $entityId = null): void
{
    log_activity($pdo, $action, $entityType, $entityId ?? ($entityType === 'channel' ? $c['channel_id'] : null), ['channel_id' => $c['channel_id'], 'space_id' => $c['space_id']] + $opts);
}

/** The notice banners a channel screen lands with. */
function channel_notices(array $c): array
{
    $n = $c['label'];
    return ['saved' => ['success', 'Saved ' . $n . '.'], 'created' => ['success', 'Made ' . $n . '.'], 'archived' => ['warning', $n . ' is archived: readable, nothing more is posted.'], 'restored' => ['success', $n . ' is back.'],
            'joined' => ['success', 'You are in ' . $n . '.'], 'left' => ['success', 'You left ' . $n . '.'], 'notify' => ['success', 'Saved how ' . $n . ' tells you.'], 'retention' => ['success', 'Saved the retention of ' . $n . '.'],
            'member' => ['success', 'Saved who is in ' . $n . '.'], 'removed' => ['success', 'Removed from ' . $n . '.'], 'pinned' => ['success', 'Pinned.'], 'unpinned' => ['success', 'Unpinned.'], 'bookmark' => ['success', 'Saved the bookmark.'],
            'posted' => ['success', 'Posted.'], 'scheduled' => ['success', 'Scheduled.'], 'deleted' => ['success', 'Deleted.'], 'edited' => ['success', 'Edited.'], 'replied' => ['success', 'Replied.'], 'announced' => ['success', 'Announced.']];
}

/** The path a channel is read at: /dm/{id} for a conversation, /channels/{id} for a space channel. */
function channel_path(array $c): string
{
    return (in_array($c['kind'], ['dm', 'group_dm'], true) ? '/dm/' : '/channels/') . $c['channel_id'];
}
