<?php
declare(strict_types=1);
/** Action `channel_member_add` (log `channel.member_add`): a member of the channel adds a person or an agent (a private channel's member, a public one's follower); the guard says "Join the space first". */
require_once dirname(__DIR__, 3) . '/app/features/channels/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$c = channel_from_request($pdo);
require_channel_member($c);
if ($c['kind'] === 'dm') { refuse(422, 'A direct message has its two people (start a group message instead).'); }
$errors = [];
$memberId = sp_ref($pdo, 'member', null, "SELECT 1 FROM mcp_members WHERE member_id = :id AND status = 'active' AND capability IS NOT NULL AND NOT is_guest", 'a member or an agent (a guest by channel_guest_add)', $errors, false);
if ($errors !== []) { sp_refuse_fields($errors); }
$added = sp_guard($pdo, static function () use ($pdo, $c, $memberId, $me): bool {
    $pdo->beginTransaction();
    $a = add_channel_member($pdo, $c['channel_id'], $memberId, $me);
    if ($a) { channel_log($pdo, 'channel.member_add', $c, ['after' => ['member_id' => $memberId]]); }
    $pdo->commit();
    return $a;
});
$name = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $memberId]);
sp_done($added ? 'Added ' . $name . ' to ' . $c['label'] : $name . ' was in ' . $c['label'] . ' already', $c['channel_id'], sp_land(return_path('/channels/' . $c['channel_id'] . '/members'), 'member'), 'channelChanged', ['member_id' => $memberId]);
