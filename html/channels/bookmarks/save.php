<?php
declare(strict_types=1);
/** Action `bookmark_save` (log `channel.bookmark_save`): a member; title, url or page, emoji; `bookmark` to change one. */
require_once dirname(__DIR__, 3) . '/app/features/channels/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$c = channel_from_request($pdo);
require_channel_member($c);
$bid = request_integer('bookmark');
$errors = [];
$title = mb_substr(trim((string) (req_val('title') ?? '')), 0, 100);
if ($title === '') { $errors['title'] = 'Give the bookmark a title.'; }
$url = trim((string) (req_val('url') ?? ''));
$pid = is_uuid($_POST['page'] ?? ($_GET['page'] ?? null)) ? (string) ($_POST['page'] ?? $_GET['page']) : null;
if (($url === '') === ($pid === null)) { $errors['url'] = 'A bookmark is a link or a page.'; }
if ($url !== '' && !preg_match('#^https?://#i', $url)) { $errors['url'] = 'A link starts with http:// or https://.'; }
if ($pid !== null && !db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM mcp_pages WHERE page_id = CAST(:p AS uuid))', ['p' => $pid])) { $errors['page'] = 'That page is not one you may see.'; }
$emoji = mb_substr(trim((string) (req_val('emoji') ?? '')), 0, 16);
if ($errors !== []) { sp_refuse_fields($errors); }
$f = ['title' => $title, 'url' => $url === '' ? null : $url, 'page_id' => $pid, 'emoji' => $emoji === '' ? null : $emoji];
$bid = sp_guard($pdo, static function () use ($pdo, $c, $bid, $f, $me): int {
    $pdo->beginTransaction();
    $id = save_bookmark($pdo, $bid, $c['channel_id'], $f, $me);
    channel_log($pdo, 'channel.bookmark_save', $c, ['after' => ['bookmark_id' => $id, 'title' => $f['title'], 'url' => $f['url'], 'page_id' => $f['page_id']]]);
    $pdo->commit();
    return $id;
});
sp_done('Saved the bookmark ' . $title, $bid, sp_land(return_path('/channels/' . $c['channel_id'] . '/pins'), 'bookmark'), 'channelChanged', ['bookmark_id' => $bid]);
