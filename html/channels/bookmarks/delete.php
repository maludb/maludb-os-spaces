<?php
declare(strict_types=1);
/** Action `bookmark_delete` (log `channel.bookmark_delete`): a member of its channel. */
require_once dirname(__DIR__, 3) . '/app/features/channels/handler.php';
sp_handler_begin();
$pdo = db();
$bid = request_integer('bookmark') ?? refuse(422, 'Say which bookmark.');
$b = $pdo->prepare('SELECT bookmark_id, channel_id, title FROM mcp_channel_bookmarks WHERE bookmark_id = :b');
$b->execute(['b' => $bid]);
$row = $b->fetch() ?: refuse(404, 'Bookmark not found.');
$c = find_channel($pdo, (int) $row['channel_id']) ?? refuse(404, 'Channel not found.');
require_channel_member($c);
sp_guard($pdo, static function () use ($pdo, $c, $bid, $row): void {
    $pdo->beginTransaction();
    delete_bookmark($pdo, $bid);
    channel_log($pdo, 'channel.bookmark_delete', $c, ['after' => ['bookmark_id' => $bid, 'title' => $row['title']]]);
    $pdo->commit();
});
sp_done('Removed the bookmark', $bid, sp_land(return_path('/channels/' . $c['channel_id'] . '/pins'), 'unpinned'), 'channelChanged');
